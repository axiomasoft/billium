<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\PaymentDriver;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Testkit\Drivers\SimulatedCapabilities;
use DateTimeImmutable;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Group;

/** Evidence and inventory are trusted only while the live driver still matches the frozen merchant capability and original binding. */
#[Group('pg')]
final class CapabilityDriftTest extends ExecutionTestCase
{
    private static function drift(Application $app): void
    {
        $current = $app->make(PaymentDrivers::class);
        $app->instance(PaymentDrivers::class, new readonly class($current) implements PaymentDrivers
        {
            public function __construct(private PaymentDrivers $current) {}

            public function resolve(string $driverId, string $contractVersion): PaymentDriver
            {
                return $this->current->resolve($driverId, $contractVersion);
            }

            public function capabilities(MerchantRevision $merchant): CapabilitySnapshot
            {
                return SimulatedCapabilities::snapshot(absence: false);
            }
        });
    }

    private function refused(ProblemCode $problem, string $detail, callable $call): void
    {
        try {
            $call();
            self::fail('Expected '.$detail);
        } catch (ProblemException $failure) {
            self::assertSame($problem, $failure->problem);
            self::assertSame($detail, $failure->detail);
        }
    }

    public function test_a_callback_is_quarantined_when_the_live_driver_capability_drifted(): void
    {
        $journal = Fixture::tempPath();
        try {
            $app = $this->application();
            $event = CallbackCustodyTest::dispatched($app, $journal);
            self::drift($app);
            $this->refused(ProblemCode::AuthDenied, 'Callback failed authentication and was retained',
                static fn () => $app->make(CallbackCustody::class)->receive(Fixture::scope(), 'merchant-a', 'root-a', $event));
            self::assertSame(1, $this->rows('billium_execution_provider_work', "state='quarantined'"));
            self::assertSame(0, $this->rows('billium_execution_provider_fact'));
        } finally {
            UnknownResultTest::clean($journal);
        }
    }

    public function test_a_callback_naming_another_binding_than_the_original_attempt_is_quarantined(): void
    {
        $journal = Fixture::tempPath();
        try {
            $app = $this->application();
            $event = CallbackCustodyTest::dispatched($app, $journal);
            $forged = CallbackCustodyTest::signed($event, ['bindingDigest' => 'sha256:'.str_repeat('0', 64)]);
            $this->refused(ProblemCode::AuthDenied, 'Callback failed authentication and was retained',
                static fn () => $app->make(CallbackCustody::class)->receive(Fixture::scope(), 'merchant-a', 'root-a', $forged));
            self::assertSame(1, $this->rows('billium_execution_provider_work', "state='quarantined'"));
        } finally {
            UnknownResultTest::clean($journal);
        }
    }

    public function test_inventory_is_refused_when_the_live_driver_capability_drifted(): void
    {
        $app = $this->application();
        $path = Fixture::tempPath();
        try {
            CallbackCustodyTest::dispatched($app, $path);
            InventoryReconciliationTest::enableInventory($app, $path);
            InventoryReconciliationTest::grant($this->callers);
            InventoryReconciliationTest::page($path, []);
            self::drift($app);
            $this->refused(ProblemCode::CapabilityUnsupported, 'The frozen merchant lacks a qualified bounded inventory reader',
                static fn (): string => InventoryReconciliationTest::scan($app, 'drifted-inventory', new DateTimeImmutable('-1 hour'), new DateTimeImmutable));
            self::assertSame(0, $this->rows('billium_execution_recon_page'));
        } finally {
            InventoryReconciliationTest::clear($path);
        }
    }
}
