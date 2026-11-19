<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Feature;

use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Usage\Contracts\AggregationParticipants;
use Billium\Usage\Contracts\AggregationQueries;
use Billium\Usage\Contracts\AggregationService;
use Billium\Usage\Contracts\SourceCompletenessIntake;
use Billium\Usage\Contracts\Usage;
use Billium\Usage\Laravel\UsageServiceProvider;
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
        $app->boot();
        self::assertFalse($app->bound(Usage::class));
        self::assertArrayNotHasKey(UsageServiceProvider::class, $app->getLoadedProviders());
        self::assertSame(['platform', 'ledger'], $app->make(\Illuminate\Contracts\Config\Repository::class)->get('billium.modules.enabled'));
        $descriptor = ModuleCatalog::packaged()->descriptor('usage');
        self::assertNotNull($descriptor);
        self::assertSame(4, $descriptor->currentSchemaVersion);
        self::assertCount(4, $descriptor->migrationIdentities);
        Container::setInstance();
    }

    public function test_explicit_host_enable_activates_owned_provider_at_boot_without_installing(): void
    {
        $app = new Application(dirname(__DIR__, 6));
        $app->instance('config', new Repository(['billium' => ['modules' => ['enabled' => ['platform', 'ledger', 'usage']], 'usage' => ['enabled' => true]]]));
        $app->register(BilliumCoreServiceProvider::class);
        $app->boot();
        self::assertTrue($app->bound(Usage::class));
        foreach ([AggregationService::class, AggregationQueries::class, AggregationParticipants::class, SourceCompletenessIntake::class] as $contract) {
            self::assertTrue($app->bound($contract));
        }
        self::assertArrayHasKey(UsageServiceProvider::class, $app->getLoadedProviders());
        self::assertTrue($app->isBooted());
        Container::setInstance();
    }
}
