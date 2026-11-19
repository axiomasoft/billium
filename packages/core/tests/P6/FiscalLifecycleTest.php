<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Plugins\ExecutionPlugin;
use Billium\Core\Plugins\LedgerPlugin;
use Billium\Core\Plugins\PlatformPlugin;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\Fiscal\FiscalDriver;
use Billium\Execution\Contracts\Fiscal\FiscalDrivers;
use Billium\Execution\Contracts\Fiscal\FiscalOrchestrator;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\Fiscal\FiscalActualEffect;
use Billium\Execution\Data\Fiscal\FiscalCallbackEnvelope;
use Billium\Execution\Data\Fiscal\FiscalCorrectionRequest;
use Billium\Execution\Data\Fiscal\FiscalPolicyConfig;
use Billium\Execution\Data\Fiscal\FiscalProjectionInput;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\MerchantRevisionPolicy;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Enums\Fiscal\FiscalCallbackTrustStrategy;
use Billium\Execution\Enums\Fiscal\FiscalIntentState;
use Billium\Execution\Enums\Fiscal\FiscalOperationKind;
use Billium\Execution\Enums\Fiscal\FiscalReceiptKind;
use Billium\Execution\Enums\Fiscal\FiscalRoute;
use Billium\Execution\Internal\Merchants\DatabaseMerchantRegistry;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\HistoricalMerchantRefundTest;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use Billium\Testkit\Fiscal\FiscalIntegrationExtension;
use Billium\Testkit\Fiscal\SimulatedFiscalDriver;
use Billium\Testkit\Fiscal\SimulatedFiscalDrivers;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Plugins\Data\Plugin\PluginDescription;
use Packman\Plugins\Testing\PluginGraph;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;

