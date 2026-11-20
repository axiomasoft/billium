<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\AdmissionConflict;
use Billium\Execution\Data\AdmissionPlan;
use Billium\Execution\Data\DispatchDecision;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\OriginDecision;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Enums\WorkState;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Closure;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class DispatchPersistenceTest extends ExecutionTestCase
{
    private ExecutionStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->application()->make(ExecutionStore::class);
    }

    /** @param Closure(TransactionContext):void $f */
    private function tx(Closure $f): void
    {
        $this->runner()->run($this->spec(modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($f) {
            $f($ctx);

            return self::receipt();
        })->orThrow();
    }

    private function admitFixture(bool $collection = false): FrozenBinding
    {
        $scope = ExecutionSchemaTest::scopeFixture();
        $merchant = ExecutionSchemaTest::merchantFixture();
        $cap = ExecutionSchemaTest::capabilityFixture();
        $registry = $this->application()->make(DatabaseMerchantRegistry::class);
        $this->runner()->run($this->spec(mode: FinancialMode::Administrative, class: ModuleOperationClass::HistoricalRead, modules: ['execution', 'platform']), function (TransactionContext $ctx) use ($scope, $merchant, $cap, $registry) {
            $this->store->appendMerchantRevision($ctx, $merchant, $cap);
            $registry->appendLifecycle($ctx, $scope, $merchant->revisionRef, 'enable', 'enable_new', 'new', new DateTimeImmutable('-1 day'), null, 'fixture', 'fixture-proof');

            return self::receipt();
        })->orThrow();
        $auth = $collection ? 'authorization' : null;
        $origin = new OriginDecision($scope, 'root', 'intent', 'shop', 'remote-billium', 1, $auth, 'basis', 'policy', 'period-a');
        $binding = new FrozenBinding($scope, 'remote-billium', 'intent', 'root', $merchant->revisionRef, $merchant->merchantAccountRef, $merchant->driverId, $merchant->driverContractVersion,
            $cap->digest, 'semantic', 'execution.intent.v1', $auth, 'shop', $origin);
        $this->tx(function (TransactionContext $ctx) use ($binding, $scope) {
            $this->store->transitionNamespace($ctx, $scope, NamespaceState::Open, 0, 'attested');
            $this->store->insertBinding($ctx, $binding);
            $op = $this->store->insertOperation($ctx, new AdmissionPlan($scope, $binding, 'root', EffectKind::Charge, Money::parse('10.00', 'RUB'), 'command', 'semantic', 'execution.intent.v1', ['execution', 'platform'], [], null));
            $attempt = $this->store->insertAttempt($ctx, $op, 'attempt', 'reference');
            $this->store->insertDispatchWork($ctx, $attempt, 'dispatch', 'handler');
        });

        return $binding;
    }

    public function test_stored_request_collection_permit_deadline_and_lease_fenced_work(): void
    {
        $scope = $this->admitFixture(true)->scope;
        $this->tx(function (TransactionContext $ctx) use ($scope) {
            $this->store->lock($ctx, $this->store->plan($scope, 'root'));
            $attempt = $this->store->claimAttempt($ctx, 'attempt', 'worker', new DateTimeImmutable('+1 minute'));
            $deadline = new DateTimeImmutable('+10 seconds');
            $decision = fn (string $digest) => new DispatchDecision('root', 'attempt', $attempt->leaseFence, $ctx->sessionEpoch(), 'ticket', 'fence', 'actual-collection-permit', $digest, $deadline);
            self::assertFalse($this->store->startDispatch($ctx, $decision('semantic')));
            self::assertTrue($this->store->startDispatch($ctx, $decision($attempt->immutableRequestDigest)));
            $ready = $this->store->attempt($scope, 'attempt') ?? throw new LogicException;
            self::assertSame('actual-collection-permit', $ready->collectionDispatchPermitRef);
            self::assertSame('ticket', $ready->dispatchPermitRef);
            self::assertNotNull($ready->startedAt);
            self::assertEquals($deadline, $ready->requestDeadlineAt);
            self::assertFalse($this->store->startDispatch($ctx, $decision($attempt->immutableRequestDigest)));
            $op = $this->store->operation($scope, 'root') ?? throw new LogicException;
            self::assertFalse($this->store->cancelAdmitted($ctx, $op, 'attempt', 'receipt'));
            self::assertFalse($this->store->finishDispatchWork($ctx, 'attempt', 0, WorkState::Parked, null, 'wrong-fence'));
            try {
                $this->store->finishDispatchWork($ctx, 'attempt', 1, WorkState::Processed, null, null);
                self::fail('Processed without durable evidence');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::RecoveryRequired, $e->problem);
            }
            self::assertTrue($this->store->finishDispatchWork($ctx, 'attempt', 1, WorkState::Parked, null, 'manual'));
        });
        $this->tx(function (TransactionContext $ctx) use ($scope) {
            $this->store->lock($ctx, $this->store->plan($scope, 'root'));
            try {
                $this->store->claimAttempt($ctx, 'attempt', 'worker-b', new DateTimeImmutable('+60 seconds'));
                self::fail('Parked job was reclaimed');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::RecoveryRequired, $e->problem);
            }
        });
        self::assertSame(1, $this->rows('billium_execution_dispatch_work', "state='parked' AND due_at IS NULL AND lease_owner IS NULL AND lease_expires_at IS NULL"));
    }

    public function test_cancel_before_dispatch_commits_an_immutable_local_revision_and_closes_work(): void
    {
        $scope = $this->admitFixture()->scope;
        $this->tx(function (TransactionContext $ctx) use ($scope) {
            $this->store->lock($ctx, $this->store->plan($scope, 'root'));
            $op = $this->store->operation($scope, 'root') ?? throw new LogicException;
            self::assertSame('period-a', $op->binding->origin?->periodRef);
            self::assertTrue($this->store->cancelAdmitted($ctx, $op, 'attempt', 'local-cancel'));
            self::assertFalse($this->store->cancelAdmitted($ctx, $op, 'attempt', 'second'));
        });
        self::assertSame(1, $this->rows('billium_execution_operation_revision', "revision=1 AND receipt_ref='local-cancel'"));
        self::assertSame(1, $this->rows('billium_execution_operation', "revision=1 AND state='cancelled'"));
        self::assertSame(1, $this->rows('billium_execution_dispatch_work', "state='processed' AND due_at IS NULL"));
        self::assertSame(AttemptState::Cancelled, $this->store->attempt($scope, 'attempt')?->state);
    }

    public function test_namespace_shared_lock_blocks_concurrent_close_and_precedes_feed(): void
    {
        $scope = $this->admitFixture()->scope;
        self::assertGreaterThan(LockRank::ModuleAdmission->value, LockRank::IntentNamespace->value);
        self::assertLessThan(LockRank::FeedPartition->value, LockRank::IntentNamespace->value);
        $this->tx(function (TransactionContext $ctx) use ($scope) {
            self::assertSame(NamespaceState::Open, $this->store->lockNamespace($ctx, $scope)->state);
            $ctx->lock(new LockRequest(LockRank::FeedPartition, 'billium_execution_observation_partition', [1]));
            self::assertSame(1, $this->store->lockNamespace($ctx, $scope)->generation);
            $second = TestEnvironment::pdo();
            $second->exec("SET lock_timeout='80ms'");
            self::assertSame('55P03', self::sqlState($second, "UPDATE billium_execution_namespace SET state='closed' WHERE intent_owner_namespace='source-a'"));
        });
        $this->tx(function (TransactionContext $ctx) use ($scope): void {
            $this->store->transitionNamespace($ctx, $scope, NamespaceState::Closed, 1, 'closed-proof');
        });
        $this->tx(function (TransactionContext $ctx) use ($scope) {
            $root = $this->store->operation($scope, 'root') ?? throw new LogicException;
            try {
                $this->store->insertBinding($ctx, $root->binding);
                self::fail('New admission crossed closed namespace');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::KeyExpired, $e->problem);
            }
        });
    }

    public function test_only_exact_raw_custody_can_record_immutable_conflict_and_binding_retention(): void
    {
        $scope = $this->admitFixture()->scope;
        $conflict = new AdmissionConflict($scope, 'payment.execute.v1', 'sha256:'.str_repeat('a', 64), 'sha256:'.str_repeat('b', 64), 'root');
        $this->tx(function (TransactionContext $ctx) use ($conflict): void {
            try {
                $this->store->recordAdmissionConflict($ctx, $conflict);
                self::fail('Financial root wrote a refusal audit');
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::AuthDenied, $error->problem);
            }
        });
        $this->runner()->run($this->spec(mode: FinancialMode::RawCustody, class: ModuleOperationClass::CallbackCustody, modules: ['platform', 'execution']), function (TransactionContext $ctx) use ($conflict) {
            $ref = $this->store->recordAdmissionConflict($ctx, $conflict);
            self::assertSame($ref, $this->store->recordAdmissionConflict($ctx, $conflict));

            return self::receipt();
        })->orThrow();
        self::assertSame($this->callers->current()->actorRef, $this->value('SELECT actor_ref FROM billium_execution_admission_conflict'));
        self::assertSame(1, $this->rows('billium_execution_admission_conflict', "problem_code='billium.idempotency.conflict' AND original_operation_ref='root' AND recorded_at IS NOT NULL"));
        self::assertSame(1, $this->rows('billium_execution_business_binding', "retention_policy='execution-business-identity-indefinite-v1' AND retention_deadline_at IS NULL"));
        self::assertSame(1, $this->rows('billium_execution_operation', "revision=0 AND state='admitted'"));
        self::assertSame('42501', self::sqlState(TestEnvironment::pdo(), "UPDATE billium_execution_admission_conflict SET actor_ref='other'"));
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), 'DELETE FROM billium_execution_admission_conflict'));
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), 'UPDATE billium_execution_business_binding SET retention_deadline_at=now()'));
    }

    public function test_processed_work_requires_an_exact_queued_transport_delivery_and_retires_its_send_permit(): void
    {
        $scope = $this->admitFixture()->scope;
        $this->tx(function (TransactionContext $ctx) use ($scope) {
            $this->store->lock($ctx, $this->store->plan($scope, 'root'));
            $claim = $this->store->claimAttempt($ctx, 'attempt', 'worker', new DateTimeImmutable('+1 minute'));
            self::assertTrue($this->store->startDispatch($ctx, new DispatchDecision('root', 'attempt', 1, $ctx->sessionEpoch(), 'ticket', 'fence', null, $claim->immutableRequestDigest, new DateTimeImmutable('+10 seconds'))));
            $record = fn (string $ref, string $verdict, string $handler, array $fields) => new ProviderEvidence($ref, $scope, 'provider-a', 'account-a', null, 'digest', $verdict, $handler, new DateTimeImmutable,
                ExecutionCodec::map(ExecutionCodec::encode($fields)), 'custody:'.$ref);
            $provider = $record('provider-receipt', 'verified', 'provider.v1', ['providerEventId' => 'fact']);
            $this->store->appendEvidence($ctx, $provider);
            $fields = ['schema' => 'billium.execution.transport-observation.v1', 'operationRef' => 'root', 'attemptRef' => 'attempt', 'providerReference' => 'reference', 'leaseFence' => '1', 'retainedProviderEvidenceRef' => 'provider-receipt'];
            $other = $record('foreign', 'local_transport', 'execution.transport.v1', [...$fields, 'operationRef' => 'other']);
            $this->store->appendEvidence($ctx, $other);
            try {
                $this->store->finishAttempt($ctx, 'attempt', 1, AttemptState::Pending, 'foreign');
                self::fail('Accepted observation belonging to another operation');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            }
            $local = $record('transport', 'local_transport', 'execution.transport.v1', $fields);
            $this->store->appendEvidence($ctx, $local);
            self::assertTrue($this->store->finishAttempt($ctx, 'attempt', 1, AttemptState::Pending, 'transport'));
            try {
                $this->store->finishDispatchWork($ctx, 'attempt', 1, WorkState::Processed, null, null);
                self::fail('Missing original evidence queue entry');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::RecoveryRequired, $e->problem);
            }
            $this->store->enqueueProviderWork($ctx, 'provider-receipt', 'work', 'provider.v1');
            self::assertTrue($this->store->finishDispatchWork($ctx, 'attempt', 1, WorkState::Processed, null, null));
        });
        self::assertSame(1, $this->rows('billium_execution_attempt', "state='pending' AND dispatch_ticket_ref IS NULL AND lease_expires_at IS NULL"));
        self::assertSame(1, $this->rows('billium_execution_dispatch_work', "state='processed' AND due_at IS NULL"));
    }

    public function test_due_and_expired_claims_require_exact_current_work_fence(): void
    {
        $scope = $this->admitFixture()->scope;
        $this->tx(function (TransactionContext $ctx) use ($scope) {
            $this->store->lock($ctx, $this->store->plan($scope, 'root'));
            $claim = $this->store->claimAttempt($ctx, 'attempt', 'worker-a', new DateTimeImmutable('+1 minute'));
            self::assertSame(1, $claim->leaseFence);
            try {
                $this->store->claimAttempt($ctx, 'attempt', 'worker-b', new DateTimeImmutable('+1 minute'));
                self::fail('Unexpired lease was stolen');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::RecoveryRequired, $e->problem);
            }
        });
        // Simulate an elapsed lease on both durable records, without depending on scheduler timing.
        $pdo = TestEnvironment::pdo();
        $pdo->exec("UPDATE billium_execution_dispatch_work SET lease_expires_at=clock_timestamp()-interval '1 second'");
        $pdo->exec("UPDATE billium_execution_attempt SET lease_expires_at=clock_timestamp()-interval '1 second'");
        $this->tx(function (TransactionContext $ctx) use ($scope) {
            $this->store->lock($ctx, $this->store->plan($scope, 'root'));
            $claim = $this->store->claimAttempt($ctx, 'attempt', 'worker-b', new DateTimeImmutable('+1 minute'));
            self::assertSame(2, $claim->leaseFence);
            self::assertFalse($this->store->finishDispatchWork($ctx, 'attempt', 1, WorkState::Parked, null, 'stale'));
            self::assertTrue($this->store->finishDispatchWork($ctx, 'attempt', 2, WorkState::Due, new DateTimeImmutable('+1 hour'), 'retry'));
            try {
                $this->store->claimAttempt($ctx, 'attempt', 'worker-c', new DateTimeImmutable('+1 minute'));
                self::fail('Future wakeup ran early');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::RecoveryRequired, $e->problem);
            }
        });
        self::assertSame(1, $this->rows('billium_execution_dispatch_work', "state='due' AND due_at > now() AND attempt_count=2 AND lease_fence=2"));
    }

    public function test_raw_receipt_replays_and_conflicting_event_evidence_survive_postgres(): void
    {
        $scope = ExecutionSchemaTest::scopeFixture();
        $this->tx(function (TransactionContext $ctx) use ($scope) {
            $delivery = fn (string $ref, string $value) => new ProviderEvidence($ref, $scope, 'provider', 'account', 'provider-event', 'reported-digest', 'verified', 'v1', new DateTimeImmutable, ['value' => $value], 'secure://body');
            $first = $this->store->appendEvidence($ctx, $delivery('receipt', 'original'));
            $replay = $this->store->appendEvidence($ctx, $delivery('receipt', 'original'));
            self::assertSame('receipt', $first);
            self::assertSame($first, $replay);
            foreach (['receipt', 'different-external-ref'] as $index => $external) {
                $actual = $this->store->appendEvidence($ctx, $delivery($external, 'changed'));
                self::assertNotSame($external, $actual);
                $this->store->enqueueProviderWork($ctx, $actual, 'conflict-work-'.$index, 'v1');
            }
        });
        self::assertSame(3, $this->rows('billium_execution_provider_evidence'));
        self::assertSame(2, $this->rows('billium_execution_provider_work', "state='quarantined'"));
        self::assertSame(1, $this->rows('billium_execution_provider_evidence', "sanitized_payload->>'externalEvidenceRef'='different-external-ref' AND sanitized_payload#>>'{originalPayload,value}'='changed'"));
        try {
            $this->tx(function (TransactionContext $ctx) use ($scope): void {
                $this->store->appendEvidence($ctx, new ProviderEvidence('sensitive-conflict', $scope, 'provider', 'account', 'provider-event', 'digest-sensitive', 'verified', 'v1', new DateTimeImmutable, ['api_key' => 'not-inline-api-key', 'note' => '4111111111111111', 'cvv2' => 'not-inline-cvv2'], 'secure://sensitive'));
            });
            self::fail('Sensitive evidence must be refused before a conflict row can copy it');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::ValidationInvalid, $exception->problem);
        }
        self::assertSame(3, $this->rows('billium_execution_provider_evidence'));
        self::assertSame(0, $this->rows('billium_execution_provider_evidence', "sanitized_payload::text LIKE '%not-inline-api-key%' OR sanitized_payload::text LIKE '%4111111111111111%' OR sanitized_payload::text LIKE '%not-inline-cvv2%'"));
    }
}
