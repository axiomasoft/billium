<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit\Plugins;

use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Core\Plugins\LedgerPlugin;
use Billium\Core\Plugins\PlatformPlugin;
use Billium\Core\Plugins\RatingPlugin;
use Billium\Core\Plugins\SchedulePlugin;
use Billium\Core\Plugins\UsagePlugin;
use Billium\Rating\Contracts\RatingBooking;
use Billium\Rating\Contracts\RatingCorrection;
use Billium\Rating\Contracts\RatingPreparer;
use Billium\Rating\Contracts\RatingQuoter;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use Packman\Plugins\Contracts\Contributions\Capabilities;
use Packman\Plugins\Contracts\Registry\PluginInspector;
use Packman\Plugins\Testing\PluginGraph;

final class ModulePluginsTest extends TestCase
{
    public function test_all_module_plugins_compile_deterministically_through_packman_plugin_graph(): void
    {
        $registry = PluginGraph::compile(
            $this->app,
            [],
            corePluginId: 'axiomasoft.billium.platform',
            builtIn: [
                PlatformPlugin::class,
                LedgerPlugin::class,
                UsagePlugin::class,
                SchedulePlugin::class,
                RatingPlugin::class,
            ],
        );

        $ids = array_map(static fn ($d): string => $d->id, $registry->describe());
        self::assertContains('axiomasoft.billium.platform', $ids);
        self::assertContains('axiomasoft.billium.ledger', $ids);
        self::assertContains('axiomasoft.billium.usage', $ids);
        self::assertContains('axiomasoft.billium.schedule', $ids);
        self::assertContains('axiomasoft.billium.rating', $ids);
        self::assertNotEmpty($registry->providers());
        self::assertNotEmpty($registry->slotContributions());
    }

    public function test_schedule_plugin_dependencies_match_module_descriptor(): void
    {
        $descriptor = ModuleCatalog::packaged()->descriptor('schedule');
        self::assertNotNull($descriptor);
        $expected = array_map(
            static fn (string $moduleId): string => 'axiomasoft.billium.'.$moduleId,
            $descriptor->requiredModuleIds(),
        );
        self::assertSame($expected, (new SchedulePlugin)->manifest()->requires);
    }

    public function test_every_advertised_capability_resolves_across_all_valid_module_subsets(): void
    {
        $subsets = [
            ['platform'],
            ['platform', 'ledger'],
            ['platform', 'usage'],
            ['platform', 'rating'],
            ['platform', 'ledger', 'rating'],
            ['platform', 'rating', 'schedule'],
            ['platform', 'ledger', 'usage', 'rating', 'schedule'],
        ];

        $containerBackup = Container::getInstance();
        try {
            foreach ($subsets as $enabled) {
                $app = new Application(dirname(__DIR__, 4));
                Container::setInstance($app);
                $app->instance('config', new Repository([
                    'database' => [
                        'default' => 'sqlite',
                        'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
                    ],
                    'billium' => [
                        'modules' => ['enabled' => $enabled],
                        'feed' => ['signing_key' => str_repeat('k', 32)],
                        'usage' => ['enabled' => in_array('usage', $enabled, true)],
                        'rating' => ['enabled' => in_array('rating', $enabled, true)],
                        'schedule' => ['enabled' => in_array('schedule', $enabled, true)],
                    ],
                ]));
                $app->register(DatabaseServiceProvider::class);
                $app->register(BilliumCoreServiceProvider::class);
                $app->boot();

                $capabilities = $app->make(Capabilities::class);
                $inspector = $app->make(PluginInspector::class);

                $advertised = array_keys($inspector->providers());
                self::assertNotEmpty($advertised, implode(',', $enabled));

                foreach ($advertised as $contract) {
                    if ($capabilities->has($contract)) {
                        self::assertInstanceOf($contract, $capabilities->require($contract), implode(',', $enabled).' :: '.$contract);
                        self::assertInstanceOf($contract, $app->make($contract), implode(',', $enabled).' :: '.$contract);
                    }
                }

                if ($enabled === ['platform', 'ledger', 'rating']) {
                    self::assertTrue($capabilities->has(RatingQuoter::class));
                    self::assertFalse($capabilities->has(RatingPreparer::class));
                    self::assertFalse($capabilities->has(RatingBooking::class));
                    self::assertFalse($capabilities->has(RatingCorrection::class));
                    self::assertFalse($app->bound(RatingPreparer::class));
                    self::assertFalse($app->bound(RatingBooking::class));
                    self::assertFalse($app->bound(RatingCorrection::class));
                }
            }
        } finally {
            Container::setInstance($containerBackup);
        }
    }
}
