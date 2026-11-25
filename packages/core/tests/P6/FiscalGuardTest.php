<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\Fiscal\FiscalDrivers;
use Billium\Execution\Contracts\Fiscal\FiscalOrchestrator;
use Billium\Execution\Data\Fiscal\FiscalActualEffect;
use Billium\Execution\Data\Fiscal\FiscalCorrectionRequest;
use Billium\Execution\Data\Fiscal\FiscalPolicyConfig;
use Billium\Execution\Enums\Fiscal\FiscalCallbackTrustStrategy;
use Billium\Execution\Enums\Fiscal\FiscalIntentState;
use Billium\Execution\Enums\Fiscal\FiscalOperationKind;
use Billium\Execution\Enums\Fiscal\FiscalReceiptKind;
use Billium\Execution\Enums\Fiscal\FiscalRoute;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Testkit\Fiscal\SimulatedFiscalDriver;
use Billium\Testkit\Fiscal\SimulatedFiscalDrivers;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;

/** The fiscal orchestrator refuses routes, trust strategies, lookups, pages and corrections its driver or parent cannot honour. */
#[Group('pg')]
final class FiscalGuardTest extends ExecutionTestCase
{
    public function test_policy_refuses_a_route_or_trust_strategy_the_driver_lacks(): void
    {
        $app = $this->application();
        $app->instance(FiscalDrivers::class, new SimulatedFiscalDrivers([
            new SimulatedFiscalDriver(id: 'psp-only', version: 'v1', supportedRoutes: [FiscalRoute::PspPassthrough]),
            new SimulatedFiscalDriver(id: 'hmac-only', version: 'v1', supportedTrustStrategies: [FiscalCallbackTrustStrategy::HmacSignature]),
        ]));
        $orchestrator = $app->make(FiscalOrchestrator::class);

        self::refused(ProblemCode::CapabilityUnsupported, 'Fiscal driver does not support configured fiscal route',
            static fn (): mixed => $orchestrator->configurePolicy(self::policy(FiscalRoute::FiscalDriver, 'psp-only')));
        self::refused(ProblemCode::CapabilityUnsupported, 'Fiscal driver does not support configured callback trust strategy',
            static fn (): mixed => $orchestrator->configurePolicy(self::policy(FiscalRoute::FiscalDriver, 'hmac-only', FiscalCallbackTrustStrategy::DriverVerified)));
        self::assertSame(0, $this->rows('billium_fiscal_policy'));
    }

    public function test_driver_capabilities_and_parent_currency_are_rechecked_after_configuration(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $scope = Fixture::scope();
            Refunds::captured($app, $path);
            $driver = new SimulatedFiscalDriver(id: 'cloud', version: 'v1', supportedRoutes: [FiscalRoute::FiscalDriver], supportsLookup: false);
            $app->instance(FiscalDrivers::class, new SimulatedFiscalDrivers([$driver]));
            $orchestrator = $app->make(FiscalOrchestrator::class);
            $orchestrator->configurePolicy(self::policy(FiscalRoute::FiscalDriver, 'cloud', FiscalCallbackTrustStrategy::HmacSignature));
            $intent = $orchestrator->syncActualEffectsForRoot($scope, 'root-a', 'remote-billium')[0];

            $driver->loseRegisterResponseFor($intent->externalId);
            self::assertSame(FiscalIntentState::Unknown, $orchestrator->dispatchIntent($scope, $intent->intentRef)->state);
            self::refused(ProblemCode::CapabilityUnsupported, 'Fiscal driver does not support receipt lookup',
                static fn (): mixed => $orchestrator->lookupIntent($scope, $intent->intentRef));

            foreach ([0, 501] as $limit) {
                self::refused(ProblemCode::ValidationInvalid, 'claimDueLookups limit must be between 1 and 500',
                    static fn (): mixed => $orchestrator->claimDueLookups($scope, new DateTimeImmutable, $limit));
            }
            self::refused(ProblemCode::MoneyCurrencyMismatch, 'Fiscal correction currency must match parent intent currency',
                static fn (): mixed => $orchestrator->createCorrection(new FiscalCorrectionRequest(scope: $scope, parentFiscalIntentRef: $intent->intentRef,
                    correctionAmount: new Money(50, Currency::of('USD')), reason: 'tax_rate_adjustment', correctionBasisRef: 'usd-basis')));

            // A driver swapped for one without the route must not receive new intents.
            $app->instance(FiscalDrivers::class, new SimulatedFiscalDrivers([new SimulatedFiscalDriver(id: 'cloud', version: 'v1', supportedRoutes: [FiscalRoute::PspPassthrough])]));
            $app->forgetInstance(FiscalOrchestrator::class);
            $reconfigured = $app->make(FiscalOrchestrator::class);
            self::refused(ProblemCode::CapabilityUnsupported, 'Resolved fiscal driver does not support policy route',
                static fn (): mixed => $reconfigured->prepareIntent(new FiscalActualEffect(
                    scope: $scope, executionProfile: 'remote-billium', economicEffectRef: 'fact:swapped-driver', rootOperationRef: 'root-a', operationRef: 'root-a',
                    operationKind: FiscalOperationKind::Charge, receiptKind: FiscalReceiptKind::Sale,
                    actualAmount: new Money(1000, Currency::of('RUB')), merchantRevisionRef: 'merchant-a', merchantAccountRef: 'account-a', providerEvidenceRef: 'ev-swapped')));
        } finally {
            UnknownResultTest::clean($path);
        }
    }

    private static function policy(FiscalRoute $route, string $driver, ?FiscalCallbackTrustStrategy $trust = null): FiscalPolicyConfig
    {
        return new FiscalPolicyConfig(scope: Fixture::scope(), executionProfile: 'remote-billium', operationKind: FiscalOperationKind::Charge,
            receiptRequired: true, fiscalRoute: $route, driverId: $driver, driverContractVersion: 'v1', callbackTrustStrategy: $trust);
    }

    /** @param callable(): mixed $call */
    private static function refused(ProblemCode $problem, string $detail, callable $call): void
    {
        try {
            $call();
            self::fail('Expected '.$problem->value.': '.$detail);
        } catch (ProblemException $e) {
            self::assertSame([$problem, $detail], [$e->problem, $e->detail]);
        }
    }
}
