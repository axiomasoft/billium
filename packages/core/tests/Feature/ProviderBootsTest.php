<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Core\BilliumCoreServiceProvider;
use Orchestra\Testbench\TestCase;

final class ProviderBootsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BilliumCoreServiceProvider::class];
    }

    public function test_provider_is_registered_on_laravel_13(): void
    {
        self::assertNotEmpty($this->app->getProviders(BilliumCoreServiceProvider::class));
        self::assertStringStartsWith('13.', $this->app->version());
    }
}
