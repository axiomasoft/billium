<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AuthorizationCommands;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RepairCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\OriginDecision;
use Billium\Execution\Data\RecoveryAction;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Data\EventContracts\RegisteredPublicEvent;
use Billium\Platform\Data\Outbox\OutboxEvent;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Fixtures\DurableJournal;
use DateTimeImmutable;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
#[Group('PF-11')]
final class LinkedCorrectionApprovalTest extends ExecutionTestCase
{
    private const string STATEMENT = 'merchant-a-root-a-gross-refunds';

    private function payment(Application $app, string $path): void
    {
        AuthorizationLifecycleTest::authorize($app, $path);
        foreach (['capture-twenty' => 20, 'capture-forty' => 40] as $operation => $amount) {
            $app->make(AuthorizationCommands::class)->admit(Fixture::scope(), AuthorizationLifecycleTest::child($operation, $amount))->orThrow();
            AuthorizationLifecycleTest::dispatchAndReduce($app, $operation);
        }
        InventoryReconciliationTest::grant($this->callers);
    }

    /** Leave the already executed refund's original provider result in local custody. */
    private function refund(Application $app, string $operation, int $minor, string $statement, string $root = 'root-a', string $category = 'reconciliation_correction'): string
    {
        $app->make(RefundCommands::class)->admit(Fixture::scope(), Refunds::refund($operation, $minor, crc32($operation),
            ['reasonCode' => $statement, 'category' => $category, 'originalOperationRef' => $root]))->orThrow();
        AuthorizationLifecycleTest::dispatch($app, $operation);

        return self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint=? AND state='due'", [$operation]));
    }

    private function statement(Application $app, string $path, bool $includeOtherStatement = false): string
    {
        $refunded = 0;
        foreach (new DurableJournal($path)->records() as $row) {
            if ($row instanceof ProviderRecord && $row->kind === EffectKind::Refund && $row->status === 'succeeded' && $row->request->binding->rootOperationRef === 'root-a') {
                $refunded += $row->effectAmounts['refunded'];
            }
        }
        self::assertSame(20, $refunded);
        InventoryReconciliationTest::enableInventory($app, $path);
        $lines = [DisputeSettlementTest::line('balance_comparison', $refunded, self::STATEMENT)];
        if ($includeOtherStatement) {
            $lines[] = DisputeSettlementTest::line('balance_comparison', $refunded, 'other-gross-refunds');
        }
        InventoryReconciliationTest::page($path, $lines);
        self::assertSame('complete', InventoryReconciliationTest::scan($app, 'statement', new DateTimeImmutable('-10 minutes'), new DateTimeImmutable));

        return self::text($this->value("SELECT event_ref FROM billium_execution_recon_review WHERE code='balance_mismatch' AND status='open' AND evidence_ref=?", [self::STATEMENT]));
    }

    private function action(string $review, string $evidence, ?string $actor = null, string $reason = 'Confirm delayed application of the original refund'): RecoveryAction
    {
        $actor ??= $this->callers->current()->actorRef;
        $scope = Fixture::scope();
        $refs = [self::STATEMENT, $evidence];
        $digest = PaymentIdentity::digest(['schema' => 'billium.execution.repair.v1', 'scope' => get_object_vars($scope), 'source' => 'remote-billium',
            'root' => 'root-a', 'review' => $review, 'action' => 'approve_linked_correction', 'actor' => $actor, 'reason' => $reason, 'evidence' => $refs]);

        return new RecoveryAction('correction-approval', $scope, 'approve_linked_correction', $refs, $actor, $reason, $digest, 'root-a');
    }

    /** @return array<string,mixed> */
    private function history(string $review): array
    {
        $history = [];
        foreach (['operation', 'operation_revision', 'provider_evidence', 'provider_fact', 'child_identity', 'reservation', 'capacity', 'recon_item', 'attempt'] as $table) {
            $history[$table] = $this->value("SELECT jsonb_agg(to_jsonb(t) ORDER BY id)::text FROM billium_execution_{$table} t");
        }
        $history['originalReview'] = $this->value('SELECT to_jsonb(t)::text FROM billium_execution_recon_review t WHERE event_ref=?', [$review]);

        return $history;
    }

