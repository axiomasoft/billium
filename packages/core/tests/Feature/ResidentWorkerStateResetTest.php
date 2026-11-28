<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Core\Internal\Runner\DatabaseOperationRunner;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Data\AdmissionPlan;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\CapabilityDetails;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\DispatchDecision;
use Billium\Execution\Data\DispatchRequest;
use Billium\Execution\Data\DriverRequest;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\MerchantRevisionPolicy;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderLookupResult;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Enums\CaptureMode;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Enums\ProviderEvidenceMode;
use Billium\Execution\Enums\ProviderLookupState;
use Billium\Execution\Enums\QualificationMode;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Execution\Internal\Authority\DispatchAuthority;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Testkit\Fixtures\DeterministicWriterHost;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use RuntimeException;

#[Group('pg')]
final class ResidentWorkerStateResetTest extends ExecutionTestCase
{
    private ?string $journalPath = null;

    protected function tearDown(): void
    {
        if ($this->journalPath !== null && is_file($this->journalPath)) {
            unlink($this->journalPath);
        }

        parent::tearDown();
    }

    public function test_resident_worker_resets_runner_caller_and_dispatch_authority_state_across_20_multi_seller_operations(): void
    {
        $runner = $this->runner();
        self::assertInstanceOf(DatabaseOperationRunner::class, $runner);

        $databases = $this->application()->make(DatabaseManager::class);
        $connection = $databases->connection();
        $store = $this->application()->make(ExecutionStore::class);
        $registry = $this->application()->make(DatabaseMerchantRegistry::class);

        $this->journalPath = sys_get_temp_dir().'/billium-resident-worker-'.bin2hex(random_bytes(12));
        $host = new DeterministicWriterHost(
            new DurableJournal($this->journalPath),
            new FixtureClock(new DateTimeImmutable),
            static fn (): int => $databases->connection()->transactionLevel(),
        );
        $this->application()->instance(WriterAdmission::class, $host);
        $this->application()->instance(DispatchEgress::class, $host);

        $authority = $this->application()->make(DispatchAuthority::class);
        self::assertInstanceOf(DispatchAuthority::class, $authority);

        $runnerCurrentProp = new ReflectionProperty(DatabaseOperationRunner::class, 'current');
        $authorityVerifiedProp = new ReflectionProperty(DispatchAuthority::class, 'verified');

        $sellers = ['seller-a', 'seller-b', 'seller-c', 'seller-d'];
        $dispatchFixtures = [];
        foreach ($sellers as $idx => $sellerRef) {
            $dispatchFixtures[$sellerRef] = $this->seedDispatchFixture($sellerRef, $idx + 1, $store, $registry, $host);
        }

        // Cross-seller ticket isolation check: a ticket prepared for seller-a cannot be used under seller-b.
        $this->switchCaller('seller-a');
        $fixtureA = $dispatchFixtures['seller-a'];
        $ticketA = $authority->prepare($fixtureA['request']);
        self::assertCount(1, $this->verifiedMap($authorityVerifiedProp, $authority));

        $this->switchCaller('seller-b');
        try {
            $runner->run(
                $this->spec(
                    kind: 'execution.dispatch_cross_seller',
                    modules: ['execution', 'platform'],
                    seller: 'seller-b',
                    financialScope: new FinancialScope(self::INSTALLATION, 'seller-b', 'test', 'source-a', 'remote-billium'),
                ),
                static function (TransactionContext $ctx) use ($authority, $ticketA, $fixtureA): Receipt {
                    $authority->validate($ctx, $ticketA, $fixtureA['operation'], $fixtureA['attempt']);

                    return self::receipt();
                },
            );
            self::fail('Cross-seller ticket validation must be refused');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::AuthDenied, $exception->problem);
        }

        try {
            $decisionFake = new DispatchDecision(
                $fixtureA['operation']->operationRef,
                $fixtureA['attempt']->attemptRef,
                $fixtureA['attempt']->leaseFence,
                1,
                $ticketA->ticketRef,
                $ticketA->egressFenceRef,
                null,
                $fixtureA['attempt']->immutableRequestDigest,
                $ticketA->expiresAt,
            );
            $authority->send(
                $ticketA,
                $this->driverRequest($fixtureA['operation'], $decisionFake, $fixtureA['request'], $fixtureA['cap']),
                static fn (): ProviderLookupResult => new ProviderLookupResult(ProviderLookupState::Ambiguous, null, null, null, 'unused', null),
            );
            self::fail('Cross-seller ticket egress must be refused');
        } catch (ProblemException $exception) {
            self::assertSame(ProblemCode::RecoveryRequired, $exception->problem);
        }

