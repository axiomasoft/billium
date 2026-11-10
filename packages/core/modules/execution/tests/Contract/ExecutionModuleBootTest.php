<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Contract;

use Billium\Core\BilliumCoreServiceProvider;
use Billium\Execution\Contracts\CapabilityGate;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Internal\ExecutionServiceProvider;
use Orchestra\Testbench\TestCase;

final class ExecutionModuleBootTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BilliumCoreServiceProvider::class];
    }

    public function test_execution_contracts_boot_without_provider_credentials_or_mutation_authority(): void
    {
        self::assertFalse(config('billium.execution.enabled'));
        self::assertEmpty($this->app->getProviders(ExecutionServiceProvider::class));
        $this->app->register(ExecutionServiceProvider::class);
        self::assertInstanceOf(CapabilityGate::class, $this->app->make(CapabilityGate::class));
        self::assertFalse($this->app->bound(PaymentDrivers::class));
        self::assertFalse($this->app->bound(WriterAdmission::class));
        self::assertSame([], $this->app['router']->getRoutes()->getRoutes());
    }
}
