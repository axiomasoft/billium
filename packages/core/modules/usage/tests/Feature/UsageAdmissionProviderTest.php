<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Feature;

use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Usage\Contracts\Usage;
use Billium\Usage\Internal\UsageServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class UsageAdmissionProviderTest extends TestCase
{
    public function test_default_disabled_usage_has_no_provider_binding_or_registered_installation(): void
    {
        $app = new Application(dirname(__DIR__, 6));
        $app->instance('config', new Repository);
        $app->register(BilliumCoreServiceProvider::class);
        self::assertFalse($app->bound(Usage::class));
        self::assertArrayNotHasKey(UsageServiceProvider::class, $app->getLoadedProviders());
        self::assertSame(['platform', 'ledger'], $app['config']->get('billium.modules.enabled'));
        self::assertSame(1, ModuleCatalog::packaged()->descriptor('usage')->currentSchemaVersion);
        Container::setInstance(null);
    }

    public function test_explicit_host_enable_discovers_owned_provider_without_booting_or_installing(): void
    {
        $app = new Application(dirname(__DIR__, 6));
        $app->instance('config', new Repository(['billium' => [
            'modules' => ['enabled' => ['platform', 'ledger', 'usage']], 'usage' => ['enabled' => true],
        ]]));
        $app->register(BilliumCoreServiceProvider::class);
        self::assertTrue($app->bound(Usage::class));
        self::assertArrayHasKey(UsageServiceProvider::class, $app->getLoadedProviders());
        self::assertFalse($app->isBooted());
        Container::setInstance(null);
    }
}