        // Complete seller-a dispatch and send so the verified cache is evicted before the 20-op sequence.
        $this->switchCaller('seller-a');
        $decisionA = null;
        $planA = $store->plan($fixtureA['operation']->binding->scope, $fixtureA['operation']->operationRef);
        $runner->run(
            $this->spec(
                kind: 'execution.dispatch_cleanup',
                modules: ['execution', 'platform'],
                seller: 'seller-a',
                financialScope: new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium'),
            ),
            static function (TransactionContext $ctx) use ($store, $planA, $authority, $ticketA, $fixtureA, &$decisionA): Receipt {
                $store->lock($ctx, $planA);
                $decisionA = $authority->validate($ctx, $ticketA, $fixtureA['operation'], $fixtureA['attempt']);
                self::assertTrue($store->startDispatch($ctx, $decisionA));

                return self::receipt();
            },
        );
        self::assertInstanceOf(DispatchDecision::class, $decisionA);
        $authority->send(
            $ticketA,
            $this->driverRequest($fixtureA['operation'], $decisionA, $fixtureA['request'], $fixtureA['cap']),
            static fn (): ProviderLookupResult => new ProviderLookupResult(
                ProviderLookupState::Ambiguous,
                null,
                null,
                null,
                'simulated-cleanup',
                null,
            ),
        );
        self::assertSame([], $this->verifiedMap($authorityVerifiedProp, $authority));

        // 20 sequential multi-seller operations through the exact same singleton instances.
        $scenarios = [
            'accepted',
            'stored_refusal',
            'none_refusal',
            'lock_plan_retry',
            'uncaught_exception',
            'dispatch_cycle',
            'accepted',
            'auth_scope_mismatch',
            'stored_refusal',
            'lock_plan_retry',
            'none_refusal',
            'uncaught_exception',
            'dispatch_cycle',
            'accepted',
            'stored_refusal',
            'lock_plan_retry',
            'uncaught_exception',
            'none_refusal',
            'dispatch_cycle',
            'accepted',
        ];
        self::assertCount(20, $scenarios);

        $acceptedWrites = 0;
        $dispatchSeq = 0;

