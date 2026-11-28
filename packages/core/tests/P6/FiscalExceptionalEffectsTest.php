<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\Fiscal\FiscalDrivers;
use Billium\Execution\Contracts\Fiscal\FiscalOrchestrator;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Data\Fiscal\FiscalActualEffect;
use Billium\Execution\Data\Fiscal\FiscalExternalOutcome;
use Billium\Execution\Data\Fiscal\FiscalIntentRecord;
use Billium\Execution\Data\Fiscal\FiscalPolicyConfig;
use Billium\Execution\Data\Fiscal\FiscalProjectionInput;
use Billium\Execution\Data\Fiscal\FiscalProjectionRecord;
use Billium\Execution\Enums\Fiscal\FiscalIntentState;
use Billium\Execution\Enums\Fiscal\FiscalOperationKind;
use Billium\Execution\Enums\Fiscal\FiscalReceiptKind;
use Billium\Execution\Enums\Fiscal\FiscalRoute;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Internal\Fiscal\ActualEffectExtractor;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\AuthorizationLifecycleTest;
use Billium\Execution\Tests\Feature\CallbackCustodyTest;
use Billium\Execution\Tests\Feature\HistoricalMerchantRefundTest;
use Billium\Execution\Tests\Feature\InventoryReconciliationTest;
use Billium\Execution\Tests\Support\CertifiesAbsentRefund;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Fiscal\SimulatedFiscalDriver;
use Billium\Testkit\Fiscal\SimulatedFiscalDrivers;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
#[Group('ACC-RST-12')]
final class FiscalExceptionalEffectsTest extends ExecutionTestCase
{
    use CertifiesAbsentRefund;

    /**
     * Reproduces the real over-refund hold:
     * capture 100 (`root-a`), certified-absent refund `old` (70), paid replacement refund `replacement` (70),
     * then authenticated late provider evidence confirming `old` (70) also succeeded => actual refunds 140, applied 70, hold=true.
     */
    private function setupAccRst12OverRefundHold(string $path): void
    {
        $app = $this->application();
        $scope = Fixture::scope();
        Refunds::captured($app, $path);
        $commands = $app->make(RefundCommands::class);
        $commands->admit($scope, Refunds::refund('old', 70, 2))->orThrow();
        $this->certifyAbsentAndAdoptNewSession($path);
        self::assertSame(1, $this->rows('billium_execution_operation', "operation_ref='old' AND state='failed'"));

        $commands->admit($scope, Refunds::refund('replacement', 70, 3))->orThrow();
        $app->make(AttemptWorker::class)->dispatch($scope, Refunds::attemptRef('replacement'), 'replacement', new DateTimeImmutable('+1 minute'))->orThrow();
        $work = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint='replacement' AND state='due'"));
        $input = $app->make(ExecutionStore::class)->providerWork($work);
        self::assertNotNull($input);
        self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce($work)->disposition);

        $late = CallbackCustodyTest::signed($input->evidence, [
            'revision' => '18',
            'operationRef' => 'old',
            'stableEffectId' => 'late-paid-refund',
            'providerOperationId' => 'late-original-provider-operation',
            'reference' => self::text($this->value("SELECT provider_reference FROM billium_execution_attempt WHERE operation_ref='old'")),
            'refunded' => ['amount' => '1.40', 'currency' => 'RUB'],
            'operationAmount' => ['amount' => '0.70', 'currency' => 'RUB'],
        ]);
        InventoryReconciliationTest::grant($this->callers);
        InventoryReconciliationTest::enableInventory($app, $path);
        InventoryReconciliationTest::page($path, [$late]);
        InventoryReconciliationTest::scan($app, 'fiscal-over-refund-review', new DateTimeImmutable('-1 hour'), new DateTimeImmutable);

