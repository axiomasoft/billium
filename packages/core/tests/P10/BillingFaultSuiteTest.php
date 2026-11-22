<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P10;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\AuthorizationCommands;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\OriginDecision;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\OutcomeDeliveryTest;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\AuthorizationLifecycleTest;
use Billium\Execution\Tests\Feature\CallbackCustodyTest;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Faults\ConservationVerifier;
use Billium\Testkit\Faults\SeededFaultPlan;
use Billium\Testkit\Fixtures\DurableJournal;
use Billium\Testkit\Fixtures\FixtureClock;
use Billium\Testkit\Oracles\IndependentFinancialOracle;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
#[Group('P10.2')]
#[Group('D71')]
#[Group('D72')]
final class BillingFaultSuiteTest extends ExecutionTestCase
{
    public function test_seeded_failpoint_and_unknown_crash_outcome_never_become_terminal_failure(): void
    {
        $planA = SeededFaultPlan::forScenario('P10.2-S1-UNKNOWN-CRASH', 20261014, [
            'before_accept' => 1,
            'after_accept' => 1,
        ]);
        $planB = SeededFaultPlan::forScenario('P10.2-S1-UNKNOWN-CRASH', 20261014, [
            'before_accept' => 1,
            'after_accept' => 1,
        ]);
        self::assertSame($planA->splitAmount(10000, 3), $planB->splitAmount(10000, 3));

        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            $scope = Fixture::scope();
            Fixture::seedMerchant($app);
            Fixture::host(
                $app,
                $path,
                mode: ResponseMode::AcceptedThenTimeout,
                checkpoint: static function (string $stage) use ($planA): void {
                    $planA->trigger($stage);
                },
            );

            $app->make(RootPaymentCommands::class)->admit(
                $scope,
                Fixture::wire(['money' => ['amount' => '100.00', 'currency' => 'RUB']]),
            )->orThrow();

            $worker = $app->make(AttemptWorker::class);
            $attemptRef = UnknownResultTest::attemptRef();
            $dispatched = $worker->dispatch($scope, $attemptRef, 'fault-worker-1', new DateTimeImmutable('+1 second'));

            // Timeout/crash after provider accept MUST remain unknown, never terminal failure.
            self::assertSame('unknown', $dispatched->body['status']);
            self::assertSame(AttemptState::Unknown, $app->make(ExecutionStore::class)->attempt($scope, $attemptRef)?->state);
            self::assertSame(1, $this->rows('billium_execution_dispatch_work', "state='due'"));
            self::assertSame(0, $this->rows('billium_execution_provider_fact'));
            self::assertSame(['after_accept' => 1, 'before_accept' => 1], $planA->triggeredCounts());

            // Lease expires -> recovery performs lookup only, never a second provider create.
            usleep(1_100_000);
            $recovered = $worker->recover($scope, $attemptRef, 'recovery-worker-1', new DateTimeImmutable('+1 minute'));
            self::assertSame('pending', $recovered->body['status']);
            self::assertSame(1, $this->rows('billium_execution_provider_work', "state='due'"));

            $workRef = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE state='due'"));
            $reduced = $app->make(EvidenceReducer::class)->reduce($workRef);
            self::assertSame(ReductionDisposition::Applied, $reduced->disposition);

            $operation = $app->make(ExecutionStore::class)->operation($scope, 'root-a');
            self::assertNotNull($operation);

            $report = (new ConservationVerifier)->verify(
                $app->make(DatabaseManager::class)->connection(),
                new DurableJournal($path),
                $operation->binding,
                'root-a',
            );

            self::assertSame(1, $report['providerCalls']);
            self::assertSame(1, $report['providerLookups']);
            self::assertSame(1, $report['providerRecords']);
            self::assertSame(1, $report['attempts']);
            self::assertSame(1, $report['providerFacts']);
            self::assertSame(10000, $report['capacity']['captured']);
            self::assertSame(10000, $report['oracle']['captured']);
        } finally {
            self::cleanup($path);
        }
    }

    public function test_duplicate_stale_conflicting_and_forged_callbacks_preserve_confirmed_state(): void
    {
        $plan = SeededFaultPlan::forScenario('P10.2-S2-CALLBACK-FAULTS', 20261015);
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            $scope = Fixture::scope();
            self::registerExecutionEvents($app);
            $this->seedObligationAndIntent('debt-s2', 10000, 'intent-a');

            Fixture::seedMerchant($app);
            Fixture::host($app, $path);
            $app->make(RootPaymentCommands::class)->admit(
                $scope,
                Fixture::wire(['money' => ['amount' => '100.00', 'currency' => 'RUB']]),
            )->orThrow();

            $reduced = AuthorizationLifecycleTest::dispatchAndReduce($app, 'root-a');
            self::assertCount(1, $reduced->observationCommandRefs);
            OutcomeDeliveryTest::attest($app, $path, $reduced->observationCommandRefs[0]);
            self::assertSame(1, OutcomeDeliveryTest::relay($app, $path)->delivered);

            $evidence = CallbackCustodyTest::providerInput($app, 'root-a');

            // 1. Duplicate callback delivery -> Duplicate disposition, zero extra facts/calls.
            $plan->recordEvent('duplicate_callback');
            $dup = AuthorizationLifecycleTest::receive($app, 'root-a', $evidence);
            self::assertSame(ReductionDisposition::Duplicate, $dup->disposition);

            // 2. Stale lower-revision callback -> Stale disposition, confirmed state untouched.
            $plan->recordEvent('stale_callback');
            $stale = CallbackCustodyTest::signed($evidence, ['revision' => '0']);
            self::assertSame(ReductionDisposition::Stale, AuthorizationLifecycleTest::receive($app, 'root-a', $stale)->disposition);

            // 3. Conflicting callback claiming terminal 'declined' -> quarantined, confirmed capture protected.
            $plan->recordEvent('conflicting_callback');
            $conflicting = CallbackCustodyTest::signed($evidence, ['status' => 'declined']);
            $quarantined = $app->make(CallbackCustody::class)->receive($scope, 'merchant-a', null, $conflicting)->orThrow();
            self::assertSame('custody_quarantined', $quarantined->body['status']);

            // 4. Forged signature callback with secret payload -> AuthDenied, zero secret retention.
            $plan->recordEvent('forged_callback');
            $forged = new ProviderEvidence(
                $evidence->evidenceRef,
                $evidence->scope,
                $evidence->providerId,
                $evidence->merchantAccountRef,
                $evidence->providerEventId,
                $evidence->payloadDigest,
                $evidence->signatureVerdict,
                $evidence->handlerContractVersion,
                $evidence->receivedAt,
                ['material' => $evidence->sanitizedPayload['material'], 'fixtureSignature' => 'invalid', 'api_key' => 'secret-leak-guard'],
                $evidence->securePayloadRef,
            );
            try {
                $app->make(CallbackCustody::class)->receive($scope, 'merchant-a', 'root-a', $forged);
                self::fail('Forged callback must be refused');
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::AuthDenied, $error->problem);
            }
            self::assertSame(0, $this->rows('billium_execution_provider_evidence', "sanitized_payload::text LIKE '%secret-leak-guard%'"));

            $operation = $app->make(ExecutionStore::class)->operation($scope, 'root-a');
            self::assertNotNull($operation);

            $report = (new ConservationVerifier)->verify(
                $app->make(DatabaseManager::class)->connection(),
                new DurableJournal($path),
                $operation->binding,
                'root-a',
                'debt-s2',
            );

            self::assertSame(1, $report['providerCalls']);
            self::assertSame(1, $report['providerFacts']);
            self::assertSame(1, $report['allocationReceipts']);
            self::assertSame(['captured' => 10000, 'refunded' => 0, 'allocated' => 10000], $report['payment']);
            self::assertSame(['amountDue' => 10000, 'allocated' => 10000, 'open' => 0], $report['obligation']);
            self::assertSame(4, array_sum($plan->triggeredCounts()));
        } finally {
            self::cleanup($path);
        }
    }

    public function test_ttl_expiry_idempotency_conflicts_and_duplicate_delivery_remain_safe(): void
    {
        $plan = SeededFaultPlan::forScenario('P10.2-S3-TTL-IDEMPOTENCY', 20261016);
        $app = $this->application();
        $path = Fixture::tempPath();
        $pathDelivery = Fixture::tempPath();
        try {
            $scope = Fixture::scope();
            $cap = SimulatedCapabilities::snapshot(idempotencySeconds: 1, visibility: 'eventual', completeInventory: false, absence: false);
            $clock = new FixtureClock(new DateTimeImmutable);
            Fixture::seedMerchant($app, $cap);
            Fixture::host($app, $path, cap: $cap, mode: ResponseMode::AcceptedThenTimeout, clock: $clock);

            $commands = $app->make(RootPaymentCommands::class);
            $wire = Fixture::wire(['commandKey' => Fixture::key(101), 'money' => ['amount' => '50.00', 'currency' => 'RUB']]);
            $first = $commands->admit($scope, $wire)->orThrow();

            // 1. Exact duplicate commandKey replays original receipt without new operation/send.
            $plan->recordEvent('command_replay');
            $replay = $commands->admit($scope, $wire)->orThrow();
            self::assertTrue($replay->replayed);
            self::assertSame($first->receiptId, $replay->receiptId);
            self::assertSame('root-a', $replay->body['operationRef']);

            // 2. Same commandKey with mutated money digest -> deterministic IdempotencyConflict.
            $plan->recordEvent('command_conflict');
            try {
                $commands->admit(
                    $scope,
                    Fixture::wire(['commandKey' => Fixture::key(101), 'money' => ['amount' => '75.00', 'currency' => 'RUB']]),
                )->orThrow();
                self::fail('Mutated payload for same commandKey must fail with IdempotencyConflict');
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::IdempotencyConflict, $error->problem);
            }

            // 3. New commandKey for same business intent -> business-binding dedupe returns root-a.
            $plan->recordEvent('business_binding_replay');
            $sameIntent = $commands->admit(
                $scope,
                Fixture::wire(['commandKey' => Fixture::key(102), 'money' => ['amount' => '50.00', 'currency' => 'RUB']]),
            )->orThrow();
            self::assertSame($first->body['receiptRef'], $sameIntent->body['receiptRef']);

            // 4. Provider TTL expiry + eventual lookup never becomes certified absence or new create.
            $worker = $app->make(AttemptWorker::class);
            $attemptRef = UnknownResultTest::attemptRef();
            self::assertSame('unknown', $worker->dispatch($scope, $attemptRef, 'ttl-worker', new DateTimeImmutable('+1 second'))->body['status']);
            $clock->advance(2);
            usleep(1_100_000);
            $plan->recordEvent('ttl_expired_recover');
            self::assertSame('unknown', $worker->recover($scope, $attemptRef, 'ttl-recovery', new DateTimeImmutable('+20 seconds'))->body['status']);
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.lookup'));
            self::assertSame(1, $this->rows('billium_execution_attempt', "state='unknown'"));
            self::assertSame(0, $this->rows('billium_execution_provider_fact'));
            self::assertSame(0, $this->rows('billium_execution_provider_evidence', "signature_verdict='verified_no_effect'"));

            // 5. Duplicate ledger observation delivery produces exactly one allocation & receipt.
            self::registerExecutionEvents($app);
            $this->seedObligationAndIntent('debt-s3', 6000, 'intent-b');
            Fixture::host(
                $app,
                $pathDelivery,
                [new OriginDecision($scope, 'root-b', 'intent-b', 'checkout', 'remote-billium', 1, null, 'sha256:'.hash('sha256', 'order-b'), 'terms-v1', 'period-b')],
                $cap,
                mode: ResponseMode::Success,
            );
            $commands->admit(
                $scope,
                Fixture::wire([
                    'commandKey' => Fixture::key(103),
                    'operationRef' => 'root-b',
                    'source' => ['context' => 'checkout', 'intentRef' => 'intent-b', 'orderRef' => 'order-b'],
                    'money' => ['amount' => '60.00', 'currency' => 'RUB'],
                ]),
            )->orThrow();

            $reduced = AuthorizationLifecycleTest::dispatchAndReduce($app, 'root-b');
            self::assertCount(1, $reduced->observationCommandRefs);
            $commandRef = $reduced->observationCommandRefs[0];
            OutcomeDeliveryTest::attest($app, $pathDelivery, $commandRef);
            self::assertSame(1, OutcomeDeliveryTest::relay($app, $pathDelivery)->delivered);

            // Replay exact same observation command twice directly into receiver.
            $plan->recordEvent('duplicate_observation_delivery');
            OutcomeDeliveryTest::duplicateDelivery($app, $commandRef);
            OutcomeDeliveryTest::duplicateDelivery($app, $commandRef);

            self::assertSame(1, $this->rows('billium_allocation'));
            self::assertSame(1, $this->rows('billium_allocation_receipt', "kind='payment_observation'"));
            self::assertSame(1, $this->rows('billium_payment', "operation_ref='root-b' AND captured_minor=6000 AND allocated_minor=6000"));
            self::assertSame(1, UnknownResultTest::events($pathDelivery, 'provider.call'));
        } finally {
            self::cleanup($path);
            self::cleanup($pathDelivery);
        }
    }

    public function test_seeded_authorize_capture_void_refund_and_allocation_conservation_invariants(): void
    {
        $plan = SeededFaultPlan::forScenario('P10.2-S4-CONSERVATION', 20261017);
        [$cap1, $cap2, $voidPart] = $plan->splitAmount(10000, 3, 1000);
        $capturedTotal = $cap1 + $cap2;
        [$ref1, $ref2, $keepPart] = $plan->splitAmount($capturedTotal, 3, 500);
        $refundedTotal = $ref1 + $ref2;

        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            $scope = Fixture::scope();
            self::registerExecutionEvents($app);
            $this->seedObligationAndIntent('debt-s4', 10000, 'intent-a');

            Fixture::seedMerchant($app);
            Fixture::host($app, $path);
            $app->make(RootPaymentCommands::class)->admit(
                $scope,
                Fixture::wire(['schema' => 'payment.authorize.v1', 'money' => ['amount' => '100.00', 'currency' => 'RUB']]),
            )->orThrow();

            $authReduced = AuthorizationLifecycleTest::dispatchAndReduce($app, 'root-a');
            OutcomeDeliveryTest::attest($app, $path, $authReduced->observationCommandRefs[0]);
            self::assertSame(1, OutcomeDeliveryTest::relay($app, $path)->delivered);

            $authCommands = $app->make(AuthorizationCommands::class);
            foreach (['cap-1' => $cap1, 'cap-2' => $cap2] as $ref => $minor) {
                $authCommands->admit($scope, AuthorizationLifecycleTest::child($ref, $minor))->orThrow();
                $reduced = AuthorizationLifecycleTest::dispatchAndReduce($app, $ref);
                OutcomeDeliveryTest::attest($app, $path, $reduced->observationCommandRefs[0]);
                self::assertSame(1, OutcomeDeliveryTest::relay($app, $path)->delivered);
            }

            // Over-capacity capture must be rejected before reservation or send.
            $plan->recordEvent('over_capture_rejected');
            self::assertSame(
                ProblemCode::CaptureCapacityExceeded,
                $authCommands->admit($scope, AuthorizationLifecycleTest::child('cap-overflow', $voidPart + 1))->problem,
            );

            // Release exact remaining authorization.
            $authCommands->admit($scope, AuthorizationLifecycleTest::child('void-rem', $voidPart, true))->orThrow();
            AuthorizationLifecycleTest::dispatchAndReduce($app, 'void-rem');

            // Partial refunds within captured capacity succeed; overflow rejected.
            $refundCommands = $app->make(RefundCommands::class);
            foreach ([['ref-1', $ref1, 201], ['ref-2', $ref2, 202]] as [$refOp, $minor, $key]) {
                $refundCommands->admit($scope, RefundCapacityTest::refund($refOp, $minor, $key))->orThrow();
                AuthorizationLifecycleTest::dispatchAndReduce($app, $refOp);
            }

            $plan->recordEvent('over_refund_rejected');
            self::assertSame(
                ProblemCode::RefundCapacityExceeded,
                $refundCommands->admit($scope, RefundCapacityTest::refund('ref-overflow', $keepPart + 1, 203))->problem,
            );

            $operation = $app->make(ExecutionStore::class)->operation($scope, 'root-a');
            self::assertNotNull($operation);

            $journal = new DurableJournal($path);
            $report = (new ConservationVerifier)->verify(
                $app->make(DatabaseManager::class)->connection(),
                $journal,
                $operation->binding,
                'root-a',
                'debt-s4',
            );

            self::assertSame(6, $report['providerCalls']);
            self::assertSame(6, $report['providerRecords']);
            self::assertSame(6, $report['attempts']);
            self::assertSame(6, $report['providerFacts']);
            self::assertSame(
                ['authorized' => 10000, 'captured' => $capturedTotal, 'refunded' => $refundedTotal, 'voided' => $voidPart],
                $report['oracle'],
            );
            self::assertSame($capturedTotal, $report['payment']['captured'] ?? null);
            self::assertSame($capturedTotal, $report['payment']['allocated'] ?? null);
            self::assertSame($voidPart, $report['obligation']['open'] ?? null);
            self::assertSame(5, $this->rows('billium_execution_reservation', "state='consumed'"));

            $oracle = new IndependentFinancialOracle;
            $oracle->ingestProvider($journal);
            $oracle->assertCallCount($journal, 'root-a', 1);
            $oracle->assertSendCount($journal, 'cap-1', 1);
            $oracle->assertSendCount($journal, 'cap-2', 1);
            $oracle->assertSendCount($journal, 'void-rem', 1);
            $oracle->assertSendCount($journal, 'ref-1', 1);
            $oracle->assertSendCount($journal, 'ref-2', 1);
        } finally {
            self::cleanup($path);
        }
    }

    public function test_unknown_refund_holds_capacity_and_provider_effect_counts_match_receipts(): void
    {
        $plan = SeededFaultPlan::forScenario('P10.2-S5-UNKNOWN-REFUND-CAPACITY', 20261018);
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            $scope = Fixture::scope();
            self::registerExecutionEvents($app);
            $this->seedObligationAndIntent('debt-s5', 10000, 'intent-a');

            Fixture::seedMerchant($app);
            Fixture::host($app, $path);
            $app->make(RootPaymentCommands::class)->admit(
                $scope,
                Fixture::wire(['money' => ['amount' => '100.00', 'currency' => 'RUB']]),
            )->orThrow();
            $chargeReduced = AuthorizationLifecycleTest::dispatchAndReduce($app, 'root-a');
            OutcomeDeliveryTest::attest($app, $path, $chargeReduced->observationCommandRefs[0]);
            self::assertSame(1, OutcomeDeliveryTest::relay($app, $path)->delivered);

            // Inject timeout after provider accepts first partial refund (6000 minor).
            Fixture::host($app, $path, mode: ResponseMode::AcceptedThenTimeout);
            $refunds = $app->make(RefundCommands::class);
            $refunds->admit($scope, RefundCapacityTest::refund('ref-unknown', 6000, 301))->orThrow();
            $worker = $app->make(AttemptWorker::class);
            $unknownAttempt = PaymentIdentity::reference($scope, 'ref-unknown', 'attempt');
            $plan->recordEvent('refund_accepted_then_timeout');
            self::assertSame('unknown', $worker->dispatch($scope, $unknownAttempt, 'ref-worker-1', new DateTimeImmutable('+1 second'))->body['status']);

            // Unknown refund MUST keep holding 6000 capacity: 5000 exceeds remaining 4000.
            $plan->recordEvent('unknown_holds_capacity');
            self::assertSame(
                ProblemCode::RefundCapacityExceeded,
                $refunds->admit($scope, RefundCapacityTest::refund('ref-over', 5000, 302))->problem,
            );
            // Exact remaining 4000 fits.
            $refunds->admit($scope, RefundCapacityTest::refund('ref-fit', 4000, 303))->orThrow();
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=10000 AND confirmed_refund_minor=0 AND held_refund_minor=10000'));

            // Recover unknown refund via lookup (zero duplicate provider send), then dispatch ref-fit cleanly.
            usleep(1_100_000);
            Fixture::host($app, $path, mode: ResponseMode::Success);
            self::assertSame('pending', $worker->recover($scope, $unknownAttempt, 'ref-recovery', new DateTimeImmutable('+1 minute'))->body['status']);
            $workRef = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint='ref-unknown' AND state='due'"));
            self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce($workRef)->disposition);

            AuthorizationLifecycleTest::dispatchAndReduce($app, 'ref-fit');

            $operation = $app->make(ExecutionStore::class)->operation($scope, 'root-a');
            self::assertNotNull($operation);

            $report = (new ConservationVerifier)->verify(
                $app->make(DatabaseManager::class)->connection(),
                new DurableJournal($path),
                $operation->binding,
                'root-a',
                'debt-s5',
            );

            self::assertSame(3, $report['providerCalls']);
            self::assertSame(1, $report['providerLookups']);
            self::assertSame(3, $report['providerRecords']);
            self::assertSame(3, $report['attempts']);
            self::assertSame(3, $report['providerFacts']);
            self::assertSame(10000, $report['capacity']['captured']);
            self::assertSame(10000, $report['capacity']['refunded']);
            self::assertSame(0, $report['capacity']['heldRefund']);
            self::assertSame(1, $report['allocationReceipts']);
        } finally {
            self::cleanup($path);
        }
    }

    private function seedObligationAndIntent(string $obligationRef, int $minor, string $businessIntentRef): void
    {
        $ledger = $this->application()->make(LedgerParticipants::class);
        $account = new BillingAccountScope('seller-a', 'test', 'billing-a');
        $spec = $this->spec(financialScope: new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium'));
        $this->runner()->run($spec, static fn (TransactionContext $ctx) => $ledger->recordObligation(
            $ctx,
            new RecordObligation('rec-'.$obligationRef, $account, $obligationRef, 'basis-'.$obligationRef, 'buyer-a', 'component-a', new Money($minor, Currency::of('RUB')), 'sha256:'.str_repeat('f', 64)),
        ))->orThrow();
        $this->runner()->run($spec, static fn (TransactionContext $ctx) => $ledger->declareSettlementIntent(
            $ctx,
            new DeclareSettlementIntent('declare-'.$businessIntentRef, $account, $businessIntentRef, 'RUB', [$obligationRef]),
        ))->orThrow();
    }

    private static function registerExecutionEvents(Application $app): void
    {
        $registry = $app->make(ModuleRegistry::class);
        $registrar = new DatabaseEventContractRegistrar($app->make(DatabaseManager::class)->connection('billium_owner'), $registry);
        $descriptor = $registry->descriptor('execution');
        self::assertNotNull($descriptor);
        $registrar->register($registrar->plan($descriptor, true));
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