        foreach ($scenarios as $index => $scenario) {
            $opNumber = $index + 1;
            $sellerRef = $sellers[$index % count($sellers)];
            $this->switchCaller($sellerRef);

            // Confirm the container still returns the exact same singletons.
            self::assertSame($runner, $this->runner());
            self::assertSame($authority, $this->application()->make(DispatchAuthority::class));

            $capturedContext = null;

            if ($scenario === 'accepted') {
                $outcome = $runner->run(
                    $this->spec(
                        kind: 'test.resident.accepted',
                        key: "resident-accepted-{$opNumber}",
                        digest: 'sha256:'.hash('sha256', "resident-accepted-{$opNumber}"),
                        seller: $sellerRef,
                    ),
                    function (TransactionContext $ctx) use ($sellerRef, $opNumber, $connection, &$capturedContext): Receipt {
                        $capturedContext = $ctx;
                        $this->assertInTxCallerScope($ctx, $connection, $sellerRef);
                        self::effect($ctx, "resident-ok-{$opNumber}");

                        return self::receipt(['op' => $opNumber, 'seller' => $sellerRef]);
                    },
                );
                self::assertInstanceOf(StoredOutcome::class, $outcome);
                self::assertTrue($outcome->accepted);
                $acceptedWrites++;
            } elseif ($scenario === 'stored_refusal') {
                $outcome = $runner->run(
                    $this->spec(
                        kind: 'test.resident.stored_refusal',
                        key: "resident-stored-{$opNumber}",
                        digest: 'sha256:'.hash('sha256', "resident-stored-{$opNumber}"),
                        seller: $sellerRef,
                    ),
                    function (TransactionContext $ctx) use ($sellerRef, $opNumber, $connection, &$capturedContext): Receipt {
                        $capturedContext = $ctx;
                        $this->assertInTxCallerScope($ctx, $connection, $sellerRef);
                        self::effect($ctx, "resident-rolled-back-{$opNumber}");

                        throw new ProblemException(ProblemCode::RefundCapacityExceeded, "stored refusal {$opNumber}");
                    },
                );
                self::assertFalse($outcome->accepted);
                self::assertSame(ProblemCode::RefundCapacityExceeded, $outcome->problem);
            } elseif ($scenario === 'none_refusal') {
                try {
                    $runner->run(
                        $this->spec(
                            kind: 'test.resident.none_refusal',
                            key: "resident-none-{$opNumber}",
                            digest: 'sha256:'.hash('sha256', "resident-none-{$opNumber}"),
                            seller: $sellerRef,
                        ),
                        function (TransactionContext $ctx) use ($sellerRef, $opNumber, $connection, &$capturedContext): Receipt {
                            $capturedContext = $ctx;
                            $this->assertInTxCallerScope($ctx, $connection, $sellerRef);
                            self::effect($ctx, "resident-none-{$opNumber}");

                            throw new ProblemException(ProblemCode::ValidationInvalid, "none refusal {$opNumber}");
                        },
                    );
                    self::fail("Expected ProblemException for {$scenario}");
                } catch (ProblemException $exception) {
                    self::assertSame(ProblemCode::ValidationInvalid, $exception->problem);
                }
            } elseif ($scenario === 'auth_scope_mismatch') {
                $otherSeller = $sellers[($index + 1) % count($sellers)];
                try {
                    $runner->run(
                        $this->spec(
                            kind: 'test.resident.auth_mismatch',
                            key: "resident-auth-{$opNumber}",
                            digest: 'sha256:'.hash('sha256', "resident-auth-{$opNumber}"),
                            seller: $otherSeller,
                        ),
                        static fn (TransactionContext $ctx): Receipt => self::receipt(),
                    );
                    self::fail('Expected AuthDenied when OperationSpec seller differs from CallerContext');
                } catch (ProblemException $exception) {
                    self::assertSame(ProblemCode::AuthDenied, $exception->problem);
                }
            } elseif ($scenario === 'lock_plan_retry') {
                $attempts = 0;
                $outcome = $runner->run(
                    $this->spec(
                        kind: 'test.resident.retry',
                        key: "resident-retry-{$opNumber}",
                        digest: 'sha256:'.hash('sha256', "resident-retry-{$opNumber}"),
                        seller: $sellerRef,
                    ),
                    function (TransactionContext $ctx) use ($sellerRef, $opNumber, $connection, &$attempts, &$capturedContext): Receipt {
                        $capturedContext = $ctx;
                        $attempts++;
                        $this->assertInTxCallerScope($ctx, $connection, $sellerRef);
                        if ($attempts === 1) {
                            throw new LockPlanChanged("simulated lock plan change {$opNumber}");
                        }
                        self::assertSame(2, $ctx->attempt());
                        self::effect($ctx, "resident-retry-ok-{$opNumber}");

                        return self::receipt(['op' => $opNumber, 'attempts' => $attempts]);
                    },
                );
                self::assertTrue($outcome->accepted);
                self::assertSame(2, $attempts);
                $acceptedWrites++;
            } elseif ($scenario === 'uncaught_exception') {
                try {
                    $runner->run(
                        $this->spec(
                            kind: 'test.resident.exception',
                            key: "resident-exc-{$opNumber}",
                            digest: 'sha256:'.hash('sha256', "resident-exc-{$opNumber}"),
                            seller: $sellerRef,
                        ),
                        function (TransactionContext $ctx) use ($sellerRef, $opNumber, $connection, &$capturedContext): Receipt {
                            $capturedContext = $ctx;
                            $this->assertInTxCallerScope($ctx, $connection, $sellerRef);
                            self::effect($ctx, "resident-exc-{$opNumber}");

                            throw new RuntimeException("uncaught failure {$opNumber}");
                        },
                    );
                    self::fail("Expected RuntimeException for {$scenario}");
                } catch (RuntimeException $exception) {
                    self::assertSame("uncaught failure {$opNumber}", $exception->getMessage());
                }
            } elseif ($scenario === 'dispatch_cycle') {
                $dispatchSeq++;
                $cycleFixture = $this->seedDispatchFixture($sellerRef, 100 + $dispatchSeq, $store, $registry, $host, seedMerchant: false);
                $ticket = $authority->prepare($cycleFixture['request']);
                self::assertCount(1, $this->verifiedMap($authorityVerifiedProp, $authority));

                $decision = null;
                $plan = $store->plan($cycleFixture['operation']->binding->scope, $cycleFixture['operation']->operationRef);
                $outcome = $runner->run(
                    $this->spec(
                        kind: 'execution.resident_dispatch',
                        key: "resident-dispatch-{$opNumber}",
                        digest: 'sha256:'.hash('sha256', "resident-dispatch-{$opNumber}"),
                        modules: ['execution', 'platform'],
                        seller: $sellerRef,
                        financialScope: new FinancialScope(self::INSTALLATION, $sellerRef, 'test', 'source-a', 'remote-billium'),
                    ),
                    function (TransactionContext $ctx) use ($sellerRef, $connection, $store, $plan, $authority, $ticket, $cycleFixture, &$decision, &$capturedContext): Receipt {
                        $capturedContext = $ctx;
                        $this->assertInTxCallerScope($ctx, $connection, $sellerRef);
                        $store->lock($ctx, $plan);
                        $decision = $authority->validate($ctx, $ticket, $cycleFixture['operation'], $cycleFixture['attempt']);
                        self::assertTrue($store->startDispatch($ctx, $decision));

                        return self::receipt(['dispatched' => $cycleFixture['operation']->operationRef]);
                    },
                );
                self::assertTrue($outcome->accepted);
                self::assertInstanceOf(DispatchDecision::class, $decision);

                $lookup = $authority->send(
                    $ticket,
                    $this->driverRequest($cycleFixture['operation'], $decision, $cycleFixture['request'], $cycleFixture['cap']),
                    static fn (): ProviderLookupResult => new ProviderLookupResult(
                        ProviderLookupState::Ambiguous,
                        null,
                        null,
                        null,
                        "resident-dispatch-{$opNumber}",
                        null,
                    ),
                );
                self::assertSame(ProviderLookupState::Ambiguous, $lookup->state);
            }

            // Per-call post-conditions: 0 leaks of transactions, $current, caller GUC scope, or cached tickets.
            $this->assertCleanStateAfterCall($connection, $runner, $runnerCurrentProp, $authority, $authorityVerifiedProp, $capturedContext, $opNumber);
        }