    public function test_delayed_application_of_the_existing_refund_has_exact_case_operation_fact_and_receipt_lineage(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            $this->payment($app, $path);
            $previous = $this->refund($app, 'ordinary-seven', 7, 'returned_goods', category: 'customer_return');
            self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce($previous)->disposition);
            $work = $this->refund($app, 'linked-thirteen', 13, self::STATEMENT);
            $sent = UnknownResultTest::events($path, 'provider.call');
            self::assertSame(5, $sent);
            $review = $this->statement($app, $path);
            self::assertSame(1, $this->rows('billium_execution_recon_review', "(expected->>'refundedMinor')::int=7 AND (observed->>'minor')::int=20"));
            $result = $app->make(EvidenceReducer::class)->reduce($work);
            self::assertSame(ReductionDisposition::Applied, $result->disposition);
            $evidence = $result->evidenceRefs[0];
            $history = $this->history($review);
            $action = $this->action($review, $evidence);
            $outbox = $app->make(Outbox::class);
            $app->instance(Outbox::class, new readonly class($outbox) implements Outbox
            {
                public function __construct(private Outbox $inner) {}

                public function record(TransactionContext $context, RegisteredPublicEvent|OutboxEvent $event): int
                {
                    $this->inner->record($context, $event);
                    throw new RuntimeException('Rollback linked correction after outbox insert');
                }
            });
            try {
                $app->make(RepairCommands::class)->repair($action, $review)->orThrow();
                self::fail('Injected rollback committed');
            } catch (RuntimeException $failure) {
                self::assertSame('Rollback linked correction after outbox insert', $failure->getMessage());
            } finally {
                $app->instance(Outbox::class, $outbox);
            }
            $this->assertNoApproval();
            self::assertSame($history, $this->history($review));
            $api = $app->make(RepairCommands::class);
            $receipt = $api->repair($action, $review)->orThrow();
            $replay = $api->repair($action, $review)->orThrow();
            self::assertTrue($replay->replayed);
            self::assertSame($receipt->receiptId, $replay->receiptId);
            self::assertSame($receipt->body, $replay->body);
            HistoricalMerchantRefundTest::problem(ProblemCode::IdempotencyConflict, fn () => $api->repair($this->action($review, $evidence, reason: 'Changed intent'), $review));
            $case = self::text($this->value('SELECT case_ref FROM billium_execution_recon_review WHERE event_ref=?', [$review]));
            $event = $this->row("SELECT event_id, payload FROM billium_outbox_event WHERE type='execution.repair_recorded.v1'");
            $payload = json_decode(self::text($event['payload']), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            self::assertSame('approve_linked_correction', $payload['action']);
            self::assertSame($review, $payload['originalReviewRef']);
            self::assertSame('root-a', $payload['rootOperationRef']);
            self::assertSame(1, $this->rows('billium_execution_recon_review', "event_ref=? AND status='open' AND case_ref=? AND provenance='approve_linked_correction'", [$payload['reviewEventRef'], $case]));
            $audit = $this->row('SELECT before_digest, after_digest, correction_refs, evidence_refs, authorization_ref FROM billium_execution_repair_receipt WHERE repair_ref=?', [$receipt->body['receiptRef']]);
            self::assertSame([$payload['beforeDigest'], $payload['afterDigest']], [$audit['before_digest'], $audit['after_digest']]);
            self::assertNotSame($audit['before_digest'], $audit['after_digest']);
            self::assertSame($action->authorizationRef, $audit['authorization_ref']);
            self::assertSame([self::STATEMENT, $evidence], json_decode(self::text($audit['evidence_refs']), true, flags: JSON_THROW_ON_ERROR));
            $fact = self::text($this->value("SELECT fact_ref FROM billium_execution_provider_fact WHERE operation_ref='linked-thirteen' AND evidence_mode='cumulative_snapshot'"));
            $admission = self::text($this->value("SELECT receipt_ref FROM billium_execution_child_identity WHERE operation_ref='linked-thirteen'"));
            self::assertSame([$review, $payload['reviewEventRef'], $event['event_id'], $case, 'root-a', 'linked-thirteen', $admission, $work, $fact],
                json_decode(self::text($audit['correction_refs']), true, flags: JSON_THROW_ON_ERROR));
            self::assertSame(1, $this->rows('billium_execution_repair_receipt'));
            self::assertSame(1, $this->rows('billium_outbox_event', "type='execution.repair_recorded.v1'"));
            self::assertSame($history, $this->history($review));
            self::assertSame($sent, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            InventoryReconciliationTest::clear($path);
        }
    }

