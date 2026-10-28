<?php

declare(strict_types=1);

namespace Billium\Core;

use Billium\Core\Contracts\Modules\ModuleAdmissionGate;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Modules\ModuleTransitionPlanner;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Core\Internal\Modules\ModuleLifecycle;
use Billium\Ledger\Internal\LedgerServiceProvider;
use Billium\Platform\Internal\PlatformServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class BilliumCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/billium.php', 'billium');
        // D55: packaged descriptors only; validation is explicit (doctor/readiness), never a hidden boot side effect.
        $this->app->singleton(ModuleRegistry::class, static fn (): ModuleCatalog => ModuleCatalog::packaged());
        $this->app->singleton(ModuleLifecycle::class, static fn (Application $app): ModuleLifecycle => new ModuleLifecycle($app->make(ModuleRegistry::class)));
        $this->app->alias(ModuleLifecycle::class, ModuleAdmissionGate::class);
        $this->app->alias(ModuleLifecycle::class, ModuleTransitionPlanner::class);
        $this->app->register(PlatformServiceProvider::class);
        $this->app->register(LedgerServiceProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom([
            __DIR__.'/../database/migrations/platform',
            __DIR__.'/../database/migrations/ledger',
        ]);
    }
}