#[Group('pg')]
final class FiscalLifecycleTest extends ExecutionTestCase
{
    public function test_policy_non_universality_mutual_exclusivity_and_embedded_commerz_dispatch_refusal(): void
    {
        $app = $this->application();
        $scope = Fixture::scope();
        $txProbe = static fn (): int => $app->make(DatabaseManager::class)->connection()->transactionLevel();
        $pspDriver = new SimulatedFiscalDriver(
            id: 'psp-passthrough-driver',
            version: 'psp-passthrough.v1',
            supportedRoutes: [FiscalRoute::PspPassthrough],
            txLevelProbe: $txProbe,
        );
        $cloudDriver = new SimulatedFiscalDriver(
            id: 'cloud-fiscal-driver',
            version: 'fiscal-driver.v1',
            supportedRoutes: [FiscalRoute::FiscalDriver],
            txLevelProbe: $txProbe,
        );
        $app->instance(FiscalDrivers::class, new SimulatedFiscalDrivers([$pspDriver, $cloudDriver]));
        $orchestrator = $app->make(FiscalOrchestrator::class);

        $effect = new FiscalActualEffect(
            scope: $scope,
            executionProfile: 'remote-billium',
            economicEffectRef: 'fact:non-universal-1',
            rootOperationRef: 'root-a',
            operationRef: 'root-a',
            operationKind: FiscalOperationKind::Charge,
            receiptKind: FiscalReceiptKind::Sale,
            actualAmount: new Money(1000, Currency::of('RUB')),
            merchantRevisionRef: 'merchant-a',
            merchantAccountRef: 'account-a',
            providerEvidenceRef: 'ev-charge-1',
        );

        // 1. Policy non-universality: no policy => 0 intents.
        $withoutPolicy = $orchestrator->prepareIntent($effect);
        self::assertNull($withoutPolicy);
        self::assertSame(0, $this->rows('billium_fiscal_intent'));

        // Policy with receiptRequired=false / route=none => 0 intents.
        $orchestrator->configurePolicy(new FiscalPolicyConfig(
            scope: $scope,
            executionProfile: 'remote-billium',
            operationKind: FiscalOperationKind::Charge,
            receiptRequired: false,
            fiscalRoute: FiscalRoute::None,
        ));
        $withNonePolicy = $orchestrator->prepareIntent($effect);
        self::assertNull($withNonePolicy);
        self::assertSame(0, $this->rows('billium_fiscal_intent', "seller_ref='seller-a'"));

        // 2. Profile-to-route validation & embedded-commerz dispatch refusal.
        HistoricalMerchantRefundTest::problem(
            ProblemCode::ValidationInvalid,
            static fn (): FiscalPolicyConfig => new FiscalPolicyConfig(
                scope: $scope,
                executionProfile: 'embedded-commerz',
                operationKind: FiscalOperationKind::Charge,
                receiptRequired: true,
                fiscalRoute: FiscalRoute::FiscalDriver,
                driverId: 'cloud-fiscal-driver',
                driverContractVersion: 'fiscal-driver.v1',
            ),
        );
        HistoricalMerchantRefundTest::problem(
            ProblemCode::ValidationInvalid,
            static fn (): FiscalPolicyConfig => new FiscalPolicyConfig(
                scope: $scope,
                executionProfile: 'remote-billium',
                operationKind: FiscalOperationKind::Charge,
                receiptRequired: true,
                fiscalRoute: FiscalRoute::ProjectionOnly,
            ),
        );
        HistoricalMerchantRefundTest::problem(
            ProblemCode::CapabilityUnsupported,
            static fn () => $orchestrator->prepareIntent(new FiscalActualEffect(
                scope: $scope,
                executionProfile: 'embedded-commerz',
                economicEffectRef: 'fact:embedded-1',
                rootOperationRef: 'root-a',
                operationRef: 'root-a',
                operationKind: FiscalOperationKind::Charge,
                receiptKind: FiscalReceiptKind::Sale,
                actualAmount: new Money(1000, Currency::of('RUB')),
                merchantRevisionRef: 'merchant-a',
                merchantAccountRef: 'account-a',
                providerEvidenceRef: 'ev-embedded-1',
            )),
        );
        self::assertSame(0, $this->rows('billium_fiscal_intent', "economic_effect_ref='fact:embedded-1'"));

        $orchestrator->configurePolicy(new FiscalPolicyConfig(
            scope: $scope,
            executionProfile: 'embedded-commerz',
            operationKind: FiscalOperationKind::Charge,
            receiptRequired: true,
            fiscalRoute: FiscalRoute::ProjectionOnly,
        ));
        // Registered projections MUST carry a non-empty externalFiscalRef (never fabricated).
        HistoricalMerchantRefundTest::problem(
            ProblemCode::ValidationInvalid,
            static fn () => $orchestrator->recordProjection(new FiscalProjectionInput(
                scope: $scope,
                sourceAuthority: 'embedded-commerz',
                economicEffectRef: 'fact:embedded-missing-ref',
                rootOperationRef: 'root-a',
                operationRef: 'root-a',
                receiptKind: FiscalReceiptKind::Sale,
                correctionRevision: 0,
                actualAmount: new Money(1000, Currency::of('RUB')),
                fiscalStatus: 'registered',
                externalFiscalRef: null,
            )),
        );
        HistoricalMerchantRefundTest::problem(
            ProblemCode::ValidationInvalid,
            static fn () => $orchestrator->recordProjection(new FiscalProjectionInput(
                scope: $scope,
                sourceAuthority: 'embedded-commerz',
                economicEffectRef: 'fact:embedded-blank-ref',
                rootOperationRef: 'root-a',
                operationRef: 'root-a',
                receiptKind: FiscalReceiptKind::Sale,
                correctionRevision: 0,
                actualAmount: new Money(1000, Currency::of('RUB')),
                fiscalStatus: 'registered',
                externalFiscalRef: '   ',
            )),
        );
        $projection = $orchestrator->recordProjection(new FiscalProjectionInput(
            scope: $scope,
            sourceAuthority: 'embedded-commerz',
            economicEffectRef: 'fact:embedded-1',
            rootOperationRef: 'root-a',
            operationRef: 'root-a',
            receiptKind: FiscalReceiptKind::Sale,
            correctionRevision: 0,
            actualAmount: new Money(1000, Currency::of('RUB')),
            fiscalStatus: 'registered',
            externalFiscalRef: 'commerz-rcpt-1',
        ));
        self::assertSame('embedded-commerz', $projection->executionProfile);
        self::assertSame('commerz-rcpt-1', $projection->externalFiscalRef);
        self::assertSame(1, $this->rows('billium_fiscal_projection'));
        self::assertSame(0, $this->rows('billium_fiscal_intent', "root_operation_ref='root-a'"));
        self::assertSame(0, $pspDriver->registerCalls + $cloudDriver->registerCalls);

        // 3. Passthrough vs fiscal_driver mutual exclusivity: original frozen route never dual-sends.
        $orchestrator->configurePolicy(new FiscalPolicyConfig(
            scope: $scope,
            executionProfile: 'remote-billium',
            operationKind: FiscalOperationKind::Charge,
            receiptRequired: true,
            fiscalRoute: FiscalRoute::PspPassthrough,
            driverId: 'psp-passthrough-driver',
            driverContractVersion: 'psp-passthrough.v1',
            callbackTrustStrategy: FiscalCallbackTrustStrategy::HmacSignature,
        ));
        $effectForRoute = new FiscalActualEffect(
            scope: $scope,
            executionProfile: 'remote-billium',
            economicEffectRef: 'fact:non-universal-1',
            rootOperationRef: 'root-a',
            operationRef: 'root-a',
            operationKind: FiscalOperationKind::Charge,
            receiptKind: FiscalReceiptKind::Sale,
            actualAmount: new Money(1000, Currency::of('RUB')),
            merchantRevisionRef: 'merchant-a',
            merchantAccountRef: 'account-a',
            providerEvidenceRef: 'ev-charge-1',
        );
        $prepared = $orchestrator->prepareIntent($effectForRoute);
        self::assertNotNull($prepared);
        self::assertSame(FiscalRoute::PspPassthrough, $prepared->fiscalRoute);
        self::assertSame('psp-passthrough-driver', $prepared->driverId);

        // Switching active policy to fiscal_driver must NOT create a second intent or mutate the original route.
        $orchestrator->configurePolicy(new FiscalPolicyConfig(
            scope: $scope,
            executionProfile: 'remote-billium',
            operationKind: FiscalOperationKind::Charge,
            receiptRequired: true,
            fiscalRoute: FiscalRoute::FiscalDriver,
            driverId: 'cloud-fiscal-driver',
            driverContractVersion: 'fiscal-driver.v1',
            callbackTrustStrategy: FiscalCallbackTrustStrategy::HmacSignature,
        ));
        $replayed = $orchestrator->prepareIntent($effectForRoute);
        self::assertNotNull($replayed);
        self::assertSame($prepared->intentRef, $replayed->intentRef);
        self::assertSame($prepared->externalId, $replayed->externalId);
        self::assertSame(FiscalRoute::PspPassthrough, $replayed->fiscalRoute);
        self::assertSame(1, $this->rows('billium_fiscal_intent'));

        // DB immutability trigger forbids mutating frozen fiscal_route even as schema owner.
        $owner = TestEnvironment::pdo(owner: true);
        self::assertSame('55000', self::sqlState($owner, "UPDATE billium_fiscal_intent SET fiscal_route='fiscal_driver' WHERE intent_ref='{$prepared->intentRef}'"));
        self::assertSame('55000', self::sqlState($owner, "DELETE FROM billium_fiscal_intent WHERE intent_ref='{$prepared->intentRef}'"));
    }