    /** @return list<array{string}> */
    public static function unrelated(): array
    {
        return [['opposite capture'], ['ordinary refund'], ['other statement'], ['foreign root'], ['later refund'], ['stale sibling basis'], ['wrong actor']];
    }

    #[DataProvider('unrelated')]
    public function test_approval_rejects_a_same_value_effect_without_the_original_financial_lineage(string $scenario): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            $this->payment($app, $path);
            $work = $this->refund($app, 'refund-twenty', 20, $scenario === 'other statement' ? 'other-gross-refunds' : self::STATEMENT,
                category: $scenario === 'ordinary refund' ? 'customer_return' : 'reconciliation_correction');
            if ($scenario === 'foreign root') {
                $origin = new OriginDecision(Fixture::scope(), 'root-b', 'business-b', 'checkout', 'remote-billium', 1, null, 'sha256:'.str_repeat('a', 64), 'terms-v1', null);
                Fixture::host($app, $path, [$origin]);
                $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire(['operationRef' => 'root-b', 'commandKey' => Fixture::key(501),
                    'source' => ['context' => 'checkout', 'intentRef' => 'business-b', 'orderRef' => 'order-a']]))->orThrow();
                AuthorizationLifecycleTest::dispatchAndReduce($app, 'root-b');
                $other = $this->refund($app, 'foreign-twenty', 20, self::STATEMENT, 'root-b');
                self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce($other)->disposition);
            }
            $review = $this->statement($app, $path, $scenario === 'other statement');
            self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce($work)->disposition);
            if ($scenario === 'later refund') {
                $later = $this->refund($app, 'later-twenty', 20, self::STATEMENT);
                self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce($later)->disposition);
                self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_refund_minor=40'));
            }
            if ($scenario === 'stale sibling basis') {
                $app->make(RefundCommands::class)->admit(Fixture::scope(), Refunds::refund('intervening-sibling', 1, 504))->orThrow();
            }
            $operation = match ($scenario) {
                'opposite capture' => 'capture-twenty',
                'foreign root' => 'foreign-twenty',
                'later refund' => 'later-twenty',
                default => 'refund-twenty',
            };
            $evidence = self::text($this->value("SELECT evidence_ref FROM billium_execution_provider_work WHERE operation_hint=? AND state='processed'", [$operation]));
            $history = $this->history($review);
            $calls = UnknownResultTest::events($path, 'provider.call');
            $action = $this->action($review, $evidence, $scenario === 'wrong actor' ? 'other-operator' : null);
            HistoricalMerchantRefundTest::problem($scenario === 'wrong actor' ? ProblemCode::AuthDenied : ProblemCode::RecoveryRequired,
                static fn () => $app->make(RepairCommands::class)->repair($action, $review));
            $this->assertNoApproval($scenario === 'other statement' ? 2 : 1);
            self::assertSame($history, $this->history($review));
            self::assertSame($calls, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            InventoryReconciliationTest::clear($path);
        }
    }

    private function assertNoApproval(int $originalCases = 1): void
    {
        self::assertSame(0, $this->rows('billium_execution_repair_receipt'));
        self::assertSame($originalCases, $this->rows('billium_execution_recon_review'));
        self::assertSame(0, $this->rows('billium_outbox_event', "type='execution.repair_recorded.v1'"));
    }
}
