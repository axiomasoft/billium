<?php

declare(strict_types=1);

namespace Billium\Execution\Tests;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Modules\ModuleTransitionEvidence;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Billium\Core\Exceptions\Modules\ModuleAdmissionRefused;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\CollectionCancellationParticipant;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\CollectionGuardPlan;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\DispatchCancelTest;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\CloseSettlementIntentNoEffect;
use Billium\Ledger\Data\Settlements\NoEffectProof;
use Billium\Platform\Data\Installation\ModuleTransitionRequest;
use Billium\Platform\Tests\Support\CurrentTransitionEvidence;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class CollectionDispatchGuardTest extends ExecutionTestCase
{
    private string $path;

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->make(Repository::class)->set('billium.modules.enabled', ['platform', 'ledger', 'execution', 'collection']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = Fixture::tempPath();
        $app = $this->application();
        Fixture::seedMerchant($app);
        CollectionAdmissionGuardTest::installStep($app);
        Fixture::host($app, $this->path, [Fixture::origin('step-a')]);
        CollectionAdmissionGuardTest::guard($app);
    }

    protected function tearDown(): void
    {
        TestEnvironment::pdo(owner: true)->exec('DROP TABLE IF EXISTS billium_test_collection_step');
        UnknownResultTest::clean($this->path);
        parent::tearDown();
    }

    private function bindCancellation(bool $fail): void
    {
        $app = $this->application();
        $app->instance(CollectionCancellationParticipant::class, new readonly class($app->make(DatabaseManager::class), $app->make(LedgerParticipants::class), $fail) implements CollectionCancellationParticipant
        {
            public function __construct(private DatabaseManager $db, private LedgerParticipants $ledger, private bool $fail) {}

            public function closeUnsent(TransactionContext $ctx, CollectionGuardPlan $plan, OperationRecord $operation, string $executionCancelReceiptRef): string
            {
                $ctx->assertParticipant('collection');
                $ctx->assertParticipant('ledger');
                $ctx->lock(...$plan->locks);
                $q = $this->db->connection($ctx->connectionName())->table('billium_test_collection_step')->where('step_ref', $plan->authorizationRef);
                $row = $q->first();
                if ($row === null || $plan->authorizationRef !== $operation->binding->collectionAuthorizationRef || $row->expected_digest !== $operation->binding->semanticDigest
                    || $row->dispatch_permit !== null || $row->issued_generation !== $plan->issuanceGeneration) {
                    throw new ProblemException(ProblemCode::RecoveryRequired, 'Unsent original collection step unavailable');
                }
                if ($row->incoming_held === false && $row->consent_valid === false && $row->cancel_generation === 1) {
                    return 'close-step-a';
                }
                $q->update(['incoming_held' => false]);
                $proof = new NoEffectProof($ctx->rootRef(), 'settlement-a', 1, $plan->authorizationRef, 'not_dispatched', $executionCancelReceiptRef);
                $command = new CloseSettlementIntentNoEffect(new BillingAccountScope('seller-a', 'test', 'account-a'), 'settlement-a', 1,
                    $plan->authorizationRef, 'close-step-a', 'RUB', 'execution_unsent_cancelled', $proof);
                $this->ledger->closeSettlementIntentNoEffect($ctx, $command);
                if ($this->fail) {
                    throw new ProblemException(ProblemCode::RecoveryRequired, 'Deliberate participant refusal after both closure writes');
                }

                return 'close-step-a';
            }
        });
        $app->forgetInstance(RootPaymentCommands::class);
    }

    public function test_unsent_collection_cancel_rolls_back_both_owners_on_failure_and_returns_original_step_closure(): void
    {
        $app = $this->application();
        $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
        try {
            $app->make(RootPaymentCommands::class)->cancel(Fixture::scope(), DispatchCancelTest::command())->orThrow();
            self::fail('Missing collection cancellation owner accepted');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::RecoveryRequired, $exception->problem);
        }
        $this->bindCancellation(true);
        try {
            $app->make(RootPaymentCommands::class)->cancel(Fixture::scope(), DispatchCancelTest::command());
            self::fail('Participant failure was accepted');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::RecoveryRequired, $exception->problem);
        }
        self::assertTrue($this->value('SELECT incoming_held AND consent_valid FROM billium_test_collection_step'));
        self::assertSame(1, $this->rows('billium_execution_attempt', "state='admitted'"));
        self::assertSame(0, $this->rows('billium_settlement_intent_closure'));
        $this->bindCancellation(false);
        $cancelled = $app->make(RootPaymentCommands::class)->cancel(Fixture::scope(), DispatchCancelTest::command())->orThrow();
        self::assertSame('cancelled', $cancelled->body['status']);
        self::assertIsArray($cancelled->body['evidenceRefs']);
        self::assertContains('close-step-a', $cancelled->body['evidenceRefs']);
        self::assertSame(1, $this->rows('billium_settlement_intent_closure'));
        self::assertTrue($this->value('SELECT NOT incoming_held AND consent_valid AND dispatch_permit IS NULL FROM billium_test_collection_step'));
        self::assertSame(0, UnknownResultTest::events($this->path, 'provider.call'));
    }

    public function test_revoke_between_admission_and_dispatch_closes_settlement_and_keeps_zero_sends(): void
    {
        $app = $this->application();
        $admit = $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
        self::assertSame('admitted', $admit->body['status']);
        CollectionAdmissionGuardTest::revoke($app);
        try {
            $app->make(AttemptWorker::class)->dispatch(Fixture::scope(), UnknownResultTest::attemptRef(), 'worker', new DateTimeImmutable('+30 seconds'));
            self::fail('Revoked step sent');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::OperationFinancialHold, $e->problem);
        }
        self::assertSame(0, UnknownResultTest::events($this->path, 'provider.call'));
        self::assertSame(1, $this->rows('billium_settlement_intent_closure'));
        self::assertSame(1, $this->rows('billium_execution_attempt', 'started_at IS NULL AND collection_dispatch_permit_ref IS NULL'));
        self::assertSame(false, $this->value('SELECT incoming_held FROM billium_test_collection_step'));
        $this->bindCancellation(false);
        $cancel = $app->make(RootPaymentCommands::class)->cancel(Fixture::scope(), DispatchCancelTest::command())->orThrow();
        self::assertSame('cancelled', $cancel->body['status']);
        self::assertSame(1, $this->rows('billium_settlement_intent_closure'));
    }

    public function test_revoke_after_dispatch_permit_cannot_claim_no_effect_or_free_incoming_reservation(): void
    {
        $app = $this->application();
        $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
        Fixture::host($app, $this->path, [Fixture::origin('step-a')], checkpoint: static function (string $phase) use ($app): void {
            if ($phase === 'before_accept') {
                CollectionAdmissionGuardTest::revoke($app);
            }
        });
        $app->make(AttemptWorker::class)->dispatch(Fixture::scope(), UnknownResultTest::attemptRef(), 'worker', new DateTimeImmutable('+30 seconds'))->orThrow();
        self::assertSame(1, UnknownResultTest::events($this->path, 'provider.call'));
        $permit = $this->value('SELECT dispatch_permit FROM billium_test_collection_step');
        self::assertIsString($permit);
        self::assertSame($permit, $this->value('SELECT collection_dispatch_permit_ref FROM billium_execution_attempt'));
        self::assertSame(0, $this->rows('billium_settlement_intent_closure'));
        self::assertTrue($this->value('SELECT incoming_held AND NOT consent_valid FROM billium_test_collection_step'));
        $this->bindCancellation(false);
        self::assertSame(ProblemCode::OperationNotCancellable, $app->make(RootPaymentCommands::class)->cancel(Fixture::scope(), DispatchCancelTest::command())->problem);
        self::assertSame(0, $this->rows('billium_settlement_intent_closure'));
    }

    public function test_failed_dispatch_cas_rolls_back_collection_permit_and_database_retry_never_repeats_egress(): void
    {
        $app = $this->application();
        $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
        $owner = TestEnvironment::pdo(owner: true);
        $owner->exec("CREATE FUNCTION public.billium_test_refuse_start() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RETURN NULL; END'");
        $owner->exec("CREATE TRIGGER billium_test_refuse BEFORE UPDATE ON billium_execution_attempt FOR EACH ROW WHEN (OLD.state='admitted' AND NEW.state='dispatching') EXECUTE FUNCTION billium_test_refuse_start()");
        try {
            try {
                $app->make(AttemptWorker::class)->dispatch(Fixture::scope(), UnknownResultTest::attemptRef(), 'refused', new DateTimeImmutable('+1 second'));
                self::fail('Failed attempt CAS committed a permit');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::RecoveryRequired, $e->problem);
            }
            self::assertNull($this->value('SELECT dispatch_permit FROM billium_test_collection_step'));
            self::assertNull($this->value('SELECT collection_dispatch_permit_ref FROM billium_execution_attempt'));
            self::assertSame(0, UnknownResultTest::events($this->path, 'provider.call'));
        } finally {
            $owner->exec('DROP TRIGGER billium_test_refuse ON billium_execution_attempt');
            $owner->exec('DROP FUNCTION public.billium_test_refuse_start()');
        }
        $owner->exec('CREATE SEQUENCE billium_test_dispatch_retry');
        $owner->exec(<<<'SQL'
            CREATE FUNCTION public.billium_test_retry_start() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,public AS $$
            BEGIN IF nextval('public.billium_test_dispatch_retry')=1 THEN RAISE EXCEPTION 'simulated serialization loss' USING ERRCODE='40001'; END IF; RETURN NEW; END $$
            SQL);
        $owner->exec("CREATE TRIGGER billium_test_retry BEFORE UPDATE ON billium_execution_attempt FOR EACH ROW WHEN (OLD.state='admitted' AND NEW.state='dispatching') EXECUTE FUNCTION billium_test_retry_start()");
        try {
            usleep(1_100_000);
            $app->make(AttemptWorker::class)->dispatch(Fixture::scope(), UnknownResultTest::attemptRef(), 'retry', new DateTimeImmutable('+20 seconds'))->orThrow();
            self::assertSame(2, self::integer(self::pdoValue($owner, 'SELECT last_value FROM billium_test_dispatch_retry')));
            self::assertSame(1, UnknownResultTest::events($this->path, 'provider.call'));
            self::assertSame(1, $this->rows('billium_execution_attempt', 'collection_dispatch_permit_ref IS NOT NULL'));
        } finally {
            $owner->exec('DROP TRIGGER billium_test_retry ON billium_execution_attempt');
            $owner->exec('DROP FUNCTION public.billium_test_retry_start()');
            $owner->exec('DROP SEQUENCE public.billium_test_dispatch_retry');
        }
    }

    /** @return list<array{string}> */
    public static function eligibility(): array
    {
        return [['pre-drain'], ['post-drain'], ['omitted-ledger'], ['recovery-only']];
    }

    #[DataProvider('eligibility')]
    public function test_draining_allows_only_preexisting_frozen_authorization_with_complete_closure(string $case): void
    {
        $app = $this->application();
        if ($case === 'omitted-ledger') {
            CollectionAdmissionGuardTest::guard($app, false);
        } else {
            $this->installation()->transitionModule(new ModuleTransitionRequest('drain-collection', 'collection', ModuleLifecycleState::Draining, 'drain'))->orThrow();
            if ($case === 'recovery-only') {
                $app->instance(ModuleTransitionEvidence::class, new CurrentTransitionEvidence);
                $this->installation()->transitionModule(new ModuleTransitionRequest('recover-collection', 'collection', ModuleLifecycleState::RecoveryOnly, 'recovery', 'fixture-current-owner-evidence'))->orThrow();
            }
            if ($case === 'post-drain') {
                $this->owner()->table('billium_test_collection_step')->update(['issued_generation' => 2]);
            }
        }
        try {
            $app->make(RootPaymentCommands::class)->admit(Fixture::scope(), Fixture::wire())->orThrow();
            $app->make(AttemptWorker::class)->dispatch(Fixture::scope(), UnknownResultTest::attemptRef(), 'worker', new DateTimeImmutable('+30 seconds'))->orThrow();
            self::assertSame('pre-drain', $case);
        } catch (ProblemException|ModuleAdmissionRefused $exception) {
            self::assertNotSame('pre-drain', $case, $exception->getMessage());
        }
        self::assertSame($case === 'pre-drain' ? 1 : 0, UnknownResultTest::events($this->path, 'provider.call'));
        self::assertSame($case === 'pre-drain' ? 1 : 0, $this->rows('billium_execution_operation'));
    }
}
