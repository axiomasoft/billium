<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Data\AdmissionPlan;
use Billium\Execution\Data\CapabilityDetails;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\CapacitySnapshot;
use Billium\Execution\Data\DispatchDecision;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\MaterialChange;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ProviderFact;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Data\ProviderWorkClaim;
use Billium\Execution\Data\Reservation;
use Billium\Execution\Data\VerifiedAbsence;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Enums\ProviderEvidenceMode;
use Billium\Execution\Enums\ProviderLookupState;
use Billium\Execution\Enums\ReservationState;
use Billium\Execution\Internal\Authority\AbsenceProofRegistry;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Internal\Recovery\AttemptJournal;
use Billium\Execution\Internal\Storage\DatabaseExecutionStore;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Testkit\Drivers\SimulatedAbsenceVerifier;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fixtures\ChildProcess;
use Billium\Testkit\Fixtures\DeterministicWriterHost;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Tests\HarnessFactory;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

#[Group('pg')]
final class ProviderProcessingPersistenceTest extends ExecutionTestCase
{
    private ExecutionStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = $this->application()->make(ExecutionStore::class);
    }

    /** @param Closure(TransactionContext):void $callback */
    private function tx(Closure $callback, bool $raw = false, string $owner = 'source-a'): void
    {
        $scope = new FinancialScope(self::INSTALLATION, 'seller-a', 'test', $owner, $raw ? null : 'remote-billium');
        $spec = $this->spec(mode: $raw ? FinancialMode::RawCustody : FinancialMode::Active, class: $raw ? ModuleOperationClass::CallbackCustody : ModuleOperationClass::ObservedFactRecording,
            modules: ['execution', 'platform'], financialScope: $scope);
        $this->runner()->run($spec, function (TransactionContext $ctx) use ($callback): PlatformReceipt {
            $callback($ctx);

            return self::receipt();
        })->orThrow();
    }

    private function raw(string $ref, string $owner = 'source-a', string $verdict = 'verified'): ProviderEvidence
    {
        return new ProviderEvidence($ref, new ExecutionScope(self::INSTALLATION, 'seller-a', 'test', $owner), 'provider-a', 'account-a', null, 'digest-'.$ref,
            $verdict, 'v1', new DateTimeImmutable, ['receipt' => $ref], 'custody:'.$ref);
    }

    public function test_foreign_owner_cannot_read_claim_or_complete_work_and_stale_worker_cannot_unpark(): void
    {
        $scope = ExecutionSchemaTest::scopeFixture();
        $this->tx(function (TransactionContext $ctx): void {
            $this->store->appendEvidence($ctx, $this->raw('raw'));
            $this->store->enqueueProviderWork($ctx, 'raw', 'work', 'unknown');
        }, true);
        $caller = $this->callers->current();
        $foreign = new Caller($caller->sellerRef, $caller->environment, $caller->installationRef, 'actor', ['remote-billium'], ['other']);
        $this->callers->set($foreign);
        self::assertNull($this->store->providerWork('work'));
        $this->tx(function (TransactionContext $ctx): void {
            self::assertNull($this->store->claimProviderWork($ctx, 'work', 'foreign-worker', new DateTimeImmutable('+1 minute')));
        }, true, 'other');
        $this->callers->set($caller);
        $claim = null;
        $this->tx(function (TransactionContext $ctx) use (&$claim, $scope): void {
            $read = $this->store->providerWork('work', $ctx);
            self::assertEquals($scope, $read?->evidence->scope);
            self::assertSame('unknown', $read?->handlerVersion);
            self::assertSame('work', $this->store->enqueueProviderWork($ctx, 'raw', 'alternate-alias', 'unknown'));
            $claim = $this->store->claimProviderWork($ctx, 'work', 'worker', new DateTimeImmutable('+2 minutes'));
        }, true);
        self::assertInstanceOf(ProviderWorkClaim::class, $claim);
        $this->callers->set($foreign);
        $this->tx(function (TransactionContext $ctx) use ($claim): void {
            self::assertFalse($this->store->finishProviderWork($ctx, $claim, 'processed', 'stolen', null, null));
        }, true, 'other');
        $this->callers->set($caller);
        $expired = null;
        $this->tx(function (TransactionContext $ctx) use (&$expired, $claim): void {
            self::assertSame(1, $claim->leaseFence);
            self::assertTrue($this->store->finishProviderWork($ctx, $claim, 'parked', null, 'unsupported_handler', null));
            self::assertFalse($this->store->finishProviderWork($ctx, $claim, 'processed', 'stale', null, null));
            self::assertNull($this->store->claimProviderWork($ctx, 'work', 'worker', new DateTimeImmutable('+2 minutes')));
            $this->store->enqueueProviderWork($ctx, 'raw', 'work-v2', 'v2');
            $expired = $ctx;
        }, true);
        self::assertSame('parked', $this->store->providerWork('work')?->state);
        self::assertSame('due', $this->store->providerWork('work-v2')?->state);
        self::assertSame(1, $this->rows('billium_execution_provider_evidence'));
        $this->expectException(LogicException::class);
        $this->store->providerWork('work', $expired);
    }

    public function test_current_transaction_reads_reuse_cached_caller_and_cover_existing_reservation_locks(): void
    {
        $this->seedOperations();
        $app = $this->application();
        $host = new class implements CallerContext
        {
            public function current(): Caller
            {
                throw new LogicException('Host caller read under root lock');
            }
        };
        $store = new DatabaseExecutionStore($app->make(DatabaseManager::class), $this->runner(), $host, $app->make(BilliumOptions::class), $app->make(FinancialSessionAdmission::class));
        $scope = ExecutionSchemaTest::scopeFixture();
        $this->tx(function (TransactionContext $ctx) use ($scope, $store): void {
            $plan = $store->plan($scope, 'child', true, $ctx);
            $store->lock($ctx, $plan);
            self::assertContains('billium_execution_reservation', array_column($plan->locks, 'table'));
            self::assertNotNull($store->operation($scope, 'child', $ctx));
            self::assertNotNull($store->attempt($scope, 'attempt-child', $ctx));
            self::assertNotNull($store->attemptForOperation($scope, 'child', $ctx));
            self::assertNull($store->providerWork('missing', $ctx));
            $fact = $this->fact($ctx, 'child', 'hostproof', 'pending', 0, 'assigned-id');
            self::assertTrue($store->appendFact($ctx, $fact));
        });
    }

    private function seedOperations(?CapabilitySnapshot $cap = null): void
    {
        $scope = ExecutionSchemaTest::scopeFixture();
        $cap ??= ExecutionSchemaTest::capabilityFixture();
        $m = ExecutionSchemaTest::merchantFixture();
        $merchant = new MerchantRevision($m->revisionRef, $m->scope, $m->merchantAccountRef, $m->providerId, $cap->driverId,
            $cap->driverContractVersion, $cap->snapshotRef, $cap->digest, $m->credentialRef, $m->credentialVersionRef, $m->currencyCodes, $m->digest, $m->createdAt, $m->policy);
        $registry = $this->application()->make(DatabaseMerchantRegistry::class);
        $this->runner()->run($this->spec(mode: FinancialMode::Administrative, class: ModuleOperationClass::HistoricalRead, modules: ['execution', 'platform']),
            function (TransactionContext $ctx) use ($registry, $scope, $merchant, $cap): PlatformReceipt {
                $this->store->appendMerchantRevision($ctx, $merchant, $cap);
                $registry->appendLifecycle($ctx, $scope, $merchant->revisionRef, 'enable', 'enable_new', 'new', new DateTimeImmutable('-1 day'), null, 'fixture', 'proof');

                return self::receipt();
            })->orThrow();
        $binding = new FrozenBinding($scope, 'remote-billium', 'intent', 'root', $merchant->revisionRef, $merchant->merchantAccountRef, $merchant->driverId, $merchant->driverContractVersion,
            $cap->digest, 'semantic', 'execution.intent.v1', null, 'shop');
        $this->tx(function (TransactionContext $ctx) use ($scope, $binding): void {
            $this->store->transitionNamespace($ctx, $scope, NamespaceState::Open, 0, 'proof');
            $this->store->insertBinding($ctx, $binding);
            $op = $this->store->insertOperation($ctx, new AdmissionPlan($scope, $binding, 'root', EffectKind::Authorize, Money::parse('1.00', 'RUB'), 'key', 'semantic', 'execution.intent.v1', ['execution', 'platform'], [], null));
            $attempt = $this->store->insertAttempt($ctx, $op, 'attempt-root', 'reference-root');
            $this->store->insertDispatchWork($ctx, $attempt, 'dispatch-root', 'v1');
        });
        $this->owner()->update("UPDATE billium_execution_capacity SET authorized_limit_minor=100 WHERE root_operation_ref='root'");
        $this->tx(function (TransactionContext $ctx) use ($scope, $binding): void {
            $this->store->lockNamespace($ctx, $scope);
            $this->store->lock($ctx, $this->store->plan($scope, 'root', true, $ctx));
            foreach (['child', 'sibling'] as $ref) {
                $plan = new AdmissionPlan($scope, $binding, $ref, EffectKind::Capture, Money::parse('0.50', 'RUB'), 'key-'.$ref, 'sha256:'.hash('sha256', 'fixture-'.$ref), 'execution.intent.v1', ['execution', 'platform'], [], null);
                $this->store->claimChildBinding($ctx, $plan, 'fixture-'.$ref);
                $op = $this->store->insertOperation($ctx, $plan);
                $attempt = $this->store->insertAttempt($ctx, $op, 'attempt-'.$ref, 'reference-'.$ref);
                $this->store->insertDispatchWork($ctx, $attempt, 'dispatch-'.$ref, 'v1');
                $this->store->reserveCapacity($ctx, new Reservation('reservation-'.$ref, 'root', $ref, EffectKind::Capture, $op->requested, ReservationState::Held, Money::parse('0.00', 'RUB'), null), $this->store->capacity($ctx, 'root'));
            }
        });
    }

    private function fact(TransactionContext $ctx, string $operationRef, string $evidence, string $status, ?int $amount, string $assigned): ProviderFact
    {
        $op = $this->store->operation(ExecutionSchemaTest::scopeFixture(), $operationRef, $ctx) ?? throw new LogicException;
        $zero = Money::parse('0.00', 'RUB');
        $actual = $amount === null ? null : new Money($amount, $zero->currency);
        $this->store->appendEvidence($ctx, $this->raw($evidence));
        $s = new MaterialSnapshot($op->operationRef, $op->kind, 'account-a', 'RUB', Money::parse('1.00', 'RUB'), $actual ?? $zero, $zero, $zero, $status, $evidence, $evidence,
            'material-'.$evidence, [$evidence], new DateTimeImmutable, $assigned, ExecutionCodec::bindingDigest($op->binding), 'reference-'.$operationRef, $actual);

        return new ProviderFact('fact-'.$evidence, $operationRef, ExecutionCodec::providerIdentityScope(ExecutionSchemaTest::merchantFixture(), ExecutionSchemaTest::capabilityFixture()), $evidence, 'economic-'.$evidence, ProviderEvidenceMode::CumulativeSnapshot, $s, [$evidence]);
    }

    public function test_assigned_id_is_qualified_global_immutable_and_terminal_attempt_fences_pending_lookup(): void
    {
        $this->seedOperations();
        $scope = ExecutionSchemaTest::scopeFixture();
        $id = str_repeat('assigned-id/opaque/', 600);
        $this->tx(function (TransactionContext $ctx) use ($scope, $id): void {
            $this->store->lock($ctx, $this->store->plan($scope, 'root', true, $ctx));
            $attempt = $this->store->claimAttempt($ctx, 'attempt-child', 'worker', new DateTimeImmutable('+1 minute'));
            self::assertTrue($this->store->startDispatch($ctx, new DispatchDecision('child', $attempt->attemptRef, $attempt->leaseFence, $ctx->sessionEpoch(), 'ticket', 'egress', null, $attempt->immutableRequestDigest, new DateTimeImmutable('+10 seconds'))));
            $pending = $this->fact($ctx, 'child', 'pending', 'pending', null, $id);
            self::assertTrue($this->store->appendFact($ctx, $pending));
            self::assertNull($this->store->factForEvidence($ctx, 'pending', 'child'));
            self::assertNull($this->store->factByIdentity($ctx, $pending->providerIdentityScope, $pending->factIdentity)?->snapshot->operationAmount);
            $this->store->applyAttemptFact($ctx, $attempt, $pending, AttemptState::Pending, new DateTimeImmutable('+1 minute'));
            self::assertSame('due', self::connection($ctx->connectionName())->table('billium_execution_dispatch_work')->where('work_ref', 'dispatch-child')->value('state'));
            $current = $this->store->attemptForOperation($scope, 'child', $ctx) ?? throw new LogicException;
            self::assertGreaterThan($attempt->leaseFence, $current->leaseFence);
            self::assertNull($current->leaseOwner);
            $accepted = $this->fact($ctx, 'child', 'settled', 'succeeded', 120, $id);
            self::assertTrue($this->store->appendFact($ctx, $accepted));
            $this->store->applyAttemptFact($ctx, $current, $accepted, AttemptState::Succeeded, null);
            self::assertSame('processed', self::connection($ctx->connectionName())->table('billium_execution_dispatch_work')->where('work_ref', 'dispatch-child')->value('state'));
            self::assertNull(self::connection($ctx->connectionName())->table('billium_execution_dispatch_work')->where('work_ref', 'dispatch-child')->value('due_at'));
            $collision = $this->fact($ctx, 'sibling', 'collision', 'succeeded', 10, $id);
            self::assertFalse($this->store->appendFact($ctx, $collision));
            self::assertNull($this->store->factForEvidence($ctx, 'collision', 'sibling'));
            self::assertNull($this->store->factForEvidence($ctx, 'settled', 'sibling'));
            self::assertSame(120, $this->store->factForEvidence($ctx, 'settled', 'child')?->snapshot->operationAmount?->minor);
            $op = $this->store->operation($scope, 'child', $ctx) ?? throw new LogicException;
            $after = new OperationRecord($op->id, $op->operationRef, $op->rootOperationRef, $op->kind, $op->binding, $op->requested,
                1, 'held', 'held-overcapture', $op->authorizationRef, $op->createdAt);
            $priorCapacity = $this->store->capacity($ctx, 'root');
            $unheld = new MaterialChange($op, $after, $accepted->snapshot, $priorCapacity, 'unheld-discrepancy', [], [], null, null, 'over_capture');
            try {
                $this->store->appendDiscrepancy($ctx, 'unheld-discrepancy', $unheld);
                self::fail('A source discrepancy requires held capacity');
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            }
            self::assertEquals($priorCapacity, $this->store->capacity($ctx, 'root'));
            $cap = new CapacitySnapshot('root', $priorCapacity->version + 1, $priorCapacity->authorizedLimit, $priorCapacity->confirmedCapture,
                $priorCapacity->confirmedRefund, $priorCapacity->releasedAuthorization, $priorCapacity->heldCapture, $priorCapacity->heldVoid, $priorCapacity->heldRefund, true);
            $change = new MaterialChange($op, $after, $accepted->snapshot, $cap, 'overcapture', [], [], Money::parse('0.20', 'RUB'), null, 'over_capture');
            $this->store->appendDiscrepancy($ctx, 'overcapture', $change);
            $root = $this->store->operation($scope, 'root', $ctx) ?? throw new LogicException;
            $rootAfter = new OperationRecord($root->id, $root->operationRef, $root->rootOperationRef, $root->kind, $root->binding, $root->requested,
                1, 'held', 'held-root', $root->authorizationRef, $root->createdAt);
            $this->store->appendRevision($ctx, new MaterialChange($root, $rootAfter, $accepted->snapshot, $cap, 'overcapture', [], [], Money::parse('0.20', 'RUB'), null, 'over_capture'), 'root-receipt');
            $this->store->appendRevision($ctx, $change, 'revision-receipt');
            $this->store->assertMaterialRevision($ctx, $change);
            try {
                $forged = new MaterialChange($op, $after, $accepted->snapshot, $cap, 'different-reason', [], [], null, null, 'not-original');
                $this->store->assertMaterialRevision($ctx, $forged);
                self::fail('Forged exposure could publish');
            } catch (ProblemException $error) {
                self::assertStringContainsString('persisted material revision', $error->getMessage());
            }
            self::assertSame(120, $this->store->latestRootObservation($ctx, 'root')?->capturedTotal->minor);
            self::assertNull($this->store->lastObserved($ctx, 'root'));
            self::assertSame(120, $this->store->lastObserved($ctx, 'child')?->operationAmount?->minor);
        });
        self::assertSame($id, $this->store->providerOperationId($scope, 'attempt-child'));
        self::assertSame('child', $this->store->operationByProviderId($scope, 'provider-a', 'account-a', 'account/reference', $id)?->operationRef);
        self::assertNull($this->store->operationByProviderId($scope, 'provider-a', 'account-a', 'other-namespace', $id));
        self::assertNull($this->store->providerOperationId($scope, 'attempt-sibling'));
        self::assertSame('20', $this->value("SELECT exposure#>>'{overCaptured,minor}' FROM billium_execution_discrepancy WHERE discrepancy_ref='overcapture'"));
        self::assertSame('0', $this->value("SELECT last_consistent_applied#>>'{confirmedCapture,minor}' FROM billium_execution_operation_revision WHERE receipt_ref='revision-receipt'"));
        $caller = $this->callers->current();
        $this->callers->set(new Caller('other-seller', $caller->environment, $caller->installationRef, $caller->actorRef, ['remote-billium'], ['other-owner']));
        $foreign = new ExecutionScope($scope->installationRef, 'other-seller', $scope->environment, 'other-owner');
        self::assertNull($this->store->operationByProviderId($foreign, 'provider-a', 'account-a', 'account/reference', $id));
        self::assertNull($this->store->providerOperationId($foreign, 'attempt-child'));
        $this->callers->set($caller);
        $pdo = TestEnvironment::pdo();
        $pdo->exec('SET enable_seqscan=off');
        try {
            foreach ([
                ['SELECT id FROM billium_execution_provider_identity WHERE public.billium_execution_provider_identity_key(environment,provider_id,merchant_account_ref,provider_namespace,provider_operation_id)=public.billium_execution_provider_identity_key(?,?,?,?,?)', ['test', 'provider-a', 'account-a', 'account/reference', $id], 'billium_execution_assigned_operation'],
                ['SELECT id FROM billium_execution_provider_evidence WHERE installation_ref=? AND seller_ref=? AND environment=? AND provider_id=? AND merchant_account_ref=? AND provider_event_id=?', [self::INSTALLATION, 'seller-a', 'test', 'provider-a', 'account-a', 'event'], 'billium_execution_provider_evidence_lookup'],
            ] as [$sql, $params, $index]) {
                $query = $pdo->prepare('EXPLAIN '.$sql);
                $query->execute($params);
                self::assertStringContainsString($index, implode("\n", array_map(self::text(...), $query->fetchAll(PDO::FETCH_COLUMN))));
            }
        } finally {
            $pdo->exec('RESET enable_seqscan');
        }
    }

    public function test_release_needs_accepted_zero_for_exact_operation_and_owner_in_php_and_raw_sql(): void
    {
        $this->seedOperations();
        $scope = ExecutionSchemaTest::scopeFixture();
        $caller = $this->callers->current();
        $this->callers->set(new Caller($caller->sellerRef, $caller->environment, $caller->installationRef, 'actor', ['remote-billium'], ['source-a', 'other']));
        $this->tx(function (TransactionContext $ctx): void {
            $this->store->appendEvidence($ctx, $this->raw('foreign-literal', 'other', 'verified_no_effect'));
        }, true, 'other');
        $this->tx(function (TransactionContext $ctx) use ($scope): void {
            $this->store->lock($ctx, $this->store->plan($scope, 'root', true, $ctx));
            $this->store->appendEvidence($ctx, $this->raw('local-literal', verdict: 'verified_no_effect'));
            $sibling = $this->fact($ctx, 'sibling', 'sibling-zero', 'declined', 0, 'assigned-sibling');
            self::assertTrue($this->store->appendFact($ctx, $sibling));
        });
        $runtime = TestEnvironment::pdo();
        foreach (['foreign-literal', 'local-literal', 'sibling-zero'] as $wrong) {
            self::assertSame('23514', self::sqlState($runtime, "UPDATE billium_execution_reservation SET state='released',version=version+1,release_evidence_ref=".$runtime->quote($wrong)." WHERE reservation_ref='reservation-child'"));
        }
        $this->tx(function (TransactionContext $ctx) use ($scope): void {
            $this->store->lock($ctx, $this->store->plan($scope, 'root', true, $ctx));
            $c = $this->store->capacity($ctx, 'root');
            $next = new CapacitySnapshot($c->rootOperationRef, $c->version + 1, $c->authorizedLimit, $c->confirmedCapture, $c->confirmedRefund,
                $c->releasedAuthorization, Money::parse('0.50', 'RUB'), $c->heldVoid, $c->heldRefund, $c->financialHold);
            $zero = Money::parse('0.00', 'RUB');
            foreach (['foreign-literal', 'local-literal', 'sibling-zero'] as $wrong) {
                try {
                    $this->store->settleReservation($ctx, 'reservation-child', ReservationState::Released, $zero, $wrong, $next);
                    self::fail('Unrelated evidence released the reservation');
                } catch (ProblemException $exception) {
                    self::assertStringContainsString('original operation amount', $exception->getMessage());
                }
            }
            self::assertTrue($this->store->appendFact($ctx, $this->fact($ctx, 'child', 'child-zero', 'declined', 0, 'assigned-child')));
            $this->store->settleReservation($ctx, 'reservation-child', ReservationState::Released, $zero, 'child-zero', $next);
            self::assertSame(ReservationState::Released, $this->store->reservationForOperation($ctx, 'child')?->state);
            self::assertSame(ReservationState::Held, $this->store->reservation($ctx, 'reservation-sibling')?->state);
        });
    }

    private static function backendPid(PDO $pdo): int
    {
        $statement = $pdo->prepare('SELECT pg_backend_pid()');
        $statement->execute();

        return self::integer($statement->fetchColumn());
    }

    public static function concurrentInsert(string $fixture, string $path): void
    {
        $pdo = TestEnvironment::pdo();
        $data = ExecutionCodec::map($fixture);
        $backend = self::backendPid($pdo);
        file_put_contents($path, (string) $backend);
        $sql = $pdo->prepare('SELECT public.billium_execution_accept_provider_fact(?::jsonb,?::jsonb)');
        $sql->execute([ExecutionCodec::encode($data['fact']), ExecutionCodec::encode($data['identity'])]);
        file_put_contents($path.'.result', json_encode($sql->fetchColumn(), JSON_THROW_ON_ERROR));
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_racing_global_identity_inserts_keep_only_original_fact_and_mapping(bool $sameEffect): void
    {
        $this->seedOperations();
        $this->tx(function (TransactionContext $ctx): void {
            foreach (['child', 'sibling'] as $ref) {
                $this->store->appendEvidence($ctx, $this->raw('raw-'.$ref));
            }
        });
        $fixture = function (string $op) use ($sameEffect): array {
            $evidence = 'raw-'.$op;
            $fact = ['installation_ref' => self::INSTALLATION, 'seller_ref' => 'seller-a', 'environment' => 'test', 'fact_ref' => 'fact-'.$op, 'operation_ref' => $op,
                'provider_identity_scope' => 'provider/account/ns', 'fact_identity' => $sameEffect ? 'stable' : $op, 'evidence_mode' => 'stable_effect_id',
                'material_digest' => 'digest-'.$op, 'normalized_snapshot' => ['bindingDigest' => 'binding-'.$op, 'operationAmount' => ['minor' => 10]], 'evidence_refs' => [$evidence]];
            $identity = ['attempt_ref' => 'attempt-'.$op, 'provider_id' => 'provider-a', 'merchant_account_ref' => 'account-a', 'provider_namespace' => 'account/reference',
                'provider_operation_id' => $sameEffect ? $op : 'one-assigned-id', 'evidence_ref' => $evidence];

            return ['fact' => $fact, 'identity' => $identity];
        };
        $path = sys_get_temp_dir().'/billium-fact-race-'.bin2hex(random_bytes(12));
        $pdo = TestEnvironment::pdo();
        $child = null;
        try {
            $pdo->beginTransaction();
            $holder = self::backendPid($pdo);
            $sql = $pdo->prepare('SELECT public.billium_execution_accept_provider_fact(?::jsonb,?::jsonb)');
            $first = $fixture('child');
            $sql->execute([ExecutionCodec::encode($first['fact']), ExecutionCodec::encode($first['identity'])]);
            self::assertTrue($sql->fetchColumn());
            $child = new ChildProcess([PHP_BINARY, '-r', 'require "vendor/autoload.php"; Billium\Execution\Tests\Feature\ProviderProcessingPersistenceTest::concurrentInsert($argv[1], $argv[2]);',
                ExecutionCodec::encode($fixture('sibling')), $path]);
            $deadline = hrtime(true) + 10_000_000_000;
            $blocked = false;
            do {
                $other = is_file($path) ? (int) file_get_contents($path) : 0;
                if ($other > 0) {
                    $blocked = $this->value('SELECT ?::integer = ANY(pg_blocking_pids(?::integer))', [$holder, $other]) === true;
                }
                if (! $blocked) {
                    usleep(2000);
                }
            } while (! $blocked && hrtime(true) < $deadline);
            self::assertNotSame($holder, $other);
            self::assertTrue($blocked);
            $pdo->commit();
            self::assertSame(0, $child->wait());
            $child = null;
            self::assertSame('false', file_get_contents($path.'.result'));
            self::assertSame(1, $this->rows('billium_execution_provider_fact'));
            self::assertSame(1, $this->rows('billium_execution_provider_identity'));
            self::assertSame('attempt-child', $this->value('SELECT attempt_ref FROM billium_execution_provider_identity'));
            self::assertSame(2, $this->rows('billium_execution_provider_evidence'));
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $child?->kill();
            foreach ([$path, $path.'.result'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_independently_certified_child_absence_releases_only_its_reservation_after_durable_lookup_and_live_claim(): void
    {
        $cap = SimulatedCapabilities::snapshot();
        $this->seedOperations($cap);
        $scope = ExecutionSchemaTest::scopeFixture();
        $op = $this->store->operation($scope, 'child') ?? throw new LogicException;
        $clock = new FixtureClock(new DateTimeImmutable);
        $path = sys_get_temp_dir().'/billium-absence-'.bin2hex(random_bytes(12));
        $db = $this->application()->make(DatabaseManager::class)->connection();
        $level = static fn (): int => $db->transactionLevel();
        $hostLog = new DurableJournal($path.'.host');
        $host = new DeterministicWriterHost($hostLog, $clock, $level);
        $verifier = new SimulatedAbsenceVerifier(new DurableJournal($path.'.provider'), $hostLog, new DurableJournal($path.'.issued'), $clock, $cap, $level);
        try {
            $request = HarnessFactory::request($cap, 'child', 'reference-child', 50, $op->binding);
            $claims = HarnessFactory::dispatch($request, EffectKind::Capture);
            $host->openSession($claims);
            $ticket = $host->ticket($claims);
            $this->tx(function (TransactionContext $ctx) use ($scope, $ticket): void {
                $this->store->lock($ctx, $this->store->plan($scope, 'root', true, $ctx));
                $claimed = $this->store->claimAttempt($ctx, 'attempt-child', 'worker', new DateTimeImmutable('+1 minute'));
                self::assertTrue($this->store->startDispatch($ctx, new DispatchDecision('child', $claimed->attemptRef, $claimed->leaseFence, $ctx->sessionEpoch(), $ticket->ticketRef,
                    $ticket->egressFenceRef, null, $claimed->immutableRequestDigest, new DateTimeImmutable('+200 milliseconds'))));
            });
            $attempt = $this->store->attemptForOperation($scope, 'child') ?? throw new LogicException;
            $host->retire($claims, 'independent-fixture-egress-retirement');
            usleep(250_000);
            $clock->now = new DateTimeImmutable;
            $cert = $verifier->certify($op->binding, $attempt->providerReference, $op->createdAt->modify('-1 minute'), $clock->now);
            $this->application()->instance(ProviderAbsenceVerifier::class, $verifier);
            $registry = $this->application()->make(AbsenceProofRegistry::class);
            $proof = $registry->verify($cert, $op, $attempt, $cap);
            $untrusted = VerifiedAbsence::verify(self::createStub(ProviderAbsenceVerifier::class), $cert, $op, $attempt, $cap);
            foreach (['capability without absence certification' => ['absenceCertification' => false],
                'capability of another qualification' => ['qualificationEvidenceRef' => 'other-qualification'],
                'capability of another digest' => ['digest' => str_repeat('b', 64)],
                'capability of another account' => ['merchantAccountRef' => 'other-account'],
                'capability of another lookup namespace' => ['details' => new CapabilityDetails(...array_replace(get_object_vars($cap->details), ['lookupNamespace' => 'other-namespace']))],
            ] as $case => $change) {
                try {
                    VerifiedAbsence::verify(self::createStub(ProviderAbsenceVerifier::class), $cert, $op, $attempt, new CapabilitySnapshot(...array_replace(get_object_vars($cap), $change)));
                    self::fail($case);
                } catch (ProblemException $e) {
                    self::assertSame(ProblemCode::ValidationInvalid, $e->problem, $case);
                }
            }
            $merchant = $this->store->merchantRevision($scope, $op->binding->merchantRevisionRef);
            $lookup = new ProviderLookupResult(ProviderLookupState::CertifiedAbsent, null, null, $cert, null, null);
            $journal = $this->application()->make(AttemptJournal::class);
            $journal->retain($op, $attempt, $merchant, $lookup, 'verified_absence', true, 'attestation')->orThrow();
            $workRef = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE evidence_ref='attestation'"));
            $claim = null;
            $this->tx(function (TransactionContext $ctx) use (&$claim, $workRef): void {
                $claim = $this->store->claimProviderWork($ctx, $workRef, 'reducer', new DateTimeImmutable('+1 minute'));
            }, true);
            self::assertInstanceOf(ProviderWorkClaim::class, $claim);
            $this->tx(function (TransactionContext $ctx) use ($scope, $attempt, $claim, $untrusted): void {
                $this->store->lock($ctx, $this->store->plan($scope, 'root', true, $ctx));
                try {
                    $this->store->applyCertifiedAbsence($ctx, $attempt, $claim, $untrusted, null);
                    self::fail('Rogue verifier supplied an unregistered absence decision');
                } catch (ProblemException $failure) {
                    self::assertStringContainsString('configured verifier', $failure->getMessage());
                }
            });
            $apply = function (TransactionContext $ctx) use ($scope, $attempt, $claim, $proof): void {
                $this->store->lock($ctx, $this->store->plan($scope, 'root', true, $ctx));
                $capacity = $this->store->capacity($ctx, 'root');
                $next = new CapacitySnapshot($capacity->rootOperationRef, $capacity->version + 1, $capacity->authorizedLimit, $capacity->confirmedCapture, $capacity->confirmedRefund,
                    $capacity->releasedAuthorization, Money::parse('0.50', 'RUB'), $capacity->heldVoid, $capacity->heldRefund, $capacity->financialHold);
                $this->store->applyCertifiedAbsence($ctx, $attempt, $claim, $proof, $next);
            };
            try {
                $this->tx($apply);
                self::fail('Independent certificate still needs its original lookup receipt');
            } catch (ProblemException $e) {
                self::assertStringContainsString('original lookup', $e->getMessage());
            }
            self::assertSame('held', $this->value("SELECT state FROM billium_execution_reservation WHERE reservation_ref='reservation-child'"));
            $journal->retain($op, $attempt, $merchant, $lookup, 'lookup', false, 'original-lookup')->orThrow();
            $this->tx($apply);
            self::assertSame('failed', $this->store->operation($scope, 'child')?->state);
            self::assertSame('released', $this->value("SELECT state FROM billium_execution_reservation WHERE reservation_ref='reservation-child'"));
            self::assertSame('held', $this->value("SELECT state FROM billium_execution_reservation WHERE reservation_ref='reservation-sibling'"));
            self::assertSame('processed', $this->store->providerWork($workRef)?->state);
            self::assertSame($cert->certificateRef, $this->store->providerWork($workRef)->resultRef);
            $this->tx(function (TransactionContext $ctx) use ($scope, $proof, $untrusted): void {
                $this->store->lock($ctx, $this->store->plan($scope, 'root', true, $ctx));
                $settled = $this->store->operation($scope, 'child', $ctx) ?? throw new LogicException;
                $this->store->assertCertifiedDecision($ctx, $settled, $proof);
                try {
                    $this->store->assertCertifiedDecision($ctx, $settled, $untrusted);
                    self::fail('Original certificate bytes cannot impersonate configured issuance');
                } catch (ProblemException $failure) {
                    self::assertStringContainsString('configured verifier', $failure->getMessage());
                }
            });
            self::assertSame(1, $this->rows('billium_execution_certified_absence'));
            self::assertSame(0, $this->rows('billium_execution_provider_fact'));
            self::assertSame('no_provider_material', $this->value("SELECT observed->>'aggregateDisposition' FROM billium_execution_operation_revision WHERE operation_ref='child'"));
        } finally {
            foreach (['.host', '.provider', '.issued'] as $suffix) {
                if (is_file($path.$suffix)) {
                    unlink($path.$suffix);
                }
            }
        }
    }

    public function test_unassigned_evidence_cannot_change_the_frozen_provider_namespace_or_reapply_old_unsigned_effect(): void
    {
        $this->seedOperations();
        $scope = ExecutionSchemaTest::scopeFixture();
        $this->tx(function (TransactionContext $ctx) use ($scope): void {
            $this->store->lock($ctx, $this->store->plan($scope, 'root', true, $ctx));
            $fact = $this->fact($ctx, 'child', 'known-stable', 'succeeded', 10, 'temporary');
            $fields = get_object_vars($fact->snapshot);
            $fields['providerOperationId'] = null;
            $snapshot = ExecutionCodec::decode(MaterialSnapshot::class, ExecutionCodec::encode($fields));
            $forged = new ProviderFact('unassigned', 'child', 'forged-namespace', 'new-identity', 'economic', ProviderEvidenceMode::StableEffectId, $snapshot, $fact->evidenceRefs);
            try {
                $this->store->appendFact($ctx, $forged);
                self::fail('Unassigned operation bypassed frozen provider namespace');
            } catch (ProblemException $e) {
                self::assertStringContainsString('namespace', $e->getMessage());
            }
            unset($fields['providerOperationId'], $fields['providerReference'], $fields['bindingDigest'], $fields['operationAmount']);
            self::connection($ctx->connectionName())->table('billium_execution_provider_fact')->insert([
                'installation_ref' => $scope->installationRef, 'seller_ref' => $scope->sellerRef, 'environment' => $scope->environment,
                'fact_ref' => 'old-ref', 'operation_ref' => 'child', 'provider_identity_scope' => 'old-provider-account', 'fact_identity' => 'old-identity',
                'evidence_mode' => 'stable_effect_id', 'material_digest' => 'old-digest', 'normalized_snapshot' => ExecutionCodec::encode($fields),
                'evidence_refs' => ExecutionCodec::encode(['historical-evidence'])]);
            $same = new ProviderFact('new-ref', 'child', $fact->providerIdentityScope, 'new-identity', 'economic', ProviderEvidenceMode::StableEffectId, $snapshot, $fact->evidenceRefs);
            self::assertFalse($this->store->appendFact($ctx, $same));
            self::assertFalse($this->store->capacity($ctx, 'root')->financialHold);
            self::assertSame(0, $this->rows('billium_execution_operation_revision'));
            self::assertNull($this->store->factForEvidence($ctx, 'known-stable', 'child'));
        });
        self::assertSame(1, $this->rows('billium_execution_provider_fact'));
        self::assertSame('old-ref', $this->value('SELECT fact_ref FROM billium_execution_provider_fact'));
    }

    public function test_an_old_terminal_proof_cannot_release_or_partially_settle_a_larger_accepted_operation_amount(): void
    {
        $this->seedOperations();
        $scope = ExecutionSchemaTest::scopeFixture();
        $this->tx(function (TransactionContext $ctx) use ($scope): void {
            $this->store->lock($ctx, $this->store->plan($scope, 'root', true, $ctx));
            foreach ([['zero', 'declined', 0], ['partial', 'succeeded', 20], ['larger', 'pending', 40]] as [$ref, $status, $minor]) {
                self::assertTrue($this->store->appendFact($ctx, $this->fact($ctx, 'child', $ref, $status, $minor, 'same-child-id')));
            }
        });
        $runtime = TestEnvironment::pdo();
        foreach ([['released', 0, 'zero'], ['consumed', 20, 'partial']] as [$state, $minor, $ref]) {
            self::assertSame('23514', self::sqlState($runtime, 'UPDATE billium_execution_reservation SET state='.$runtime->quote($state).',consumed_minor='.$minor.',version=version+1,release_evidence_ref='.$runtime->quote($ref)." WHERE reservation_ref='reservation-child'"));
        }
        self::assertSame('held', $this->value("SELECT state FROM billium_execution_reservation WHERE reservation_ref='reservation-child'"));
    }
}