    public function test_lost_register_response_and_24h_outage_recover_via_lookup_with_zero_duplicate_registrations_and_paid_intact(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            Refunds::captured($app, $path);
            $paidBefore = $app->make(ExecutionQueries::class)->status($scope, 'root-a');
            self::assertSame('succeeded', $paidBefore->status);

            $txProbe = static fn (): int => $app->make(DatabaseManager::class)->connection()->transactionLevel();
            $driver = new SimulatedFiscalDriver(
                id: 'cloud-fiscal',
                version: 'fiscal-driver.v1',
                supportedRoutes: [FiscalRoute::FiscalDriver],
                txLevelProbe: $txProbe,
            );
            $app->instance(FiscalDrivers::class, new SimulatedFiscalDrivers([$driver]));
            $orchestrator = $app->make(FiscalOrchestrator::class);
            $orchestrator->configurePolicy(new FiscalPolicyConfig(
                scope: $scope,
                executionProfile: 'remote-billium',
                operationKind: FiscalOperationKind::Charge,
                receiptRequired: true,
                fiscalRoute: FiscalRoute::FiscalDriver,
                driverId: 'cloud-fiscal',
                driverContractVersion: 'fiscal-driver.v1',
                callbackTrustStrategy: FiscalCallbackTrustStrategy::HmacSignature,
            ));

            $intents = $orchestrator->syncActualEffectsForRoot($scope, 'root-a', 'remote-billium');
            self::assertCount(1, $intents);
            $intent = $intents[0];
            $driver->loseRegisterResponseFor($intent->externalId);

            $t0 = new DateTimeImmutable('2026-11-19T00:00:00Z');
            $afterLost = $orchestrator->dispatchIntent($scope, $intent->intentRef, $t0);
            self::assertSame(FiscalIntentState::Unknown, $afterLost->state);
            self::assertSame(1, $driver->registerCalls);
            self::assertSame(0, $driver->duplicateRegistrations);

            // DB trigger strictly forbids unknown -> prepared re-registration.
            $owner = TestEnvironment::pdo(owner: true);
            self::assertSame('23514', self::sqlState($owner, "UPDATE billium_fiscal_intent SET state='prepared' WHERE intent_ref='{$intent->intentRef}'"));
            self::assertSame('23514', self::sqlState($owner, "UPDATE billium_fiscal_intent SET state='submitted' WHERE intent_ref='{$intent->intentRef}'"));

            // Simulate a 24h outage: repeated dispatch/lookup retries NEVER re-register.
            $driver->setOffline(true);
            $retry1 = $orchestrator->dispatchIntent($scope, $intent->intentRef, $t0->modify('+1 hour'));
            $retry2 = $orchestrator->lookupIntent($scope, $intent->intentRef, $t0->modify('+12 hours'));
            $retry3 = $orchestrator->lookupIntent($scope, $intent->intentRef, $t0->modify('+23 hours'));
            self::assertSame(FiscalIntentState::Unknown, $retry1->state);
            self::assertSame(FiscalIntentState::Unknown, $retry2->state);
            self::assertSame(FiscalIntentState::Unknown, $retry3->state);
            self::assertSame(1, $driver->registerCalls);
            self::assertSame(3, $driver->lookupCalls);
            self::assertSame(0, $driver->duplicateRegistrations);

            // Paid execution status remains completely intact during the entire 24h fiscal outage.
            self::assertSame($paidBefore->status, $app->make(ExecutionQueries::class)->status($scope, 'root-a')->status);
            self::assertSame(1, $this->rows('billium_execution_operation', "operation_ref='root-a' AND state='succeeded'"));

            // Outage clears at +24h: lookup recovers the original receipt without re-registering.
            $driver->setOffline(false);
            $recovered = $orchestrator->lookupIntent($scope, $intent->intentRef, $t0->modify('+24 hours'));
            self::assertSame(FiscalIntentState::Registered, $recovered->state);
            self::assertNotNull($recovered->providerReceiptId);
            self::assertNotNull($recovered->fiscalDocumentNumber);
            self::assertNull($recovered->nextLookupAt);
            self::assertSame(1, $driver->registerCalls);
            self::assertSame(4, $driver->lookupCalls);
            self::assertSame(0, $driver->duplicateRegistrations);

            // Terminal registered state is immutable even under direct SQL update.
            self::assertSame('23514', self::sqlState($owner, "UPDATE billium_fiscal_intent SET state='unknown' WHERE intent_ref='{$intent->intentRef}'"));
            self::assertSame(1, $this->rows('billium_execution_operation', "operation_ref='root-a' AND state='succeeded'"));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_late_and_duplicate_callbacks_enforce_trust_strategy_and_redact_signatures(): void
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
                supportedTrustStrategies: [FiscalCallbackTrustStrategy::HmacSignature],
            );
            $app->instance(FiscalDrivers::class, new SimulatedFiscalDrivers([$driver]));
            $orchestrator = $app->make(FiscalOrchestrator::class);
            $orchestrator->configurePolicy(new FiscalPolicyConfig(
                scope: $scope,
                executionProfile: 'remote-billium',
                operationKind: FiscalOperationKind::Charge,
                receiptRequired: true,
                fiscalRoute: FiscalRoute::FiscalDriver,
                driverId: 'cloud-fiscal',
                driverContractVersion: 'fiscal-driver.v1',
                callbackTrustStrategy: FiscalCallbackTrustStrategy::HmacSignature,
            ));
            $intent = $orchestrator->syncActualEffectsForRoot($scope, 'root-a', 'remote-billium')[0];
            $driver->loseRegisterResponseFor($intent->externalId);
            $unknown = $orchestrator->dispatchIntent($scope, $intent->intentRef, new DateTimeImmutable('2026-11-19T01:00:00Z'));
            self::assertSame(FiscalIntentState::Unknown, $unknown->state);

