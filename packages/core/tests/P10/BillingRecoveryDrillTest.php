<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P10;

use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\Drill\ExecutionRestoreTest;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\OriginDecision;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\AuthorizationLifecycleTest;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Credits\RecordCustomerCredit;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Platform\Contracts\Feed\EventFeed;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Platform\Exceptions\TransitionRefused;
use Billium\Platform\Internal\Outbox\OutboxPruner;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Restore\PostgresSnapshot;
use Billium\Testkit\Restore\RestoreSessionAuthority;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use ValueError;

/**
 * Minimal delta on top of:
 * - Restored snapshot starts in recovery with zero auto-dispatch + old-worker/egress fencing
 * - Explicit activation required; copy with identical max(commit_no) never auto-activates
 * - Retention prune preserves legal holds/pins, unknown attempts, provider evidence, and dedupe floors
 * - Mixed-version reader fail-closed & non-destructive expand-contract rollback preserve immutable facts
 */
#[Group('pg')]
#[Group('pg-serial')]
#[Group('P10.5')]
#[Group('D71')]
#[Group('D72')]
#[Group('REQ-ACC-12')]
final class BillingRecoveryDrillTest extends ExecutionTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('billium.feed.signing_key', str_repeat('k', 32));
    }

    private static function key(): string
    {
        return getenv('BILLIUM_SIMULATED_AUTHORITY_KEY') ?: throw new LogicException('Missing fixture authority key');
    }

    private static function scopeSelection(): FinancialScope
    {
        $scope = Fixture::scope();

        return new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, 'remote-billium');
    }

    private static function origin(string $operation, string $intent): OriginDecision
    {
        return new OriginDecision(...array_replace(get_object_vars(Fixture::origin(operation: $operation)), ['businessIntentRef' => $intent]));
    }

    private static function charge(string $ref, string $intent, int $key): ExecutePayment
    {
        $wire = Fixture::wire([
            'commandKey' => Fixture::key($key),
            'operationRef' => $ref,
            'source' => ['context' => 'checkout', 'intentRef' => $intent, 'orderRef' => 'order-'.$ref],
        ]);
        self::assertInstanceOf(ExecutePayment::class, $wire);

        return $wire;
    }

    #[Group('ACC-RST-03')]
    #[Group('ACC-RST-04')]
    #[Group('PB-13')]
    #[Group('PB-20')]
    public function test_restored_snapshot_starts_in_recovery_with_zero_auto_dispatch_and_old_worker_fencing(): void
    {
        $app = $this->application();
        $scope = Fixture::scope();
        $source = TestEnvironment::databaseName();
        $path = Fixture::tempPath();
        $backup = null;

        try {
            Fixture::seedMerchant($app);
            $writer = Fixture::host($app, $path, [
                Fixture::origin(),
                self::origin('root-before', 'intent-before'),
                self::origin('root-fresh', 'intent-fresh'),
            ]);

            $app->make(RootPaymentCommands::class)->admit($scope, self::charge('root-before', 'intent-before', 11))->orThrow();
            AuthorizationLifecycleTest::dispatchAndReduce($app, 'root-before');
            $app->make(RootPaymentCommands::class)->admit($scope, Fixture::wire())->orThrow();
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));

            $ticket = array_find(
                new DurableJournal($path.'.host')->records(),
                static fn (object $r): bool => $r instanceof DispatchTicket && $r->claims->operationRef === 'root-before',
            );
            self::assertInstanceOf(DispatchTicket::class, $ticket);

            // Take a real PostgreSQL snapshot before retiring the old writer/session.
            $backup = new PostgresSnapshot(TestEnvironment::pdo(owner: true), $source, $path.'.dump');

            // Old-worker quiescence & fencing: seal old epoch and retire egress ticket.
            $this->authority->issueTransition('sealing', 'p105-sealing', [1]);
            $this->installation()->beginSealing('p105-sealing', 1);
            $this->authority->issueTransition('seal', 'p105-seal', [1]);
            $this->installation()->seal('p105-seal', 1);
            $writer->retire($ticket->claims, 'old worker quiesced and egress retired');

            $retiredRejected = false;
            try {
                $writer->verify($ticket, $ticket->claims);
            } catch (RuntimeException $failure) {
                $retiredRejected = str_contains($failure->getMessage(), 'Retired');
            }
            self::assertTrue($retiredRejected, 'Retired egress ticket must be rejected');

            // Old worker cannot mutate or dispatch after seal.
            $commitsAtSeal = $this->rows('billium_money_commit_log');
            $this->assertRefused(
                ProblemCode::RecoveryRequired,
                fn () => $app->make(AttemptWorker::class)->dispatch($scope, UnknownResultTest::attemptRef(), 'stale-worker', new DateTimeImmutable('+30 seconds'))->orThrow(),
            );
            self::assertSame($commitsAtSeal, $this->rows('billium_money_commit_log'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));

            // Restore snapshot to a fresh PostgreSQL database.
            $cloneName = $backup->restore();
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $cloneName;
            putenv('DB_DATABASE='.$cloneName);

            $clone = ExecutionRestoreTest::cloneApplication($app);
            $replica = $clone->make(DatabaseManager::class)->connection();
            $tokens = $clone->make(FinancialSessionAdmission::class);
            $authority = $clone->make(SimulatedFinancialAuthorityEvidence::class);

            self::assertSame('active', $clone->make(Installation::class)->currentSession(self::INSTALLATION)?->state);
            self::assertSame('admitted', $replica->table('billium_execution_attempt')->where('operation_ref', 'root-a')->value('state'));

            // Without a valid host token, restored copy is quarantined with zero auto-dispatch.
            $tokens->set(null);
            $this->assertRefused(
                ProblemCode::InstallationQuarantined,
                fn () => $clone->make(AttemptWorker::class)->dispatch($scope, UnknownResultTest::attemptRef(), 'clone-worker', new DateTimeImmutable('+30 seconds'))->orThrow(),
            );

            // With a new attachment generation token, ordinary dispatch & new intents require recovery.
            $newGenToken = FixedSessionAdmission::token(self::INSTALLATION, attachmentGeneration: 2);
            $tokens->set($newGenToken);
            $authority->issueProcess($newGenToken, [self::scopeSelection()]);
            $this->assertRefused(
                ProblemCode::RecoveryRequired,
                fn () => $clone->make(RootPaymentCommands::class)->admit($scope, self::charge('root-fresh', 'intent-fresh', 99))->orThrow(),
            );
            $this->assertRefused(
                ProblemCode::RecoveryRequired,
                fn () => $clone->make(AttemptWorker::class)->dispatch($scope, UnknownResultTest::attemptRef(), 'clone-worker', new DateTimeImmutable('+30 seconds'))->orThrow(),
            );

            // Raw custody remains permitted while quarantined/recovering; zero new provider calls occurred.
            $custody = $clone->make(OperationRunner::class)->run(
                $this->spec(
                    kind: 'platform.raw_custody_probe',
                    key: 'p105-custody-1',
                    mode: FinancialMode::RawCustody,
                    class: ModuleOperationClass::CallbackCustody,
                    modules: ['platform'],
                    commit: null,
                ),
                static fn (): Receipt => new PlatformReceipt('platform.custody', ['ok' => true]),
            );
            self::assertTrue($custody->accepted);
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $source;
            putenv('DB_DATABASE='.$source);
            $backup?->close();
            foreach (glob($path.'*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    #[Group('ACC-RST-13')]
    #[Group('D62')]
    public function test_explicit_activation_required_and_equal_commit_max_never_auto_activates(): void
    {
        $app = $this->application();
        $source = TestEnvironment::databaseName();
        $path = Fixture::tempPath();
        $backup = null;

        try {
            Fixture::host($app, $path);
            $first = $this->runner()->run(
                $this->spec(key: 'p105-commit-1'),
                static fn (TransactionContext $ctx): Receipt => self::receipt(['effect' => self::effect($ctx, 'before-dump')]),
            );
            self::assertTrue($first->accepted);

            // Snapshot at exact same max(commit_no) as sealed source.
            $backup = new PostgresSnapshot(TestEnvironment::pdo(owner: true), $source, $path.'.dump');

            $this->authority->issueTransition('sealing', 'p105-eq-sealing', [1]);
            $this->installation()->beginSealing('p105-eq-sealing', 1);
            $this->authority->issueTransition('seal', 'p105-eq-seal', [1]);
            $this->installation()->seal('p105-eq-seal', 1);
            $sourceMaxCommit = self::integer($this->value('SELECT max(commit_no) FROM billium_money_commit_log'));

            $cloneName = $backup->restore();
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $cloneName;
            putenv('DB_DATABASE='.$cloneName);

            $clone = ExecutionRestoreTest::cloneApplication($app);
            $replica = $clone->make(DatabaseManager::class)->connection();
            $copyMaxCommit = self::integer($replica->scalar('SELECT max(commit_no) FROM billium_money_commit_log'));
            self::assertSame($sourceMaxCommit, $copyMaxCommit, 'Copy has identical max(commit_no) to sealed source');

            $tokens = $clone->make(FinancialSessionAdmission::class);
            $authority = $clone->make(SimulatedFinancialAuthorityEvidence::class);
            $installation = $clone->make(Installation::class);

            // Equal commit max does NOT auto-activate.
            $tokenGen2 = FixedSessionAdmission::token(self::INSTALLATION, 'owner-2', 2, 2, 2, [FinancialMode::Active, FinancialMode::Recovery], 'restored');
            $tokens->set($tokenGen2);
            $authority->issueProcess($tokenGen2);
            $this->assertRefused(
                ProblemCode::RecoveryRequired,
                fn () => $clone->make(OperationRunner::class)->run($this->spec(key: 'p105-before-open'), static fn (): Receipt => self::receipt())->orThrow(),
            );

            // Opening a new epoch starts in recovery, never active.
            $external = new RestoreSessionAuthority($authority, new DurableJournal($path.'.authority'), self::key());
            $clone->instance(FinancialAuthorityEvidence::class, $external);
            $open = $authority->issueTransition('open', 'p105-open-2', ['owner-2', 2, 2, 2, 'verified-retirement']);
            self::assertNotNull($open->retirementProof);
            $external->adopt($open, $open->retirementProof);

            $opened = $installation->openSession('p105-open-2', 'owner-2', 2, 2, 2, 'verified-retirement');
            self::assertSame(2, $opened->epoch);
            self::assertSame('recovery', $opened->state);

            // Ordinary money still refused until explicit activation.
            $this->assertRefused(
                ProblemCode::RecoveryRequired,
                fn () => $clone->make(OperationRunner::class)->run($this->spec(key: 'p105-in-recovery'), static fn (): Receipt => self::receipt())->orThrow(),
            );

            // Superseded epoch 1 can never be reactivated; runtime SQL UPDATE is denied.
            try {
                $staleActivate = $authority->issueTransition('activate', 'p105-activate-old', [1, 'cert-old', 'admit-old', 'ev-old']);
                self::assertNotNull($staleActivate->activationCertificate);
                $external->adopt($staleActivate, $open->retirementProof, $staleActivate->activationCertificate->coverage);
                $installation->activateSession('p105-activate-old', 1, 'cert-old', 'admit-old', 'ev-old');
                self::fail('Superseded epoch must never be reactivated');
            } catch (TransitionRefused $refused) {
                self::assertSame('not_the_current_epoch', $refused->reason);
            }
            self::assertSame('42501', self::sqlState(TestEnvironment::pdo(), "UPDATE billium_financial_session SET state = 'active' WHERE epoch = 1"));

            // Explicit certified activation enables epoch 2.
            $activate = $authority->issueTransition('activate', 'p105-activate-2', [2, 'cert-2', 'admit-2', 'reconciled']);
            self::assertNotNull($activate->activationCertificate);
            $external->adopt($activate, $open->retirementProof, $activate->activationCertificate->coverage);
            $activated = $installation->activateSession('p105-activate-2', 2, 'cert-2', 'admit-2', 'reconciled');
            self::assertSame('active', $activated->state);

            $afterActivate = $clone->make(OperationRunner::class)->run(
                $this->spec(key: 'p105-after-activate'),
                static fn (TransactionContext $ctx): Receipt => self::receipt(['effect' => self::effect($ctx, 'after-activate')]),
            );
            self::assertTrue($afterActivate->accepted);
            self::assertSame(1, $replica->table('billium_money_commit_log')->where('session_epoch', 2)->count());

            // Pre-restore token remains permanently refused.
            $oldToken = FixedSessionAdmission::token(self::INSTALLATION);
            $tokens->set($oldToken);
            $authority->issueProcess($oldToken);
            $this->assertRefused(
                ProblemCode::RecoveryRequired,
                fn () => $clone->make(OperationRunner::class)->run($this->spec(key: 'p105-old-token'), static fn (): Receipt => self::receipt())->orThrow(),
            );
        } finally {
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $source;
            putenv('DB_DATABASE='.$source);
            $backup?->close();
            foreach (glob($path.'*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    #[Group('PF-07')]
    #[Group('ACC-RST-06')]
    #[Group('ACC-RST-08')]
    public function test_retention_prune_preserves_legal_holds_unknown_evidence_and_dedupe_floors(): void
    {
        $app = $this->application();
        $owner = $this->owner();
        $scope = Fixture::scope();
        $path = Fixture::tempPath();

        try {
            // 1. OutboxPruner refuses an unconfigured retention floor.
            $unconfiguredRefused = false;
            try {
                new OutboxPruner($owner, null)->prune();
            } catch (LogicException) {
                $unconfiguredRefused = true;
            }
            self::assertTrue($unconfiguredRefused, 'Prune must refuse unconfigured retention floor');

            // 2. Active required feed subscription (legal/audit hold pin) protects outbox rows from pruning.
            $feed = $app->make(EventFeed::class);
            $feedScope = new FeedScope(self::INSTALLATION, 'seller-a', 'test', '1');
            $selection = $feed->openFeed($feedScope, 'events-2026-10-v1', ['billium.event.v1'], ['obligation.created.v1'], required: true);

            $owner->insert(<<<'SQL'
                INSERT INTO billium_outbox_event
                    (event_id, seller_ref, environment, type, schema_version, aggregate_ref, aggregate_revision, occurred_at, digest, payload, contract_revision, owner_module_id, source_scope)
                SELECT 'p105-evt-' || i, 'seller-a', 'test', 'obligation.created.v1', 'billium.event.v1', 'obl-' || i, 1, now(),
                       'sha256:' || repeat('b', 64), '{}'::jsonb, 'events-2026-10-v1', 'ledger', ?
                FROM generate_series(1, 5) i
                SQL, [$feedScope->key()]);
            $owner->insert(<<<'SQL'
                INSERT INTO billium_outbox_delivery(event_id, destination_ref, status, available_at, delivered_at)
                SELECT event_id, 'default', 'delivered', now(), now()
                FROM billium_outbox_event WHERE event_id LIKE 'p105-evt-%'
                SQL);

            self::assertSame(0, new OutboxPruner($owner, 0)->prune(), 'Active required subscription pin must block prune');
            self::assertSame(5, $owner->table('billium_outbox_event')->where('event_id', 'like', 'p105-evt-%')->count());

            // 3. Unknown attempt & provider evidence + indefinite business binding survive receipt prune & outbox prune.
            Fixture::seedMerchant($app);
            Fixture::host($app, $path, [Fixture::origin()], mode: ResponseMode::AcceptedThenTimeout);
            $commands = $app->make(RootPaymentCommands::class);
            $originalWire = Fixture::wire();
            $admitted = $commands->admit($scope, $originalWire)->orThrow();

            $app->make(AttemptWorker::class)->dispatch($scope, UnknownResultTest::attemptRef(), 'p105-worker', new DateTimeImmutable('+30 seconds'));
            self::assertSame(AttemptState::Unknown, $app->make(ExecutionStore::class)->attempt($scope, UnknownResultTest::attemptRef())?->state);
            self::assertGreaterThan(0, $this->rows('billium_execution_provider_evidence'));

            // Release the required subscription pin and run OutboxPruner: safe outbox prefix prunes cleanly,
            // while unknown attempt, provider evidence, and indefinite business binding stay intact.
            $feed->closeFeed($selection);
            self::assertGreaterThanOrEqual(5, new OutboxPruner($owner, 0)->prune());
            self::assertSame(0, $owner->table('billium_outbox_event')->where('event_id', 'like', 'p105-evt-%')->count());
            self::assertSame(AttemptState::Unknown, $app->make(ExecutionStore::class)->attempt($scope, UnknownResultTest::attemptRef())?->state);
            self::assertGreaterThan(0, $this->rows('billium_execution_provider_evidence'));

            // 4. Close namespace and prune idempotency receipts: dedupe floor preserves original binding and blocks stale keys.
            $this->runner()->run($this->spec(kind: 'execution.close_namespace', modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($scope): PlatformReceipt {
                $this->application()->make(ExecutionStore::class)->transitionNamespace($ctx, $scope, NamespaceState::Closed, 1, 'p105-retention-floor');

                return self::receipt();
            })->orThrow();
            $owner->statement('TRUNCATE billium_idempotency_receipt');
            self::assertSame(0, $this->rows('billium_idempotency_receipt'));

            $replayed = $commands->admit($scope, $originalWire)->orThrow();
            self::assertSame($admitted->body['receiptRef'], $replayed->body['receiptRef']);
            self::assertSame(1, $this->rows('billium_execution_business_binding', "retention_policy='execution-business-identity-indefinite-v1' AND retention_deadline_at IS NULL"));

            $unseenOldWire = Fixture::wire([
                'commandKey' => Fixture::key(44),
                'operationRef' => 'root-expired',
                'source' => ['context' => 'checkout', 'intentRef' => 'intent-expired'],
            ]);
            $this->assertRefused(ProblemCode::KeyExpired, fn () => $commands->admit($scope, $unseenOldWire)->orThrow());
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'), 'Prune and replay must never trigger duplicate dispatch');
        } finally {
            foreach (glob($path.'*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    #[Group('ACC-RST-10')]
    #[Group('REQ-FAIL-29')]
    public function test_mixed_version_reader_and_non_destructive_expand_contract_rollback_safety(): void
    {
        $app = $this->application();
        $owner = $this->owner();
        $ledger = $app->make(Ledger::class);
        $account = new BillingAccountScope('seller-a', 'test', 'billing-p105');
        $path = Fixture::tempPath();
        $expanded = false;
        $statusExpanded = false;

        try {
            // Seed immutable ledger baseline.
            $ledger->recordObligation(new RecordObligation('p105-obl-1', $account, 'p105-obl-1', 'basis-1', 'customer-a', 'comp-1', Money::parse('10.00', 'RUB'), 'sha256:'.str_repeat('a', 64)))->orThrow();
            $ledger->recordObligation(new RecordObligation('p105-obl-2', $account, 'p105-obl-2', 'basis-2', 'customer-a', 'comp-2', Money::parse('10.00', 'RUB'), 'sha256:'.str_repeat('b', 64)))->orThrow();
            $ledger->declareSettlementIntent(new DeclareSettlementIntent('p105-intent', $account, 'p105-intent', 'RUB', ['p105-obl-1', 'p105-obl-2']))->orThrow();
            $ledger->recordCustomerCredit(new RecordCustomerCredit('p105-credit', $account, 'p105-credit', 'credit-basis', Money::parse('50.00', 'RUB')))->orThrow();

            $firstAlloc = new Allocate('p105-alloc-1', $account, 'p105-alloc-1', 'p105-obl-1', Money::parse('10.00', 'RUB'), customerCreditRef: 'p105-credit');
            self::assertTrue($ledger->allocate($firstAlloc)->orThrow()->accepted);

            // 1. Expand phase: additive nullable column + NOT VALID check constraint; immutable amount_minor remains guarded.
            $owner->unprepared(<<<'SQL'
                ALTER TABLE billium_allocation ADD COLUMN IF NOT EXISTS p105_drill_note text;
                ALTER TABLE billium_allocation ADD CONSTRAINT billium_p105_note_len CHECK (p105_drill_note IS NULL OR length(p105_drill_note) <= 64) NOT VALID;
                CREATE OR REPLACE FUNCTION public.billium_p105_allocation_guard() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF TG_OP = 'UPDATE' AND (to_jsonb(OLD) - 'p105_drill_note') = (to_jsonb(NEW) - 'p105_drill_note') THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'billium_allocation is append-only' USING ERRCODE = '55000';
                END
                $$;
                DROP TRIGGER IF EXISTS billium_allocation_append_only ON billium_allocation;
                CREATE TRIGGER billium_allocation_append_only BEFORE UPDATE OR DELETE ON billium_allocation
                    FOR EACH ROW EXECUTE FUNCTION public.billium_p105_allocation_guard();
                SQL);
            $expanded = true;
            self::assertFalse($this->value("SELECT convalidated FROM pg_constraint WHERE conname='billium_p105_note_len'"));

            // 2. Mixed-version write: old-generation code writes without p105_drill_note; backfill fills metadata without touching immutable amounts.
            $secondAlloc = new Allocate('p105-alloc-2', $account, 'p105-alloc-2', 'p105-obl-2', Money::parse('10.00', 'RUB'), customerCreditRef: 'p105-credit');
            self::assertTrue($ledger->allocate($secondAlloc)->orThrow()->accepted);
            self::assertSame(2, $this->rows('billium_allocation', 'p105_drill_note IS NULL'));

            $immutableSnapshot = [
                'allocation' => $owner->select('SELECT id, receipt_ref, amount_minor, settlement_intent_id FROM billium_allocation ORDER BY id'),
                'receipts' => $owner->select('SELECT * FROM billium_allocation_receipt ORDER BY id'),
                'commits' => $owner->select('SELECT commit_no, kind, session_epoch FROM billium_money_commit_log ORDER BY commit_no'),
            ];

            // Mutating immutable monetary column is still rejected even during backfill.
            self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), 'UPDATE billium_allocation SET amount_minor = amount_minor + 1'));

            // Backfill + validate constraint.
            $owner->update("UPDATE billium_allocation SET p105_drill_note = 'backfilled-v2' WHERE p105_drill_note IS NULL");
            $owner->unprepared('ALTER TABLE billium_allocation VALIDATE CONSTRAINT billium_p105_note_len');
            self::assertTrue($this->value("SELECT convalidated FROM pg_constraint WHERE conname='billium_p105_note_len'"));

            // 3. Non-destructive code rollback: old-generation replay succeeds with identical immutable facts; expanded column stays (contract deferred).
            $replay = $ledger->allocate($firstAlloc)->orThrow();
            self::assertTrue($replay->accepted);
            self::assertEquals($immutableSnapshot['allocation'], $owner->select('SELECT id, receipt_ref, amount_minor, settlement_intent_id FROM billium_allocation ORDER BY id'));
            self::assertEquals($immutableSnapshot['receipts'], $owner->select('SELECT * FROM billium_allocation_receipt ORDER BY id'));
            self::assertEquals($immutableSnapshot['commits'], $owner->select('SELECT commit_no, kind, session_epoch FROM billium_money_commit_log ORDER BY commit_no'));
            self::assertTrue($owner->getSchemaBuilder()->hasColumn('billium_allocation', 'p105_drill_note'));

            // 4. Mixed-version N-1 worker/reader fails closed on unknown future attempt state before provider dispatch.
            Fixture::seedMerchant($app);
            Fixture::host($app, $path);
            $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
            $commitsBeforeUnknown = $this->rows('billium_money_commit_log');

            $owner->unprepared(<<<'SQL'
                ALTER TABLE billium_execution_attempt DROP CONSTRAINT billium_execution_attempt_state_check,
                    ADD CONSTRAINT billium_execution_attempt_state_check
                    CHECK (state IN ('admitted','dispatching','pending','unknown','succeeded','failed','cancelled','fixture_future_status'));
                UPDATE billium_execution_attempt SET state = 'fixture_future_status' WHERE operation_ref = 'root-a';
                SQL);
            $statusExpanded = true;

            $failedClosed = false;
            try {
                $app->make(AttemptWorker::class)->dispatch(Fixture::scope(), UnknownResultTest::attemptRef(), 'n-minus-one', new DateTimeImmutable('+1 minute'));
            } catch (ValueError $error) {
                $failedClosed = str_contains($error->getMessage(), 'fixture_future_status');
            }
            self::assertTrue($failedClosed, 'N-1 worker must fail closed on unknown future state');
            self::assertSame(0, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(0, $this->rows('billium_execution_attempt', 'lease_fence > 0 OR dispatch_ticket_ref IS NOT NULL'));
            self::assertSame($commitsBeforeUnknown, $this->rows('billium_money_commit_log'));
        } finally {
            if ($statusExpanded) {
                $owner->unprepared(<<<'SQL'
                    UPDATE billium_execution_attempt SET state = 'admitted' WHERE state = 'fixture_future_status';
                    ALTER TABLE billium_execution_attempt DROP CONSTRAINT billium_execution_attempt_state_check,
                        ADD CONSTRAINT billium_execution_attempt_state_check
                        CHECK (state IN ('admitted','dispatching','pending','unknown','succeeded','failed','cancelled'));
                    SQL);
            }
            if ($expanded) {
                $owner->unprepared(<<<'SQL'
                    DROP TRIGGER IF EXISTS billium_allocation_append_only ON billium_allocation;
                    CREATE TRIGGER billium_allocation_append_only BEFORE UPDATE OR DELETE ON billium_allocation
                        FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
                    DROP FUNCTION IF EXISTS public.billium_p105_allocation_guard();
                    ALTER TABLE billium_allocation DROP CONSTRAINT IF EXISTS billium_p105_note_len;
                    ALTER TABLE billium_allocation DROP COLUMN IF EXISTS p105_drill_note;
                    SQL);
            }
            foreach (glob($path.'*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    private function assertRefused(ProblemCode $expected, Closure $call): void
    {
        try {
            $call();
            self::fail("Expected refusal {$expected->value} did not occur");
        } catch (ProblemException $problem) {
            self::assertSame($expected, $problem->problem);
        }
    }
}