        // Confirm real P5 over-refund hold state: confirmed_capture=100, bounded applied confirmed_refund=70, financial_hold=true.
        self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=70 AND held_refund_minor=0 AND financial_hold'));
        self::assertSame(1, $this->rows('billium_execution_operation_revision', "operation_ref='root-a' AND (observed->'refundedTotal'->>'minor')::int=140 AND (exposure->'overRefunded'->>'minor')::int=40"));
        self::assertSame(3, UnknownResultTest::events($path, 'provider.call'));
    }

    public function test_acc_rst_12_capture_100_and_two_actual_refunds_70_plus_70_yield_one_sale_and_two_refund_intents_totaling_140_stable_under_replay(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            $this->setupAccRst12OverRefundHold($path);

            $txProbe = static fn (): int => $app->make(DatabaseManager::class)->connection()->transactionLevel();
            $driver = new SimulatedFiscalDriver(
                id: 'cloud-fiscal',
                version: 'fiscal-driver.v1',
                supportedRoutes: [FiscalRoute::FiscalDriver],
                txLevelProbe: $txProbe,
            );
            $app->instance(FiscalDrivers::class, new SimulatedFiscalDrivers([$driver]));
            $orchestrator = $app->make(FiscalOrchestrator::class);

            foreach ([FiscalOperationKind::Charge, FiscalOperationKind::Refund] as $opKind) {
                $orchestrator->configurePolicy(new FiscalPolicyConfig(
                    scope: $scope,
                    executionProfile: 'remote-billium',
                    operationKind: $opKind,
                    receiptRequired: true,
                    fiscalRoute: FiscalRoute::FiscalDriver,
                    driverId: 'cloud-fiscal',
                    driverContractVersion: 'fiscal-driver.v1',
                ));
            }

            $intents = $orchestrator->syncActualEffectsForRoot($scope, 'root-a', 'remote-billium');
            self::assertCount(3, $intents);

            $sales = array_values(array_filter($intents, static fn (FiscalIntentRecord $i): bool => $i->receiptKind === FiscalReceiptKind::Sale));
            $refunds = array_values(array_filter($intents, static fn (FiscalIntentRecord $i): bool => $i->receiptKind === FiscalReceiptKind::Refund));

            self::assertCount(1, $sales);
            self::assertSame(100, $sales[0]->amount->minor);
            self::assertFalse($sales[0]->exceptional);

            // Two refund fiscal intents totaling 140 (NEVER suppressed or capped to bounded applied 70).
            self::assertCount(2, $refunds);
            $refundSum = array_sum(array_map(static fn (FiscalIntentRecord $i): int => $i->amount->minor, $refunds));
            self::assertSame(140, $refundSum);

            $exceptionalRefunds = array_values(array_filter($refunds, static fn (FiscalIntentRecord $i): bool => $i->exceptional));
            self::assertCount(1, $exceptionalRefunds);
            self::assertSame('old', $exceptionalRefunds[0]->operationRef);
            self::assertSame(70, $exceptionalRefunds[0]->amount->minor);
            self::assertArrayHasKey('discrepancyRef', $exceptionalRefunds[0]->exposureProvenance);
            self::assertArrayHasKey('exposure', $exceptionalRefunds[0]->exposureProvenance);

            $normalRefunds = array_values(array_filter($refunds, static fn (FiscalIntentRecord $i): bool => ! $i->exceptional));
            self::assertCount(1, $normalRefunds);
            self::assertSame('replacement', $normalRefunds[0]->operationRef);
            self::assertSame(70, $normalRefunds[0]->amount->minor);

            $baselineExternalIds = array_map(static fn (FiscalIntentRecord $i): string => $i->externalId, $intents);
            sort($baselineExternalIds, SORT_STRING);

            // Replay stability across ALL 6 permutations of the 3 actual effects.
            $extracted = $app->make(ActualEffectExtractor::class)->extractForRoot($scope, 'root-a', 'remote-billium');
            self::assertCount(3, $extracted);
            $permutations = [
                [0, 1, 2],
                [0, 2, 1],
                [1, 0, 2],
                [1, 2, 0],
                [2, 0, 1],
                [2, 1, 0],
            ];
            foreach ($permutations as $order) {
                $replayedIds = [];
                foreach ($order as $idx) {
                    $res = $orchestrator->prepareIntent($extracted[$idx]);
                    self::assertNotNull($res);
                    $replayedIds[] = $res->externalId;
                }
                sort($replayedIds, SORT_STRING);
                self::assertSame($baselineExternalIds, $replayedIds);
            }
            $resynced = $orchestrator->syncActualEffectsForRoot($scope, 'root-a', 'remote-billium');
            self::assertCount(3, $resynced);
            self::assertSame(3, $this->rows('billium_fiscal_intent'));

            // Dispatch all 3 fiscal intents; 0 duplicate registrations and 0 compensating PSP actions.
            foreach ($intents as $intent) {
                $done = $orchestrator->dispatchIntent($scope, $intent->intentRef);
                self::assertSame(FiscalIntentState::Registered, $done->state);
            }
            self::assertSame(3, $driver->registerCalls);
            self::assertSame(0, $driver->duplicateRegistrations);
            self::assertSame(3, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=70 AND financial_hold'));
        } finally {
            InventoryReconciliationTest::clear($path);
        }
    }

    public function test_unknown_second_refund_creates_zero_fiscal_intent_until_actual_provider_evidence_arrives(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            Refunds::captured($app, $path);

            $driver = new SimulatedFiscalDriver(
                id: 'cloud-fiscal',
                version: 'fiscal-driver.v1',
                supportedRoutes: [FiscalRoute::FiscalDriver],
            );
            $app->instance(FiscalDrivers::class, new SimulatedFiscalDrivers([$driver]));
            $orchestrator = $app->make(FiscalOrchestrator::class);
            foreach ([FiscalOperationKind::Charge, FiscalOperationKind::Refund] as $opKind) {
                $orchestrator->configurePolicy(new FiscalPolicyConfig(
                    scope: $scope,
                    executionProfile: 'remote-billium',
                    operationKind: $opKind,
                    receiptRequired: true,
                    fiscalRoute: FiscalRoute::FiscalDriver,
                    driverId: 'cloud-fiscal',
                    driverContractVersion: 'fiscal-driver.v1',
                ));
            }

            // First refund (40) succeeds.
            $refundCommands = $app->make(RefundCommands::class);
            $refundCommands->admit($scope, Refunds::refund('refund-1', 40, 2))->orThrow();
            $app->make(AttemptWorker::class)->dispatch($scope, Refunds::attemptRef('refund-1'), 'w-r1', new DateTimeImmutable('+1 minute'))->orThrow();
            $work1 = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint='refund-1' AND state='due'"));
            $input1 = $app->make(ExecutionStore::class)->providerWork($work1);
            self::assertNotNull($input1);
            self::assertSame(ReductionDisposition::Applied, $app->make(EvidenceReducer::class)->reduce($work1)->disposition);

            // Second refund (30) experiences timeout -> unknown state at PSP (no confirmed actual effect yet).
            $refundCommands->admit($scope, Refunds::refund('refund-2', 30, 3))->orThrow();
            Fixture::host($app, $path, mode: ResponseMode::Unavailable);
            $app->make(AttemptWorker::class)->dispatch($scope, Refunds::attemptRef('refund-2'), 'w-r2', new DateTimeImmutable('+1 minute'))->orThrow();
            self::assertSame(1, $this->rows('billium_execution_attempt', "operation_ref='refund-2' AND state='unknown'"));

            // Sync creates ONLY 1 sale (100) + 1 refund (40); 0 intent for unknown refund-2.
            $beforeEvidence = $orchestrator->syncActualEffectsForRoot($scope, 'root-a', 'remote-billium');
            self::assertCount(2, $beforeEvidence);
            $refundOps = array_values(array_map(
                static fn (FiscalIntentRecord $i): string => $i->operationRef,
                array_filter($beforeEvidence, static fn (FiscalIntentRecord $i): bool => $i->receiptKind === FiscalReceiptKind::Refund),
            ));
            self::assertSame(['refund-1'], $refundOps);

            // Direct prepareIntent with confirmedActual=false also creates 0 intent.
            self::assertNull($orchestrator->prepareIntent(new FiscalActualEffect(
                scope: $scope,
                executionProfile: 'remote-billium',
                economicEffectRef: 'effect:unconfirmed-refund-2',
                rootOperationRef: 'root-a',
                operationRef: 'refund-2',
                operationKind: FiscalOperationKind::Refund,
                receiptKind: FiscalReceiptKind::Refund,
                actualAmount: new Money(30, Currency::of('RUB')),
                merchantRevisionRef: 'merchant-a',
                merchantAccountRef: 'account-a',
                providerEvidenceRef: 'ev-unconfirmed',
                confirmedActual: false,
            )));
            self::assertSame(2, $this->rows('billium_fiscal_intent'));

            // Now authenticated actual provider evidence arrives confirming refund-2 (30) succeeded.
            Fixture::host($app, $path, mode: ResponseMode::Success);
            $confirmedR2 = CallbackCustodyTest::signed($input1->evidence, [
                'revision' => '9',
                'operationRef' => 'refund-2',
                'stableEffectId' => 'confirmed-refund-2-effect',
                'providerOperationId' => 'provider-op-refund-2',
                'reference' => self::text($this->value("SELECT provider_reference FROM billium_execution_attempt WHERE operation_ref='refund-2'")),
                'refunded' => ['amount' => '0.70', 'currency' => 'RUB'],
                'operationAmount' => ['amount' => '0.30', 'currency' => 'RUB'],
            ]);
            self::assertSame(ReductionDisposition::Applied, AuthorizationLifecycleTest::receive($app, 'refund-2', $confirmedR2)->disposition);

            // Now sync creates the 2nd refund intent for 30 (total 2 refund intents = 70).
            $afterEvidence = $orchestrator->syncActualEffectsForRoot($scope, 'root-a', 'remote-billium');
            self::assertCount(3, $afterEvidence);
            $refundIntents = array_values(array_filter($afterEvidence, static fn (FiscalIntentRecord $i): bool => $i->receiptKind === FiscalReceiptKind::Refund));
            self::assertCount(2, $refundIntents);
            self::assertSame(70, array_sum(array_map(static fn (FiscalIntentRecord $i): int => $i->amount->minor, $refundIntents)));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_embedded_commerz_records_two_refund_projections_for_acc_rst_12_and_sends_zero(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            $this->setupAccRst12OverRefundHold($path);

            $driver = new SimulatedFiscalDriver(
                id: 'unused-fiscal',
                version: 'fiscal-driver.v1',
                supportedRoutes: [FiscalRoute::FiscalDriver],
            );
            $app->instance(FiscalDrivers::class, new SimulatedFiscalDrivers([$driver]));
            $orchestrator = $app->make(FiscalOrchestrator::class);

            foreach ([FiscalOperationKind::Charge, FiscalOperationKind::Refund] as $opKind) {
                $orchestrator->configurePolicy(new FiscalPolicyConfig(
                    scope: $scope,
                    executionProfile: 'embedded-commerz',
                    operationKind: $opKind,
                    receiptRequired: true,
                    fiscalRoute: FiscalRoute::ProjectionOnly,
                ));
            }

            // embedded-commerz MUST refuse intent creation/dispatch.
            HistoricalMerchantRefundTest::problem(
                ProblemCode::CapabilityUnsupported,
                static fn () => $orchestrator->syncActualEffectsForRoot($scope, 'root-a', 'embedded-commerz'),
            );

            // Without authenticated external fiscal outcomes, embedded-commerz MUST NOT fabricate projections from payment facts.
            self::assertSame([], $orchestrator->syncActualProjectionsForRoot($scope, 'root-a', 'embedded-commerz'));
            self::assertSame(0, $this->rows('billium_fiscal_projection'));

            // Supply genuine authenticated external fiscal outcomes for capture (100) + two confirmed refunds (70 + 70).
            $extracted = $app->make(ActualEffectExtractor::class)->extractForRoot($scope, 'root-a', 'embedded-commerz');
            self::assertCount(3, $extracted);
            $externalOutcomes = [];
            foreach ($extracted as $effect) {
                $extRef = match ($effect->operationRef) {
                    'root-a' => 'commerz-ofd-sale-100',
                    'replacement' => 'commerz-ofd-refund-replacement-70',
                    default => 'commerz-ofd-refund-old-70',
                };
                $externalOutcomes[] = new FiscalExternalOutcome(
                    operationRef: $effect->operationRef,
                    economicEffectRef: $effect->economicEffectRef,
                    receiptKind: $effect->receiptKind,
                    fiscalStatus: 'registered',
                    evidenceDigest: 'sha256:'.hash('sha256', $extRef.':'.$effect->economicEffectRef),
                    externalFiscalRef: $extRef,
                    correctionRevision: 0,
                    sourcePayload: [
                        'fiscalProvider' => 'commerz-ofd',
                        'externalReceiptNo' => $extRef,
                    ],
                );
            }

            // Sync projections (and replay idempotently in reversed order): 1 sale (100) + 2 refunds (70 + 70 = 140), 0 intents, 0 sends.
            $projections = $orchestrator->syncActualProjectionsForRoot($scope, 'root-a', 'embedded-commerz', $externalOutcomes);
            $replayed = $orchestrator->syncActualProjectionsForRoot($scope, 'root-a', 'embedded-commerz', array_reverse($externalOutcomes));
            self::assertCount(3, $projections);
            self::assertCount(3, $replayed);
            self::assertSame(
                array_map(static fn (FiscalProjectionRecord $p): string => $p->projectionRef, $projections),
                array_map(static fn (FiscalProjectionRecord $p): string => $p->projectionRef, $replayed),
            );

            $refundProjections = array_values(array_filter(
                $projections,
                static fn (FiscalProjectionRecord $p): bool => $p->receiptKind === FiscalReceiptKind::Refund,
            ));
            self::assertCount(2, $refundProjections);
            self::assertSame(140, array_sum(array_map(static fn (FiscalProjectionRecord $p): int => $p->amount->minor, $refundProjections)));
            self::assertCount(1, array_filter($refundProjections, static fn (FiscalProjectionRecord $p): bool => $p->exceptional));
            foreach ($projections as $projection) {
                self::assertSame('registered', $projection->fiscalStatus);
                self::assertNotNull($projection->externalFiscalRef);
                self::assertStringStartsWith('commerz-ofd-', $projection->externalFiscalRef);
                $nestedPayload = $projection->sourcePayload['sourcePayload'] ?? null;
                self::assertIsArray($nestedPayload);
                $evidenceDigest = $nestedPayload['evidenceDigest'] ?? null;
                self::assertIsString($evidenceDigest);
                self::assertStringStartsWith('sha256:', $evidenceDigest);
            }

            self::assertSame(3, $this->rows('billium_fiscal_projection'));
            self::assertSame(0, $this->rows('billium_fiscal_projection', "external_fiscal_ref LIKE 'source-receipt:%'"));
            self::assertSame(0, $this->rows('billium_fiscal_intent'));
            self::assertSame(0, $driver->registerCalls);
            self::assertSame(0, $driver->lookupCalls);
            self::assertSame(3, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            InventoryReconciliationTest::clear($path);
        }
    }

    public function test_embedded_commerz_never_fabricates_fiscal_projection_without_authenticated_external_outcome(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            Refunds::captured($app, $path);

            $driver = new SimulatedFiscalDriver(
                id: 'unused-fiscal-f9',
                version: 'fiscal-driver.v1',
                supportedRoutes: [FiscalRoute::FiscalDriver],
            );
            $app->instance(FiscalDrivers::class, new SimulatedFiscalDrivers([$driver]));
            $orchestrator = $app->make(FiscalOrchestrator::class);

            foreach ([FiscalOperationKind::Charge, FiscalOperationKind::Refund] as $opKind) {
                $orchestrator->configurePolicy(new FiscalPolicyConfig(
                    scope: $scope,
                    executionProfile: 'embedded-commerz',
                    operationKind: $opKind,
                    receiptRequired: true,
                    fiscalRoute: FiscalRoute::ProjectionOnly,
                ));
            }

            // Confirmed payment with zero external fiscal evidence MUST produce 0 projection rows (never fabricated registered / source-receipt:*).
            $withoutEvidence = $orchestrator->syncActualProjectionsForRoot($scope, 'root-a', 'embedded-commerz');
            self::assertSame([], $withoutEvidence);
            self::assertSame(0, $this->rows('billium_fiscal_projection'));

            // Unmatched external fiscal evidence for a different effect MUST NOT project the confirmed payment.
            $unmatched = $orchestrator->syncActualProjectionsForRoot($scope, 'root-a', 'embedded-commerz', [
                new FiscalExternalOutcome(
                    operationRef: 'root-a',
                    economicEffectRef: 'fact:unrelated-effect',
                    receiptKind: FiscalReceiptKind::Sale,
                    fiscalStatus: 'registered',
                    evidenceDigest: 'sha256:'.str_repeat('d', 64),
                    externalFiscalRef: 'commerz-unrelated-1',
                ),
            ]);
            self::assertSame([], $unmatched);
            self::assertSame(0, $this->rows('billium_fiscal_projection'));

            $extracted = $app->make(ActualEffectExtractor::class)->extractForRoot($scope, 'root-a', 'embedded-commerz');
            self::assertCount(1, $extracted);
            $saleEffect = $extracted[0];

            // Registered status without externalFiscalRef is refused both by FiscalExternalOutcome and recordProjection.
            HistoricalMerchantRefundTest::problem(
                ProblemCode::ValidationInvalid,
                static fn (): FiscalExternalOutcome => new FiscalExternalOutcome(
                    operationRef: $saleEffect->operationRef,
                    economicEffectRef: $saleEffect->economicEffectRef,
                    receiptKind: FiscalReceiptKind::Sale,
                    fiscalStatus: 'registered',
                    evidenceDigest: 'sha256:'.str_repeat('e', 64),
                    externalFiscalRef: null,
                ),
            );
            HistoricalMerchantRefundTest::problem(
                ProblemCode::ValidationInvalid,
                static fn () => $orchestrator->recordProjection(new FiscalProjectionInput(
                    scope: $scope,
                    sourceAuthority: 'embedded-commerz',
                    economicEffectRef: $saleEffect->economicEffectRef,
                    rootOperationRef: $saleEffect->rootOperationRef,
                    operationRef: $saleEffect->operationRef,
                    receiptKind: FiscalReceiptKind::Sale,
                    correctionRevision: 0,
                    actualAmount: $saleEffect->actualAmount,
                    fiscalStatus: 'registered',
                    externalFiscalRef: '',
                )),
            );

            // Genuine pending external fiscal outcome projects as pending with null externalFiscalRef (never upgraded to registered).
            $pendingDigest = 'sha256:'.hash('sha256', 'commerz-pending-sale');
            $pendingProjections = $orchestrator->syncActualProjectionsForRoot($scope, 'root-a', 'embedded-commerz', [
                new FiscalExternalOutcome(
                    operationRef: $saleEffect->operationRef,
                    economicEffectRef: $saleEffect->economicEffectRef,
                    receiptKind: FiscalReceiptKind::Sale,
                    fiscalStatus: 'pending',
                    evidenceDigest: $pendingDigest,
                    externalFiscalRef: null,
                    sourcePayload: ['externalQueueId' => 'q-pending-1'],
                ),
            ]);
            self::assertCount(1, $pendingProjections);
            self::assertSame('pending', $pendingProjections[0]->fiscalStatus);
            self::assertNull($pendingProjections[0]->externalFiscalRef);
            self::assertSame(0, $this->rows('billium_fiscal_projection', "fiscal_status='registered'"));
            self::assertSame(0, $this->rows('billium_fiscal_projection', "external_fiscal_ref LIKE 'source-receipt:%'"));

            // Once genuine registered external fiscal evidence arrives, it projects with authentic externalFiscalRef and digest.
            $registeredDigest = 'sha256:'.hash('sha256', 'commerz-registered-sale');
            $registeredProjections = $orchestrator->syncActualProjectionsForRoot($scope, 'root-a', 'embedded-commerz', [
                new FiscalExternalOutcome(
                    operationRef: $saleEffect->operationRef,
                    economicEffectRef: $saleEffect->economicEffectRef,
                    receiptKind: FiscalReceiptKind::Sale,
                    fiscalStatus: 'registered',
                    evidenceDigest: $registeredDigest,
                    externalFiscalRef: 'commerz-ofd-genuine-100',
                    sourcePayload: ['fiscalDocumentNumber' => 'FDN-CMZ-100'],
                ),
            ]);
            self::assertCount(1, $registeredProjections);
            self::assertSame('registered', $registeredProjections[0]->fiscalStatus);
            self::assertSame('commerz-ofd-genuine-100', $registeredProjections[0]->externalFiscalRef);
            $storedPayload = $registeredProjections[0]->sourcePayload['sourcePayload'] ?? null;
            self::assertIsArray($storedPayload);
            self::assertSame($registeredDigest, $storedPayload['evidenceDigest'] ?? null);
            self::assertSame(['fiscalDocumentNumber' => 'FDN-CMZ-100'], $storedPayload['externalOutcome'] ?? null);

            self::assertSame(2, $this->rows('billium_fiscal_projection'));
            self::assertSame(0, $this->rows('billium_fiscal_projection', "external_fiscal_ref LIKE 'source-receipt:%'"));
            self::assertSame(0, $this->rows('billium_fiscal_intent'));
            self::assertSame(0, $driver->registerCalls);
            self::assertSame(0, $driver->lookupCalls);
        } finally {
            UnknownResultTest::clean($path);
        }
    }
}