            $payload = [
                'state' => 'registered',
                'providerReceiptId' => 'cb-rcpt-777',
                'fiscalDocumentNumber' => 'FDN-777001',
            ];

            // Untrusted signature is rejected and signature string is never persisted in evidence.
            $forgedSig = 'forged-secret-signature-value-do-not-store';
            HistoricalMerchantRefundTest::problem(
                ProblemCode::AuthDenied,
                static fn () => $orchestrator->ingestCallback(new FiscalCallbackEnvelope(
                    scope: $scope,
                    externalId: $intent->externalId,
                    driverId: 'cloud-fiscal',
                    driverContractVersion: 'fiscal-driver.v1',
                    externalEventId: 'evt-bad-1',
                    trustStrategy: FiscalCallbackTrustStrategy::HmacSignature,
                    signature: $forgedSig,
                    payload: $payload,
                    receivedAt: new DateTimeImmutable('2026-11-19T01:05:00Z'),
                )),
            );

            // Wrong trust strategy is rejected.
            HistoricalMerchantRefundTest::problem(
                ProblemCode::AuthDenied,
                static fn () => $orchestrator->ingestCallback(new FiscalCallbackEnvelope(
                    scope: $scope,
                    externalId: $intent->externalId,
                    driverId: 'cloud-fiscal',
                    driverContractVersion: 'fiscal-driver.v1',
                    externalEventId: 'evt-wrong-strategy',
                    trustStrategy: FiscalCallbackTrustStrategy::MutualTls,
                    signature: 'mtls-token',
                    payload: $payload,
                    receivedAt: new DateTimeImmutable('2026-11-19T01:06:00Z'),
                )),
            );

            self::assertSame(FiscalIntentState::Unknown, $orchestrator->findIntent($scope, $intent->intentRef)?->state);
            self::assertSame(0, $this->rows('billium_fiscal_evidence', "sanitized_payload::text LIKE '%forged-secret-signature%'"));

            // Valid signed late callback transitions unknown -> registered.
            $validSig = $driver->signCallback($intent->externalId, 'evt-ok-1', FiscalCallbackTrustStrategy::HmacSignature, $payload);
            $registered = $orchestrator->ingestCallback(new FiscalCallbackEnvelope(
                scope: $scope,
                externalId: $intent->externalId,
                driverId: 'cloud-fiscal',
                driverContractVersion: 'fiscal-driver.v1',
                externalEventId: 'evt-ok-1',
                trustStrategy: FiscalCallbackTrustStrategy::HmacSignature,
                signature: $validSig,
                payload: $payload,
                receivedAt: new DateTimeImmutable('2026-11-19T01:10:00Z'),
            ));
            self::assertSame(FiscalIntentState::Registered, $registered->state);
            self::assertSame('cb-rcpt-777', $registered->providerReceiptId);
            self::assertSame('FDN-777001', $registered->fiscalDocumentNumber);

