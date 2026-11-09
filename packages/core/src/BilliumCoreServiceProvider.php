<?php

declare(strict_types=1);

namespace Billium\Core;

use Billium\Core\Contracts\CallerContext;
use Billium\Core\Contracts\FinancialSessionAdmission;
use Billium\Core\Contracts\Modules\ModuleAdmissionGate;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Modules\ModuleTransitionPlanner;
use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Core\Internal\Modules\ModuleLifecycle;
use Billium\Core\Internal\Runner\DatabaseOperationRunner;
use Billium\Core\Internal\Runner\UnboundCallerContext;
use Billium\Core\Internal\Runner\UnboundSessionAdmission;
use Billium\Ledger\Internal\LedgerServiceProvider;
use Billium\Platform\Contracts\IdempotencyStore;
use Billium\Platform\Contracts\RootAdmission;
use Billium\Platform\Internal\PlatformServiceProvider;
use Billium\Usage\Internal\UsageServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
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
        // Host SPIs fail closed until the host binds its authenticated caller and its process-token authority (D62).
        $this->app->bindIf(CallerContext::class, UnboundCallerContext::class, shared: true);
        $this->app->bindIf(FinancialSessionAdmission::class, UnboundSessionAdmission::class, shared: true);
        $this->app->singleton(OperationRunner::class, static function (Application $app): DatabaseOperationRunner {
            $connection = config('billium.connection') ?? config('database.default');
            $attempts = config('billium.runner.max_attempts', 3);
            $deadline = config('billium.runner.root_deadline_ms', 30_000);

            return new DatabaseOperationRunner(
                $app->make(DatabaseManager::class)->connection(is_string($connection) ? $connection : null),
                static fn (): CallerContext => $app->make(CallerContext::class),
                $app->make(IdempotencyStore::class),
                $app->make(RootAdmission::class),
                is_int($attempts) ? $attempts : 3,
                is_int($deadline) ? $deadline : 30_000,
            );
        });
        $this->app->register(PlatformServiceProvider::class);
        $this->app->register(LedgerServiceProvider::class);
        $this->registerCommercialModules();
    }

    private function registerCommercialModules(): void
    {
        // D55/D56: enroll module-owned providers only when enabled and available.
        $providers = [
            'usage' => UsageServiceProvider::class,
            'rating' => 'Billium\\Rating\\Internal\\RatingServiceProvider',
            'schedule' => 'Billium\\Schedule\\Internal\\ScheduleServiceProvider',
        ];
        $enabled = config('billium.modules.enabled', []);
        foreach ($providers as $module => $provider) {
            if (! is_array($enabled) || ! in_array($module, $enabled, true)
                || config("billium.{$module}.enabled") !== true) {
                continue;
            }
            $this->registerAvailableProvider($provider);
        }
    }

    private function registerAvailableProvider(string $provider): void
    {
        if (class_exists($provider) && is_subclass_of($provider, ServiceProvider::class)) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom([
            __DIR__.'/../database/migrations/platform',
            __DIR__.'/../database/migrations/ledger',
        ]);
    }
}
