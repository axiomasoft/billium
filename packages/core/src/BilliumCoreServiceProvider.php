<?php

declare(strict_types=1);

namespace Billium\Core;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Internal\Runtime\ExecutionCompositionGuard;
use Billium\Core\Laravel\Registrars\BilliumCoreRegistrar;
use Billium\Execution\Internal\ExecutionServiceProvider;
use Billium\Ledger\Internal\LedgerServiceProvider;
use Billium\Platform\Internal\PlatformServiceProvider;
use Billium\Rating\Internal\RatingServiceProvider;
use Billium\Schedule\Internal\ScheduleServiceProvider;
use Billium\Usage\Internal\UsageServiceProvider;
use Illuminate\Support\ServiceProvider;
use Override;
use Packman\Attributes\Api;
use Packman\Foundation\Concerns\RegistersPackageRegistrars;
use Packman\Foundation\Contracts\PackageRegistrar;

/**
 * Slim Laravel service provider bootstrapping Billium Core, Platform, and Ledger registrars.
 */
#[Api(since: '1.0')]
final class BilliumCoreServiceProvider extends ServiceProvider
{
    use RegistersPackageRegistrars {
        register as protected registerRegistrars;
        boot as protected bootRegistrars;
    }

    /** @var list<class-string<PackageRegistrar>> */
    protected const array REGISTRARS = [
        BilliumCoreRegistrar::class,
    ];

    #[Override]
    public function register(): void
    {
        $this->registerRegistrars();
        $this->app->register(PlatformServiceProvider::class);
        $options = $this->app->make(BilliumOptions::class);
        if (in_array('ledger', $options->modules->enabled, true)) {
            $this->app->register(LedgerServiceProvider::class);
        }
        if (in_array('execution', $options->modules->enabled, true)) {
            new ExecutionCompositionGuard()->assertReady($this->app, $options);
            $this->app->register(ExecutionServiceProvider::class);
        }

        $providers = [
            'usage' => [UsageServiceProvider::class, $options->usage->enabled],
            'rating' => [RatingServiceProvider::class, $options->rating->enabled],
            'schedule' => [ScheduleServiceProvider::class, $options->schedule->enabled],
        ];
        foreach ($providers as $module => [$provider, $enabled]) {
            if ($enabled && in_array($module, $options->modules->enabled, true) && class_exists($provider)) {
                $this->app->register($provider);
            }
        }
    }

    public function boot(): void
    {
        $this->bootRegistrars();
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations/platform');
    }
}