        // End-of-sequence assertions across all 20 calls.
        self::assertSame($acceptedWrites, $this->rows('billium_test_effect'));
        $this->assertCleanStateAfterCall($connection, $runner, $runnerCurrentProp, $authority, $authorityVerifiedProp, null, 20);
    }

    private function assertCleanStateAfterCall(
        Connection $connection,
        DatabaseOperationRunner $runner,
        ReflectionProperty $runnerCurrentProp,
        DispatchAuthority $authority,
        ReflectionProperty $authorityVerifiedProp,
        ?TransactionContext $capturedContext,
        int $step,
    ): void {
        self::assertSame(0, $connection->transactionLevel(), "Step {$step}: transactionLevel must be 0");
        self::assertFalse($connection->getPdo()->inTransaction(), "Step {$step}: PDO must not be inTransaction");
        self::assertNull($runnerCurrentProp->getValue($runner), "Step {$step}: DatabaseOperationRunner::\$current leaked");

        if ($capturedContext !== null) {
            try {
                $runner->assertCurrent($capturedContext);
                self::fail("Step {$step}: stale TransactionContext was accepted after run()");
            } catch (LogicException $exception) {
                self::assertStringContainsString('Stale or foreign TransactionContext', $exception->getMessage());
            }
        }

        $gucSeller = self::field($connection->selectOne("SELECT nullif(current_setting('billium.seller_ref', true), '') AS v"), 'v');
        $gucEnv = self::field($connection->selectOne("SELECT nullif(current_setting('billium.environment', true), '') AS v"), 'v');
        $gucInst = self::field($connection->selectOne("SELECT nullif(current_setting('billium.installation_ref', true), '') AS v"), 'v');
        self::assertNull($gucSeller, "Step {$step}: billium.seller_ref GUC leaked outside Tx");
        self::assertNull($gucEnv, "Step {$step}: billium.environment GUC leaked outside Tx");
        self::assertNull($gucInst, "Step {$step}: billium.installation_ref GUC leaked outside Tx");

        self::assertSame([], $this->verifiedMap($authorityVerifiedProp, $authority), "Step {$step}: DispatchAuthority::\$verified leaked");
    }

    private function assertInTxCallerScope(TransactionContext $ctx, Connection $connection, string $expectedSeller): void
    {
        self::assertSame($expectedSeller, $ctx->caller()->sellerRef);
        $gucSeller = self::field($connection->selectOne("SELECT current_setting('billium.seller_ref', true) AS v"), 'v');
        self::assertSame($expectedSeller, $gucSeller);
    }

    private function switchCaller(string $sellerRef): void
    {
        $this->callers->set(new Caller($sellerRef, 'test', self::INSTALLATION, 'actor-'.$sellerRef, ['remote-billium'], ['source-a']));
        SimulatedFinancialAuthorityEvidence::admitCurrent($this->application());
    }

    /** @return array<array-key, mixed> */
    private function verifiedMap(ReflectionProperty $prop, DispatchAuthority $authority): array
    {
        $value = $prop->getValue($authority);
        self::assertIsArray($value);

        return $value;
    }

    /**
     * @return array{operation: OperationRecord, attempt: AttemptRecord, request: DispatchRequest, cap: CapabilitySnapshot}
     */
    private function seedDispatchFixture(
        string $sellerRef,
        int $seq,
        ExecutionStore $store,
        DatabaseMerchantRegistry $registry,
        DeterministicWriterHost $host,
        bool $seedMerchant = true,
    ): array {
        $this->switchCaller($sellerRef);
        $scope = new ExecutionScope(self::INSTALLATION, $sellerRef, 'test', 'source-a');
        $financialScope = new FinancialScope(self::INSTALLATION, $sellerRef, 'test', 'source-a', 'remote-billium');
        $cap = new CapabilitySnapshot(
            "cap-{$sellerRef}",
            'driver-a',
            'driver.v1',
            'cap.v1',
            "account-{$sellerRef}",
            [EffectKind::Charge, EffectKind::Authorize, EffectKind::Capture, EffectKind::Refund, EffectKind::VoidAuthorization],
            ['RUB'],
            ProviderEvidenceMode::StableEffectId,
            true,
            true,
            true,
            true,
            true,
            false,
            false,
            null,
            QualificationMode::Simulated,
            'fixture-qualification',
            'sha256:'.str_repeat('a', 64),
            new CapabilityDetails('details.v1', true, false, 'account/reference', 86400, true, 'eventual', 86400, CaptureMode::PartialMultiple, true, true, true, true, true, true, true, true, true, false, false, false, 'account/reference', 'stable.v1', 'manifest-a'),
        );
        $merchant = new MerchantRevision(
            "merchant-{$sellerRef}",
            $scope,
            "account-{$sellerRef}",
            'provider-a',
            'driver-a',
            'driver.v1',
            $cap->snapshotRef,
            'sha256:'.str_repeat('a', 64),
            new SecretRef('secret://vault/credential_a'),
            'credential-v1',
            ['RUB'],
            'sha256:'.str_repeat('b', 64),
            new DateTimeImmutable('2026-01-01T00:00:00Z'),
            new MerchantRevisionPolicy(new DateTimeImmutable('2026-01-01T00:00:00Z'), null, null, 'api-v1', 'webhook-a', 'continuity-root-a', 'settlement-'.$sellerRef, 'mandate-'.$sellerRef),
        );

        $runner = $this->runner();
        if ($seedMerchant) {
            $runner->run(
                $this->spec(
                    kind: 'execution.seed_merchant',
                    mode: FinancialMode::Administrative,
                    class: ModuleOperationClass::HistoricalRead,
                    modules: ['execution', 'platform'],
                    seller: $sellerRef,
                    financialScope: $financialScope,
                ),
                static function (TransactionContext $ctx) use ($store, $registry, $merchant, $cap, $scope): Receipt {
                    $store->appendMerchantRevision($ctx, $merchant, $cap);
                    $registry->appendLifecycle($ctx, $scope, $merchant->revisionRef, 'enable-new', 'enable_new', 'new', new DateTimeImmutable('-1 day'), null, 'fixture', 'authority-fixture');

                    return self::receipt();
                },
            );
        }

        $rootRef = "root-{$sellerRef}-{$seq}";
        $attemptRef = "attempt-{$sellerRef}-{$seq}";
        $providerRef = "provider-{$sellerRef}-{$seq}";
        $binding = new FrozenBinding(
            $scope,
            'remote-billium',
            "intent-{$sellerRef}-{$seq}",
            $rootRef,
            $merchant->revisionRef,
            $merchant->merchantAccountRef,
            $merchant->driverId,
            $merchant->driverContractVersion,
            $cap->digest,
            'sha256:'.str_repeat('b', 64),
            'execution.intent.v1',
            null,
            'commerz',
        );

        $operation = null;
        $runner->run(
            $this->spec(
                kind: 'execution.seed_operation',
                modules: ['execution', 'platform'],
                seller: $sellerRef,
                financialScope: $financialScope,
            ),
            static function (TransactionContext $ctx) use ($store, $binding, $rootRef, $attemptRef, $providerRef, $seq, $seedMerchant, &$operation): Receipt {
                if ($seedMerchant) {
                    $store->transitionNamespace($ctx, $binding->scope, NamespaceState::Open, 0, 'complete-history');
                }
                $store->insertBinding($ctx, $binding);
                $operation = $store->insertOperation(
                    $ctx,
                    new AdmissionPlan(
                        $binding->scope,
                        $binding,
                        $rootRef,
                        EffectKind::Charge,
                        Money::parse('10.00', 'RUB'),
                        "command-{$binding->scope->sellerRef}-{$seq}",
                        $binding->semanticDigest,
                        'execution.intent.v1',
                        ['execution', 'platform'],
                        [],
                        null,
                    ),
                );
                $admittedAttempt = $store->insertAttempt($ctx, $operation, $attemptRef, $providerRef);
                $store->insertDispatchWork($ctx, $admittedAttempt, "dispatch-{$binding->scope->sellerRef}-{$seq}", 'dispatch.v1');

                return self::receipt();
            },
        );
        self::assertInstanceOf(OperationRecord::class, $operation);

        $attempt = null;
        $runner->run(
            $this->spec(
                kind: 'execution.claim_attempt',
                modules: ['execution', 'platform'],
                seller: $sellerRef,
                financialScope: $financialScope,
            ),
            static function (TransactionContext $ctx) use ($store, $binding, $rootRef, $attemptRef, &$attempt): Receipt {
                $store->lock($ctx, $store->plan($binding->scope, $rootRef));
                $attempt = $store->claimAttempt($ctx, $attemptRef, 'worker-resident', new DateTimeImmutable('+5 minutes'));

                return self::receipt();
            },
        );
        self::assertInstanceOf(AttemptRecord::class, $attempt);

        $request = new DispatchRequest(
            $binding->scope,
            $rootRef,
            $attemptRef,
            $providerRef,
            EffectKind::Charge,
            $operation->requested,
            AuthorityClaims::bindingDigest($binding),
            $cap->digest,
            'owner-1',
            1,
            1,
            1,
            1,
            $attempt->leaseFence,
            'billium.psp_dispatch.v1',
        );
        $host->openSession($request);

        return ['operation' => $operation, 'attempt' => $attempt, 'request' => $request, 'cap' => $cap];
    }

    private function driverRequest(
        OperationRecord $operation,
        DispatchDecision $decision,
        DispatchRequest $request,
        CapabilitySnapshot $cap,
    ): DriverRequest {
        return new DriverRequest(
            $decision,
            $operation->binding,
            $cap,
            $operation->requested,
            $request->providerReference,
            new ResolvedCredentials(
                $operation->binding->merchantAccountRef,
                'credential-v1',
                new SecretRef('secret://vault/credential_a'),
                'charge',
                new DateTimeImmutable('+5 minutes'),
                'simulated-continuity',
            ),
        );
    }
}