            // Duplicate callback (same event) and late second callback (different event) are safely ignored.
            $dup1 = $orchestrator->ingestCallback(new FiscalCallbackEnvelope(
                scope: $scope,
                externalId: $intent->externalId,
                driverId: 'cloud-fiscal',
                driverContractVersion: 'fiscal-driver.v1',
                externalEventId: 'evt-ok-1',
                trustStrategy: FiscalCallbackTrustStrategy::HmacSignature,
                signature: $validSig,
                payload: $payload,
                receivedAt: new DateTimeImmutable('2026-11-19T01:15:00Z'),
            ));
            $lateSig = $driver->signCallback($intent->externalId, 'evt-late-2', FiscalCallbackTrustStrategy::HmacSignature, $payload);
            $dup2 = $orchestrator->ingestCallback(new FiscalCallbackEnvelope(
                scope: $scope,
                externalId: $intent->externalId,
                driverId: 'cloud-fiscal',
                driverContractVersion: 'fiscal-driver.v1',
                externalEventId: 'evt-late-2',
                trustStrategy: FiscalCallbackTrustStrategy::HmacSignature,
                signature: $lateSig,
                payload: $payload,
                receivedAt: new DateTimeImmutable('2026-11-19T01:20:00Z'),
            ));
            self::assertSame(FiscalIntentState::Registered, $dup1->state);
            self::assertSame(FiscalIntentState::Registered, $dup2->state);
            self::assertSame('cb-rcpt-777', $dup2->providerReceiptId);
            self::assertSame(2, $this->rows('billium_fiscal_evidence', "outcome_state='duplicate_ignored'"));
            self::assertSame(1, $driver->registerCalls);
            self::assertSame(0, $driver->duplicateRegistrations);
            self::assertSame(1, $this->rows('billium_execution_operation', "operation_ref='root-a' AND state='succeeded'"));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_repeated_corrections_increment_revision_preserve_parent_and_keep_stable_retry_external_id(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            Refunds::captured($app, $path);
            $driver = new SimulatedFiscalDriver(
                id: 'psp-passthrough',
                version: 'psp-passthrough.v1',
                supportedRoutes: [FiscalRoute::PspPassthrough],
            );
            $app->instance(FiscalDrivers::class, new SimulatedFiscalDrivers([$driver]));
            $orchestrator = $app->make(FiscalOrchestrator::class);
            $orchestrator->configurePolicy(new FiscalPolicyConfig(
                scope: $scope,
                executionProfile: 'remote-billium',
                operationKind: FiscalOperationKind::Charge,
                receiptRequired: true,
                fiscalRoute: FiscalRoute::PspPassthrough,
                driverId: 'psp-passthrough',
                driverContractVersion: 'psp-passthrough.v1',
                callbackTrustStrategy: FiscalCallbackTrustStrategy::DriverVerified,
            ));

            $original = $orchestrator->syncActualEffectsForRoot($scope, 'root-a', 'remote-billium')[0];
            $original = $orchestrator->dispatchIntent($scope, $original->intentRef);
            self::assertSame(FiscalIntentState::Registered, $original->state);
            self::assertSame(0, $original->correctionRevision);

            // Correction revision 1 + idempotent retry with stable external_id.
            $req1 = new FiscalCorrectionRequest(
                scope: $scope,
                parentFiscalIntentRef: $original->intentRef,
                correctionAmount: new Money(50, Currency::of('RUB')),
                reason: 'tax_rate_adjustment',
                correctionBasisRef: 'corr-basis-1',
                receiptPayload: ['vatCode' => 'vat20'],
            );
            $corr1 = $orchestrator->createCorrection($req1);
            $corr1Retry = $orchestrator->createCorrection($req1);
            self::assertSame(1, $corr1->correctionRevision);
            self::assertSame(FiscalReceiptKind::CorrectionSale, $corr1->receiptKind);
            self::assertSame($original->intentRef, $corr1->parentFiscalIntentRef);
            self::assertSame(FiscalRoute::PspPassthrough, $corr1->fiscalRoute);
            self::assertSame($corr1->intentRef, $corr1Retry->intentRef);
            self::assertSame($corr1->externalId, $corr1Retry->externalId);
            self::assertNotSame($original->externalId, $corr1->externalId);

            // Conflicting amount on same correctionBasisRef is refused.
            HistoricalMerchantRefundTest::problem(
                ProblemCode::IdempotencyConflict,
                static fn () => $orchestrator->createCorrection(new FiscalCorrectionRequest(
                    scope: $scope,
                    parentFiscalIntentRef: $original->intentRef,
                    correctionAmount: new Money(60, Currency::of('RUB')),
                    reason: 'tax_rate_adjustment',
                    correctionBasisRef: 'corr-basis-1',
                )),
            );

            $corr1 = $orchestrator->dispatchIntent($scope, $corr1->intentRef);
            self::assertSame(FiscalIntentState::Registered, $corr1->state);

            // Correction revision 2 chained after correction 1.
            $corr2 = $orchestrator->createCorrection(new FiscalCorrectionRequest(
                scope: $scope,
                parentFiscalIntentRef: $corr1->intentRef,
                correctionAmount: new Money(25, Currency::of('RUB')),
                reason: 'second_tax_correction',
                correctionBasisRef: 'corr-basis-2',
            ));
            self::assertSame(2, $corr2->correctionRevision);
            self::assertSame($corr1->intentRef, $corr2->parentFiscalIntentRef);
            self::assertNotSame($corr1->externalId, $corr2->externalId);
            $corr2 = $orchestrator->dispatchIntent($scope, $corr2->intentRef);
            self::assertSame(FiscalIntentState::Registered, $corr2->state);

            self::assertSame(3, $this->rows('billium_fiscal_intent'));
            self::assertSame(3, $driver->registerCalls);
            self::assertSame(0, $driver->duplicateRegistrations);
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_historical_lookup_after_merchant_rotation_uses_continuity_merchant_credentials_lookup(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            Refunds::captured($app, $path);
            $oldMerchant = $app->make(MerchantRegistry::class)->historical($scope, 'root-a');

            $driver = new SimulatedFiscalDriver(
                id: 'cloud-fiscal',
                version: 'fiscal-driver.v1',
                supportedRoutes: [FiscalRoute::FiscalDriver],
            );
            $app->instance(FiscalDrivers::class, new SimulatedFiscalDrivers([$driver]));
            $orchestrator = $app->make(FiscalOrchestrator::class);
            $orchestrator->configurePolicy(new FiscalPolicyConfig(
                scope: $scope,
                executionProfile: 'remote-billium',
                operationKind: FiscalOperationKind::Charge,
                receiptRequired: true,
                fiscalRoute: FiscalRoute::FiscalDriver,
                driverId: 'cloud-fiscal',
                driverContractVersion: 'fiscal-driver.v1',
            ));

            $intent = $orchestrator->syncActualEffectsForRoot($scope, 'root-a', 'remote-billium')[0];
            $driver->loseRegisterResponseFor($intent->externalId);
            $unknown = $orchestrator->dispatchIntent($scope, $intent->intentRef);
            self::assertSame(FiscalIntentState::Unknown, $unknown->state);

            // Rotate merchant: stop 'new' on merchant-a, rotate 'lookup' credential on merchant-a, activate replacement merchant.
            $nextCap = SimulatedCapabilities::snapshot('replacement-account');
            $registry = $app->make(DatabaseMerchantRegistry::class);
            $store = $app->make(ExecutionStore::class);
            $rotatedSecret = new SecretRef('secret://vault/historical_lookup_v2');
            $admin = $this->spec(
                kind: 'execution.fiscal_rotate',
                mode: FinancialMode::Administrative,
                class: ModuleOperationClass::HistoricalRead,
                modules: ['execution', 'platform'],
            );
            $this->runner()->run($admin, static function (TransactionContext $ctx) use ($nextCap, $oldMerchant, $registry, $store, $scope, $rotatedSecret): PlatformReceipt {
                $time = new DateTimeImmutable('-1 minute');
                $registry->appendLifecycle($ctx, $scope, 'merchant-a', 'stop-old-new', 'stop_new', 'new', $time, null, 'merchant rotated', 'approved-rotation');
                $registry->appendCredential($ctx, $oldMerchant, 'rotated-lookup-cred', 'rotated-lookup-v2', $rotatedSecret, 'lookup', $time, new DateTimeImmutable('+2 days'), 'verified-old-lookup', 'cred-lookup');
                $replacement = new MerchantRevision(
                    'replacement',
                    $scope,
                    $nextCap->merchantAccountRef,
                    'simulated-provider',
                    $nextCap->driverId,
                    $nextCap->driverContractVersion,
                    $nextCap->snapshotRef,
                    $nextCap->digest,
                    new SecretRef('secret://vault/replacement_account'),
                    'replacement-v1',
                    $nextCap->currencies,
                    $oldMerchant->digest,
                    $time,
                    new MerchantRevisionPolicy($time, null, $oldMerchant->revisionRef, 'sim-api-v2', 'replacement-webhook', 'replacement-proof', 'replacement-settlement', 'replacement-mandate'),
                );
                $store->appendMerchantRevision($ctx, $replacement, $nextCap);
                $registry->appendLifecycle($ctx, $scope, 'replacement', 'enable-replacement-new', 'enable_new', 'new', $time, null, 'replacement active', 'approved-rotation');

                return self::receipt();
            })->orThrow();

            $app->instance(MerchantCredentials::class, new readonly class($rotatedSecret) implements MerchantCredentials
            {
                public function __construct(private SecretRef $secret) {}

                public function resolve(MerchantRevision $revision, string $purpose): ResolvedCredentials
                {
                    return new ResolvedCredentials(
                        merchantAccountRef: $revision->merchantAccountRef,
                        credentialVersionRef: 'rotated-lookup-v2',
                        secretRef: $this->secret,
                        purpose: $purpose,
                        expiresAt: new DateTimeImmutable('+5 minutes'),
                        continuityProofRef: 'verified-old-lookup',
                    );
                }
            });

            $recovered = $orchestrator->lookupIntent($scope, $intent->intentRef);
            self::assertSame(FiscalIntentState::Registered, $recovered->state);
            self::assertCount(1, $driver->recordedLookups);
            $lookupReq = $driver->recordedLookups[0];
            self::assertSame('merchant-a', $lookupReq->merchantRevision->revisionRef);
            self::assertSame('account-a', $lookupReq->credentials->merchantAccountRef);
            self::assertSame('lookup', $lookupReq->credentials->purpose);
            self::assertSame('rotated-lookup-v2', $lookupReq->credentials->credentialVersionRef);
            self::assertSame(1, $this->rows('billium_fiscal_evidence', "evidence_kind='lookup_response' AND merchant_revision_ref='merchant-a' AND credential_version_ref='rotated-lookup-v2'"));
            self::assertSame(1, $driver->registerCalls);
            self::assertSame(0, $driver->duplicateRegistrations);
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    public function test_cov_337_due_lookup_index_scan_bounded_claim_no_early_reclaim_and_no_cross_seller_starvation(): void
    {
        $app = $this->application();
        $scopeA = Fixture::scope();
        $orchestrator = $app->make(FiscalOrchestrator::class);
        $asOf = new DateTimeImmutable('2026-11-19T12:00:00Z');
        $digest = 'sha256:'.str_repeat('a', 64);

        // Seed representative multi-seller volume (~2,400 rows across 4 sellers):
        // - seller-failing: 600 due unknown lookups (must NOT starve seller-a)
        // - seller-a: 15 due unknown lookups + 400 future unknown lookups + 400 registered rows
        // - seller-b: 20 due unknown lookups + 500 future unknown lookups + 465 registered rows
        $this->owner()->transaction(function () use ($digest): void {
            $this->owner()->unprepared(<<<SQL
                INSERT INTO public.billium_fiscal_intent (
                    installation_ref, seller_ref, environment, intent_ref, execution_profile,
                    economic_effect_ref, root_operation_ref, operation_ref, receipt_kind, correction_revision,
                    parent_fiscal_intent_ref, external_id, fiscal_route, driver_id, driver_contract_version,
                    callback_trust_strategy, merchant_revision_ref, merchant_account_ref, provider_evidence_ref,
                    policy_version, policy_digest, amount_minor, currency, currency_exponent,
                    exceptional, exposure_provenance, payload_snapshot, payload_digest,
                    state, lookup_attempts, next_lookup_at, created_at, updated_at
                )
                SELECT
                    'inst-test',
                    CASE
                        WHEN n <= 600 THEN 'seller-failing'
                        WHEN n <= 1415 THEN 'seller-a'
                        ELSE 'seller-b'
                    END,
                    'test',
                    'fint:cov337-' || n,
                    'remote-billium',
                    'effect:cov337-' || n,
                    'root-' || n,
                    'op-' || n,
                    'sale',
                    0,
                    NULL,
                    'fext:cov337-' || n,
                    'fiscal_driver',
                    'cloud-fiscal',
                    'fiscal-driver.v1',
                    'driver_verified',
                    'merchant-a',
                    'account-a',
                    'ev-' || n,
                    1,
                    '{$digest}',
                    1000,
                    'RUB',
                    2,
                    false,
                    '{}'::jsonb,
                    '{"fixture":true}'::jsonb,
                    '{$digest}',
                    CASE
                        WHEN n <= 600 THEN 'unknown'
                        WHEN n <= 615 THEN 'unknown'
                        WHEN n <= 1015 THEN 'unknown'
                        WHEN n <= 1415 THEN 'registered'
                        WHEN n <= 1435 THEN 'unknown'
                        WHEN n <= 1935 THEN 'unknown'
                        ELSE 'registered'
                    END,
                    0,
                    CASE
                        WHEN n <= 600 THEN timestamptz '2026-11-19 11:00:00+00' + (n || ' milliseconds')::interval
                        WHEN n <= 615 THEN timestamptz '2026-11-19 11:30:00+00' + ((n - 600) || ' seconds')::interval
                        WHEN n <= 1015 THEN timestamptz '2026-11-19 14:00:00+00' + ((n - 615) || ' seconds')::interval
                        WHEN n <= 1415 THEN NULL
                        WHEN n <= 1435 THEN timestamptz '2026-11-19 11:20:00+00' + ((n - 1415) || ' seconds')::interval
                        WHEN n <= 1935 THEN timestamptz '2026-11-19 15:00:00+00' + ((n - 1435) || ' seconds')::interval
                        ELSE NULL
                    END,
                    timestamptz '2026-11-19 10:00:00+00',
                    timestamptz '2026-11-19 10:00:00+00'
                FROM generate_series(1, 2400) AS n;
                ANALYZE public.billium_fiscal_intent;
                SQL);
        });

        // Verify PostgreSQL planner uses Index Scan on billium_fiscal_intent_due_lookup WITHOUT forcing seqscan off.
        $pdo = TestEnvironment::pdo();
        $stmt = $pdo->query(
            'EXPLAIN SELECT id FROM public.billium_fiscal_intent '
            ."WHERE seller_ref = 'seller-a' AND state = 'unknown' AND next_lookup_at <= '2026-11-19 12:00:00+00' AND id > 0 "
            ."AND installation_ref = 'inst-test' AND environment = 'test' "
            ."AND (lease_expires_at IS NULL OR lease_expires_at <= '2026-11-19 12:00:00+00') "
            .'ORDER BY next_lookup_at ASC, id ASC LIMIT 10',
        );
        self::assertNotFalse($stmt);
        $plan = implode("\n", array_map(self::text(...), $stmt->fetchAll(PDO::FETCH_COLUMN)));
        self::assertStringContainsString('Index', $plan);
        self::assertStringContainsString('billium_fiscal_intent_due_lookup', $plan);

        // Bounded seller-a claim: gets first 10 due rows, zero future rows, zero seller-failing/seller-b rows.
        $batch1 = $orchestrator->claimDueLookups($scopeA, $asOf, limit: 10, afterId: 0, workerRef: 'worker-a');
        self::assertCount(10, $batch1);
        foreach ($batch1 as $row) {
            self::assertSame('seller-a', $row->scope->sellerRef);
            self::assertNotNull($row->nextLookupAt);
            self::assertLessThanOrEqual($asOf, $row->nextLookupAt);
        }

        // Keyset pagination continues cleanly with afterId to remaining 5 due rows; future rows NOT reclaimed early.
        $lastId = $batch1[9]->id;
        $batch2 = $orchestrator->claimDueLookups($scopeA, $asOf, limit: 10, afterId: $lastId, workerRef: 'worker-a');
        self::assertCount(5, $batch2);
        $batch3 = $orchestrator->claimDueLookups($scopeA, $asOf, limit: 10, afterId: 0, workerRef: 'worker-a-2');
        self::assertCount(0, $batch3);

        // Cross-seller caller cannot claim seller-b under seller-a caller grant.
        $scopeB = new ExecutionScope(self::INSTALLATION, 'seller-b', 'test', 'source-a');
        HistoricalMerchantRefundTest::problem(
            ProblemCode::AuthDenied,
            static fn () => $orchestrator->claimDueLookups($scopeB, $asOf, limit: 10),
        );

        // Switching to authenticated seller-b claims seller-b due rows unaffected by seller-failing backlog.
        $caller = $this->callers->current();
        $this->callers->set(new Caller('seller-b', $caller->environment, $caller->installationRef, $caller->actorRef, $caller->sourceAuthorities, $caller->intentOwnerNamespaces));
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);
        $sellerBBatch = $orchestrator->claimDueLookups($scopeB, $asOf, limit: 25, afterId: 0, workerRef: 'worker-b');
        self::assertCount(20, $sellerBBatch);
        foreach ($sellerBBatch as $row) {
            self::assertSame('seller-b', $row->scope->sellerRef);
            self::assertNotNull($row->nextLookupAt);
            self::assertLessThanOrEqual($asOf, $row->nextLookupAt);
        }
    }

    public function test_cov_295_extension_isolation_and_exact_contract_version_negotiation(): void
    {
        $extension = new FiscalIntegrationExtension;
        self::assertFalse(new ReflectionClass($extension)->implementsInterface(WithSchema::class));

        $registry = PluginGraph::compile(
            $this->application(),
            [FiscalIntegrationExtension::class],
            corePluginId: 'axiomasoft.billium.platform',
            builtIn: [
                PlatformPlugin::class,
                LedgerPlugin::class,
                ExecutionPlugin::class,
            ],
        );
        $ids = array_map(static fn (PluginDescription $descriptor): string => $descriptor->id, $registry->describe());
        self::assertContains('axiomasoft.billium.execution', $ids);
        self::assertContains('axiomasoft.billium.testkit.fiscal-extension', $ids);

        $v1 = new SimulatedFiscalDriver(id: 'ext-fiscal', version: 'fiscal-driver.v1');
        $drivers = new SimulatedFiscalDrivers([$v1]);

        self::assertSame($v1, $extension->negotiateDriver($drivers, 'ext-fiscal', 'fiscal-driver.v1'));

        // Unknown contract version MUST refuse explicitly; never substitutes v1 or latest.
        HistoricalMerchantRefundTest::problem(
            ProblemCode::CapabilityUnsupported,
            static fn (): FiscalDriver => $extension->negotiateDriver($drivers, 'ext-fiscal', 'fiscal-driver.v2'),
        );
        HistoricalMerchantRefundTest::problem(
            ProblemCode::CapabilityUnsupported,
            static fn (): FiscalDriver => $drivers->resolve('ext-fiscal', 'fiscal-driver.v99'),
        );
    }
}
