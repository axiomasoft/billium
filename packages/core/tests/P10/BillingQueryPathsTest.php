<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P10;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\EventPublisher;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Data\Obligations\ObligationDuePolicy;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Platform\Contracts\Feed\EventFeed;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Internal\Outbox\OutboxRelay;
use Billium\Testkit\Fixtures\DurableJournal;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use stdClass;
use Throwable;

#[Group('pg')]
#[Group('pg-serial')]
#[Group('P10.4')]
#[Group('D71')]
#[Group('D72')]
final class BillingQueryPathsTest extends ExecutionTestCase
{
    private const string POLICY_DIGEST = 'sha256:1111111111111111111111111111111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['billium.feed.signing_key' => str_repeat('k', 32)]);
        $this->application()->forgetInstance(BilliumOptions::class);
        $this->application()->instance(EventPublisher::class, new class implements EventPublisher
        {
            /** @param array<string, mixed> $message */
            public function publish(string $destination, array $message): void {}
        });
    }

    public function test_ledger_seller_and_due_query_plans_use_expected_indexes_with_bounded_limits(): void
    {
        $asOf = new DateTimeImmutable('2026-11-22T00:00:00Z');
        $this->seedObligationsForSeller('seller-a', 'acct-a', 20, 3, $asOf);
        $this->seedObligationsForSeller('seller-b', 'acct-b', 8, 2, $asOf);
        $this->seedObligationsForSeller('seller-c', 'acct-c', 8, 2, $asOf);
        $this->switchCaller('seller-a');
        $this->owner()->statement('ANALYZE public.billium_obligation');

        $db = $this->runtimeConnection();
        $db->enableQueryLog();
        $queries = $this->application()->make(LedgerQueries::class);
        $page1 = $queries->dueObligations('acct-a', $asOf, limit: 5);
        self::assertCount(5, $page1['obligations']);
        self::assertIsString($page1['nextCursor'] ?? null);

        $page2 = $queries->dueObligations('acct-a', $asOf, limit: 5, cursor: $page1['nextCursor']);
        self::assertCount(5, $page2['obligations']);
        self::assertIsString($page2['nextCursor'] ?? null);
        $log = $db->getQueryLog();
        $db->disableQueryLog();

        $allRefs = array_map(
            static fn (array $item): string => self::text($item['obligationRef']),
            [...$page1['obligations'], ...$page2['obligations']],
        );
        self::assertCount(10, array_unique($allRefs));
        foreach ($allRefs as $ref) {
            self::assertStringStartsWith('debt-seller-a-', $ref);
        }

        $dueQuery = array_find(
            $log,
            static fn (array $entry): bool => str_contains(self::text($entry['query']), '"billium_obligation"')
                && str_contains(self::text($entry['query']), 'order by "due_at" asc, "id" asc limit 6'),
        );
        self::assertNotNull($dueQuery, 'Expected bounded LIMIT 6 dueObligations query in SQL log');
        /** @var list<mixed> $dueBindings */
        $dueBindings = $dueQuery['bindings'];
        $this->assertIndexedPlan(self::text($dueQuery['query']), $dueBindings, ['billium_obligation_account_due_idx'], forbidSort: true);

        $this->assertIndexedPlan(
            "SELECT id, obligation_ref, due_at FROM public.billium_obligation WHERE seller_ref = ? AND environment = ? AND status = 'open' AND due_at IS NOT NULL AND due_at <= ? ORDER BY due_at, id LIMIT 5",
            ['seller-a', 'test', $asOf->format('Y-m-d\TH:i:s.uP')],
            ['billium_obligation_due_idx'],
            forbidSort: true,
        );
        $this->assertIndexedPlan(
            'SELECT id, payment_ref, captured_minor FROM public.billium_payment WHERE installation_ref = ? AND seller_ref = ? AND environment = ? AND payment_ref = ? LIMIT 1',
            [self::INSTALLATION, 'seller-a', 'test', 'pay-a-1'],
            ['billium_payment_ref_idx'],
        );
        $this->assertIndexedPlan(
            'SELECT id, amount_minor, receipt_ref FROM public.billium_allocation WHERE seller_ref = ? AND environment = ? AND obligation_id >= ? ORDER BY obligation_id, id LIMIT 50',
            ['seller-a', 'test', 1],
            ['billium_allocation_obligation_idx'],
            forbidSort: true,
        );
    }

    public function test_idempotency_outbox_and_inbox_query_plans_use_expected_indexes_and_bounded_batches(): void
    {
        $owner = $this->owner();
        foreach (['seller-a', 'seller-b', 'seller-c'] as $seller) {
            $feedScope = (new FeedScope(self::INSTALLATION, $seller, 'test', '1'))->key();
            $finScope = json_encode(new FinancialScope(self::INSTALLATION, $seller, 'test', 'source-a', 'remote-billium'), JSON_THROW_ON_ERROR);
            $owner->insert(<<<'SQL'
                INSERT INTO public.billium_idempotency_receipt
                    (environment, seller_ref, command_kind, command_key, digest, outcome, result, completed_at, financial_scope)
                SELECT 'test', ?, 'payment.execute.v1', ? || '-cmd-' || n, 'sha256:' || repeat('a', 64), 'accepted',
                       jsonb_build_object('class', 'execution.operation', 'body', jsonb_build_object('receiptRef', ? || '-rcpt-' || n)),
                       now(), ?::jsonb
                FROM generate_series(1, 20) n
                SQL, [$seller, $seller, $seller, $finScope]);
            $owner->insert(<<<'SQL'
                INSERT INTO public.billium_outbox_event
                    (event_id, seller_ref, environment, type, schema_version, aggregate_ref, aggregate_revision,
                     occurred_at, digest, payload, contract_revision, owner_module_id, source_scope)
                SELECT ? || '-evt-' || n, ?, 'test', 'obligation.created.v1', 'billium.event.v1',
                       'obligation-' || ? || '-' || n, 1, now(), 'sha256:' || repeat('b', 64), '{}'::jsonb,
                       'events-2026-10-v1', 'ledger', ?
                FROM generate_series(1, 20) n
                SQL, [$seller, $seller, $seller, $feedScope]);
            $owner->insert(<<<'SQL'
                INSERT INTO public.billium_outbox_delivery
                    (event_id, destination_ref, status, available_at, delivered_at)
                SELECT ? || '-evt-' || n, 'default',
                       CASE WHEN n <= 12 THEN 'pending' ELSE 'delivered' END,
                       CASE WHEN n <= 4 THEN now() - interval '5 seconds' WHEN n <= 12 THEN now() + interval '1 hour' ELSE now() - interval '1 minute' END,
                       CASE WHEN n > 12 THEN now() ELSE NULL END
                FROM generate_series(1, 20) n
                SQL, [$seller]);
        }
        $owner->statement('ANALYZE public.billium_idempotency_receipt, public.billium_outbox_event, public.billium_outbox_delivery, public.billium_inbox_message');

        $relay = $this->application()->make(OutboxRelay::class);
        $this->assertRejectedWith(LogicException::class, static fn () => $relay->claim(0));
        $this->assertRejectedWith(LogicException::class, static fn () => $relay->claim(1001));

        $db = $this->runtimeConnection();
        $db->enableQueryLog();
        self::assertCount(5, $relay->claim(5));

        $feed = $this->application()->make(EventFeed::class);
        $selection = $feed->openFeed(new FeedScope(self::INSTALLATION, 'seller-a', 'test', '1'), 'events-2026-10-v1', ['billium.event.v1'], ['obligation.created.v1']);
        $page = $feed->read($selection, null, 8);
        self::assertCount(8, $page->events);
        self::assertTrue($page->hasMore);
        $log = $db->getQueryLog();
        $db->disableQueryLog();

        $claimSql = array_find($log, static fn (array $entry): bool => str_contains(self::text($entry['query']), 'SKIP LOCKED'));
        self::assertNotNull($claimSql);
        self::assertStringContainsString('LIMIT 5', self::text($claimSql['query']));
        $this->assertIndexedPlan(self::text($claimSql['query']), [], ['billium_outbox_delivery_due'], forbidSort: true);

        $feedSql = array_find($log, static fn (array $entry): bool => str_contains(self::text($entry['query']), 'ORDER BY tx_id,id LIMIT'));
        self::assertNotNull($feedSql);
        /** @var list<mixed> $feedBindings */
        $feedBindings = $feedSql['bindings'];
        $this->assertIndexedPlan(
            self::text($feedSql['query']),
            $feedBindings,
            ['billium_outbox_event_scope_cursor', 'billium_outbox_event_seller_cursor'],
            forbidSort: true,
        );

        $finScopeA = json_encode(new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium'), JSON_THROW_ON_ERROR);
        $this->assertIndexedPlan(
            'SELECT id, outcome, digest FROM public.billium_idempotency_receipt WHERE environment = ? AND seller_ref = ? AND command_kind = ? AND command_key = ? LIMIT 1',
            ['test', 'seller-a', 'payment.execute.v1', 'seller-a-cmd-1'],
            ['billium_idempotency_receipt_scope_key'],
        );
        $this->assertIndexedPlan(
            "SELECT id, result FROM public.billium_idempotency_receipt WHERE financial_scope = ?::jsonb AND outcome = 'accepted' AND result->>'class' = 'execution.operation' AND result#>>'{body,receiptRef}' = ? ORDER BY created_at, id LIMIT 1",
            [$finScopeA, 'seller-a-rcpt-1'],
            ['billium_idempotency_opaque_receipt_scope'],
            forbidSort: true,
        );
        $this->assertIndexedPlan(
            "SELECT id, event_id FROM public.billium_outbox_delivery WHERE status <> 'delivered' AND event_id = ? LIMIT 50",
            ['seller-a-evt-1'],
            ['billium_outbox_delivery_open_event'],
        );
        $this->assertIndexedPlan(
            "SELECT id, operation_revision FROM public.billium_inbox_message WHERE seller_ref = ? AND environment = ? AND channel = 'observation' AND source_authority = 'remote-billium' AND operation_ref = ? AND payload->>'installationRef' = ? AND status IN ('ready', 'parked_gap', 'parked_dependency') ORDER BY operation_revision DESC, id DESC LIMIT 1",
            ['seller-a', 'test', 'op-a-1', self::INSTALLATION],
            ['billium_inbox_message_pending_op_idx'],
            forbidSort: true,
        );
    }

    public function test_execution_and_collection_due_query_plans_use_expected_indexes(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            $scope = Fixture::scope();
            Fixture::seedMerchant($app);
            Fixture::host($app, $path);
            $app->make(RootPaymentCommands::class)->admit(
                $scope,
                Fixture::wire(['money' => ['amount' => '25.00', 'currency' => 'RUB']]),
            )->orThrow();

            // Seed compact child operations & attempts so planner statistics reflect multi-row selectivity.
            $this->owner()->transaction(function (): void {
                $this->owner()->unprepared(<<<'SQL'
                    INSERT INTO public.billium_execution_child_identity
                        (installation_ref, seller_ref, environment, intent_owner_namespace, source_context, business_intent_ref,
                         operation_kind, operation_ref, root_operation_ref, semantic_digest, canonicalization_version, binding,
                         requested_minor, currency, currency_exponent, receipt_ref)
                    SELECT o.installation_ref, o.seller_ref, o.environment, o.intent_owner_namespace, o.source_context,
                           'plan-intent-' || n, 'capture', 'plan-op-' || n, o.root_operation_ref, o.economic_digest,
                           o.binding->>'canonicalizationVersion', o.binding, 100, o.currency, o.currency_exponent, 'plan-rcpt-' || n
                    FROM public.billium_execution_operation o CROSS JOIN generate_series(1, 40) n WHERE o.operation_ref = 'root-a';
                    INSERT INTO public.billium_execution_operation
                        (installation_ref, seller_ref, environment, operation_ref, root_operation_ref, kind, source_context, binding,
                         binding_digest, business_intent_ref, intent_owner_namespace, merchant_revision_ref, merchant_account_ref,
                         driver_id, driver_contract_version, capability_digest, requested_minor, currency, currency_exponent, state,
                         revision, economic_digest, created_at)
                    SELECT o.installation_ref, o.seller_ref, o.environment, 'plan-op-' || n, o.root_operation_ref, 'capture',
                           o.source_context, o.binding, o.binding_digest, o.business_intent_ref, o.intent_owner_namespace,
                           o.merchant_revision_ref, o.merchant_account_ref, o.driver_id, o.driver_contract_version,
                           o.capability_digest, 100, o.currency, o.currency_exponent, 'admitted', 0, o.economic_digest, o.created_at
                    FROM public.billium_execution_operation o CROSS JOIN generate_series(1, 40) n WHERE o.operation_ref = 'root-a';
                    INSERT INTO public.billium_execution_attempt
                        (installation_ref, seller_ref, environment, attempt_ref, operation_ref, provider_reference, provider_id,
                         merchant_account_ref, request_digest)
                    SELECT o.installation_ref, o.seller_ref, o.environment, 'plan-att-' || o.operation_ref, o.operation_ref,
                           'prov-ref-' || o.operation_ref, r.provider_id, o.merchant_account_ref, o.economic_digest
                    FROM public.billium_execution_operation o
                    JOIN public.billium_execution_merchant_revision r
                      ON r.installation_ref = o.installation_ref AND r.seller_ref = o.seller_ref
                     AND r.environment = o.environment AND r.revision_ref = o.merchant_revision_ref
                    WHERE o.operation_ref LIKE 'plan-op-%';
                    SQL);
            });

            $providerId = self::text($this->value("SELECT provider_id FROM public.billium_execution_attempt WHERE operation_ref = 'root-a'"));
            $this->owner()->statement(
                'ANALYZE public.billium_execution_dispatch_work, public.billium_execution_provider_work, '
                .'public.billium_execution_attempt, public.billium_execution_business_binding, '
                .'public.billium_execution_operation, public.billium_collection_work',
            );

            $this->assertIndexedPlan(
                'SELECT id, work_ref FROM public.billium_execution_dispatch_work WHERE installation_ref = ? AND seller_ref = ? AND environment = ? AND state = ? AND due_at <= now() ORDER BY due_at, id LIMIT 50',
                [self::INSTALLATION, 'seller-a', 'test', 'due'],
                ['billium_execution_dispatch_work_lookup'],
                forbidSort: true,
            );
            $this->assertIndexedPlan(
                'SELECT id, work_ref FROM public.billium_execution_provider_work WHERE installation_ref = ? AND seller_ref = ? AND environment = ? AND state = ? AND due_at <= now() ORDER BY due_at, id LIMIT 50',
                [self::INSTALLATION, 'seller-a', 'test', 'due'],
                ['billium_execution_provider_work_lookup'],
                forbidSort: true,
            );
            $this->assertIndexedPlan(
                'SELECT id, attempt_ref FROM public.billium_execution_attempt WHERE installation_ref = ? AND environment = ? AND provider_id = ? AND merchant_account_ref = ? AND provider_reference = ? LIMIT 1',
                [self::INSTALLATION, 'test', $providerId, 'account-a', 'prov-ref-plan-op-40'],
                ['billium_execution_provider_reference'],
            );
            $digest = self::text($this->value("SELECT business_identity_digest FROM public.billium_execution_business_binding WHERE business_intent_ref = 'intent-a' LIMIT 1"));
            $this->assertIndexedPlan(
                'SELECT id, operation_ref FROM public.billium_execution_business_binding WHERE business_identity_digest = ? LIMIT 1',
                [$digest],
                ['billium_execution_business_identity_digest_unique'],
            );
            $this->assertIndexedPlan(
                'SELECT id, operation_ref, state FROM public.billium_execution_operation WHERE installation_ref = ? AND seller_ref = ? AND environment = ? AND intent_owner_namespace = ? AND root_operation_ref = ? LIMIT 50',
                [self::INSTALLATION, 'seller-a', 'test', 'remote-billium', 'root-a'],
                ['billium_execution_operation_root_idx'],
            );
            $this->assertIndexedPlan(
                'SELECT step_id, next_attempt_at FROM public.billium_collection_work WHERE seller_ref = ? AND status = ? AND next_attempt_at IS NOT NULL AND next_attempt_at <= now() ORDER BY next_attempt_at, step_id LIMIT 50',
                ['seller-a', 'due'],
                ['billium_collection_work_due_idx'],
                forbidSort: true,
            );
        } finally {
            self::cleanup($path);
        }
    }

    public function test_cross_seller_read_leakage_is_blocked_across_ledger_idempotency_outbox_and_execution(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        $asOf = new DateTimeImmutable('2026-11-22T00:00:00Z');
        try {
            $this->seedObligationsForSeller('seller-a', 'acct-a', 2, 0, $asOf);
            $this->seedObligationsForSeller('seller-b', 'acct-b', 2, 0, $asOf);

            foreach (['seller-a', 'seller-b'] as $seller) {
                $feedScope = (new FeedScope(self::INSTALLATION, $seller, 'test', '1'))->key();
                $finScope = json_encode(new FinancialScope(self::INSTALLATION, $seller, 'test', 'source-a', 'remote-billium'), JSON_THROW_ON_ERROR);
                $this->owner()->insert(<<<'SQL'
                    INSERT INTO public.billium_idempotency_receipt
                        (environment, seller_ref, command_kind, command_key, digest, outcome, result, completed_at, financial_scope)
                    VALUES ('test', ?, 'payment.execute.v1', ? || '-iso-cmd', 'sha256:' || repeat('c', 64), 'accepted',
                            jsonb_build_object('class', 'execution.operation', 'body', jsonb_build_object('receiptRef', ? || '-iso-rcpt')),
                            now(), ?::jsonb)
                    SQL, [$seller, $seller, $seller, $finScope]);
                $this->owner()->insert(<<<'SQL'
                    INSERT INTO public.billium_outbox_event
                        (event_id, seller_ref, environment, type, schema_version, aggregate_ref, aggregate_revision,
                         occurred_at, digest, payload, contract_revision, owner_module_id, source_scope)
                    VALUES (? || '-iso-evt', ?, 'test', 'obligation.created.v1', 'billium.event.v1',
                            'obligation-' || ?, 1, now(), 'sha256:' || repeat('d', 64), '{}'::jsonb,
                            'events-2026-10-v1', 'ledger', ?)
                    SQL, [$seller, $seller, $seller, $feedScope]);
            }

            $this->switchCaller('seller-a');
            $scopeA = Fixture::scope();
            $scopeB = new ExecutionScope(self::INSTALLATION, 'seller-b', 'test', 'source-a');
            Fixture::seedMerchant($app);
            Fixture::host($app, $path);
            $app->make(RootPaymentCommands::class)->admit(
                $scopeA,
                Fixture::wire(['money' => ['amount' => '10.00', 'currency' => 'RUB']]),
            )->orThrow();

            $ledgerQueries = $app->make(LedgerQueries::class);
            $this->assertProblem(ProblemCode::ReferenceUnknown, static fn () => $ledgerQueries->obligation('debt-seller-b-due-1'));
            $this->assertProblem(ProblemCode::ReferenceUnknown, static fn () => $ledgerQueries->dueObligations('acct-b', $asOf));

            $receiptQueries = $app->make(IdempotencyReceiptQueries::class);
            $finScopeA = new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium');
            $finScopeB = new FinancialScope(self::INSTALLATION, 'seller-b', 'test', 'source-a', 'remote-billium');
            $this->assertProblem(ProblemCode::AuthDenied, static fn () => $receiptQueries->byCommandKey($finScopeB, 'payment.execute.v1', 'seller-b-iso-cmd'));
            self::assertNull($receiptQueries->byCommandKey($finScopeA, 'payment.execute.v1', 'seller-b-iso-cmd'));

            $feed = $app->make(EventFeed::class);
            $selectionA = $feed->openFeed(new FeedScope(self::INSTALLATION, 'seller-a', 'test', '1'), 'events-2026-10-v1', ['billium.event.v1'], ['obligation.created.v1']);
            $eventsA = $feed->read($selectionA, null, 50)->events;
            self::assertNotEmpty($eventsA);
            foreach ($eventsA as $event) {
                self::assertSame('seller-a', $event['sellerRef']);
                self::assertStringNotContainsString('seller-b', self::text($event['eventId']));
            }

            $executionQueries = $app->make(ExecutionQueries::class);
            $this->assertProblem(ProblemCode::AuthDenied, static fn () => $executionQueries->find($scopeB, 'root-a'));

            $this->switchCaller('seller-b');
            $this->assertProblem(ProblemCode::ReferenceUnknown, static fn () => $ledgerQueries->obligation('debt-seller-a-due-1'));
            $this->assertProblem(ProblemCode::ReferenceUnknown, static fn () => $executionQueries->find($scopeB, 'root-a'));
        } finally {
            self::cleanup($path);
        }
    }

    public function test_dispatch_does_not_update_installation_state(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            $scope = Fixture::scope();
            Fixture::seedMerchant($app);
            Fixture::host($app, $path);
            $app->make(RootPaymentCommands::class)->admit(
                $scope,
                Fixture::wire(['money' => ['amount' => '42.00', 'currency' => 'RUB']]),
            )->orThrow();

            $before = $this->owner()->selectOne(
                'SELECT xmin::text AS row_xmin, updated_at::text AS updated_at FROM public.billium_installation_state WHERE installation_ref = ?',
                [self::INSTALLATION],
            );
            self::assertInstanceOf(stdClass::class, $before);

            $db = $this->runtimeConnection();
            $db->enableQueryLog();
            $attemptRef = PaymentIdentity::reference($scope, 'root-a', 'attempt');
            $outcome = $app->make(AttemptWorker::class)->dispatch($scope, $attemptRef, 'p10-4-dispatch-worker', new DateTimeImmutable('+1 minute'));
            $log = $db->getQueryLog();
            $db->disableQueryLog();

            self::assertSame('pending', $outcome->body['status']);
            self::assertNotEmpty((new DurableJournal($path))->records());
            self::assertSame(1, $this->rows('billium_execution_provider_work', "state='due'"));

            foreach ($log as $entry) {
                $sql = self::text($entry['query']);
                self::assertDoesNotMatchRegularExpression(
                    '/\b(?:UPDATE|INSERT|DELETE)\b.*\bbillium_installation_state\b/is',
                    $sql,
                    'Dispatch must never mutate billium_installation_state: '.$sql,
                );
            }

            $after = $this->owner()->selectOne(
                'SELECT xmin::text AS row_xmin, updated_at::text AS updated_at FROM public.billium_installation_state WHERE installation_ref = ?',
                [self::INSTALLATION],
            );
            self::assertInstanceOf(stdClass::class, $after);
            self::assertSame($before->row_xmin, $after->row_xmin, 'billium_installation_state tuple xmin changed during dispatch');
            self::assertSame($before->updated_at, $after->updated_at);
        } finally {
            self::cleanup($path);
        }
    }

    private function seedObligationsForSeller(string $seller, string $accountRef, int $dueCount, int $futureCount, DateTimeImmutable $asOf): void
    {
        $this->switchCaller($seller);
        $ledger = $this->application()->make(LedgerParticipants::class);
        $account = new BillingAccountScope($seller, 'test', $accountRef);
        $spec = $this->spec(
            seller: $seller,
            environment: 'test',
            financialScope: new FinancialScope(self::INSTALLATION, $seller, 'test', 'source-a', 'remote-billium'),
        );

        for ($i = 1; $i <= $dueCount; $i++) {
            $ref = sprintf('debt-%s-due-%d', $seller, $i);
            $policy = new ObligationDuePolicy($asOf->modify(sprintf('-%d minutes', ($dueCount - $i + 1) * 5)), 'due.v1', 'policy-'.$seller, self::POLICY_DIGEST);
            $this->runner()->run($spec, static fn (TransactionContext $ctx) => $ledger->recordObligation(
                $ctx,
                new RecordObligation('rec-'.$ref, $account, $ref, 'basis-'.$ref, 'buyer-'.$seller, 'component-a', new Money(1000 * $i, Currency::of('RUB')), self::POLICY_DIGEST, duePolicy: $policy),
            ))->orThrow();
        }
        for ($i = 1; $i <= $futureCount; $i++) {
            $ref = sprintf('debt-%s-future-%d', $seller, $i);
            $policy = new ObligationDuePolicy($asOf->modify(sprintf('+%d hours', $i)), 'due.v1', 'policy-'.$seller, self::POLICY_DIGEST);
            $this->runner()->run($spec, static fn (TransactionContext $ctx) => $ledger->recordObligation(
                $ctx,
                new RecordObligation('rec-'.$ref, $account, $ref, 'basis-'.$ref, 'buyer-'.$seller, 'component-a', new Money(500 * $i, Currency::of('RUB')), self::POLICY_DIGEST, duePolicy: $policy),
            ))->orThrow();
        }
    }

    private function switchCaller(string $seller): void
    {
        $current = $this->callers->current();
        $this->callers->set(new Caller($seller, $current->environment, $current->installationRef, $current->actorRef, $current->sourceAuthorities, $current->intentOwnerNamespaces));
        SimulatedFinancialAuthorityEvidence::admitCurrent($this->application());
    }

    private function runtimeConnection(): Connection
    {
        return $this->application()->make(DatabaseManager::class)->connection();
    }

    /**
     * @param  list<mixed>  $bindings
     * @param  list<string>  $expectedIndexes
     */
    private function assertIndexedPlan(string $sql, array $bindings, array $expectedIndexes, bool $forbidSort = false): void
    {
        $owner = $this->owner();
        $owner->beginTransaction();
        try {
            $owner->statement('SET LOCAL enable_seqscan = off');
            $row = $owner->selectOne('EXPLAIN (FORMAT JSON) '.$sql, $bindings);
        } finally {
            $owner->rollBack();
        }
        self::assertInstanceOf(stdClass::class, $row);
        $decoded = json_decode(self::text($row->{'QUERY PLAN'}), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded[0]['Plan'] ?? null);

        $indexes = [];
        $nodeTypes = [];
        $seqScans = [];
        $walk = static function (array $node) use (&$walk, &$indexes, &$nodeTypes, &$seqScans): void {
            if (is_string($node['Node Type'] ?? null)) {
                $nodeTypes[] = $node['Node Type'];
                if ($node['Node Type'] === 'Seq Scan') {
                    $seqScans[] = is_string($node['Relation Name'] ?? null) ? $node['Relation Name'] : 'unknown';
                }
            }
            if (is_string($node['Index Name'] ?? null)) {
                $indexes[] = $node['Index Name'];
            }
            foreach (is_array($node['Plans'] ?? null) ? $node['Plans'] : [] as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($decoded[0]['Plan']);

        self::assertTrue(
            array_any($expectedIndexes, static fn (string $idx): bool => in_array($idx, $indexes, true)),
            sprintf('Expected one of [%s], got [%s] for SQL: %s', implode(', ', $expectedIndexes), implode(', ', $indexes), $sql),
        );
        self::assertSame([], $seqScans, 'Unexpected Seq Scan for SQL: '.$sql);
        if ($forbidSort) {
            self::assertNotContains('Sort', $nodeTypes, 'Unexpected Sort node for SQL: '.$sql);
        }
    }

    /** @param Closure(): mixed $call */
    private function assertProblem(ProblemCode $expected, Closure $call): void
    {
        try {
            $call();
            self::fail('Expected ProblemException '.$expected->value);
        } catch (ProblemException $error) {
            self::assertSame($expected, $error->problem);
        }
    }

    /**
     * @param  class-string<Throwable>  $expected
     * @param  Closure(): mixed  $call
     */
    private function assertRejectedWith(string $expected, Closure $call): void
    {
        try {
            $call();
            self::fail('Expected exception '.$expected);
        } catch (Throwable $error) {
            self::assertInstanceOf($expected, $error);
        }
    }

    private static function cleanup(string $path): void
    {
        UnknownResultTest::clean($path);
        foreach ([$path.'.origins', $path.'.delivery'] as $extra) {
            if (is_file($extra)) {
                unlink($extra);
            }
        }
    }
}
