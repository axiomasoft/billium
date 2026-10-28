<?php

declare(strict_types=1);

namespace Billium\Platform\Internal;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Contracts\CallerContext;
use Billium\Core\Contracts\FinancialSessionAdmission;
use Billium\Core\Contracts\Modules\ModuleAdmissionGate;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Modules\ModuleStateReader;
use Billium\Core\Contracts\Modules\ModuleTransitionEvidence;
use Billium\Core\Contracts\Modules\ModuleTransitionPlanner;
use Billium\Core\Contracts\OperationRunner;
use Billium\Platform\Contracts\IdempotencyStore;
use Billium\Platform\Contracts\Installation;
use Billium\Platform\Contracts\Outbox;
use Billium\Platform\Contracts\RootAdmission;
use Billium\Platform\Internal\Idempotency\DatabaseIdempotencyStore;
use Billium\Platform\Internal\Installation\DatabaseInstallation;
use Billium\Platform\Internal\Installation\InitInstallationCommand;
use Billium\Platform\Internal\Installation\PlatformRootAdmission;
use Billium\Platform\Internal\Installation\RuntimeGrants;
use Billium\Platform\Internal\Outbox\OutboxRecorder;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RuntimeGrants::class, static fn (): RuntimeGrants => new RuntimeGrants(self::connection()));
        $this->app->singleton(IdempotencyStore::class, static fn (Application $app): DatabaseIdempotencyStore => new DatabaseIdempotencyStore(
            $app->make(DatabaseManager::class)->connection(self::connection()),
            self::int('billium.idempotency.lock_timeout_ms', 5000),
        ));
        $this->app->singleton(RootAdmission::class, static fn (Application $app): PlatformRootAdmission => new PlatformRootAdmission(
            $app->make(DatabaseManager::class),
            static fn (): FinancialSessionAdmission => $app->make(FinancialSessionAdmission::class),
            $app->make(ModuleRegistry::class),
            $app->make(ModuleAdmissionGate::class),
        ));
        $this->app->singleton(Outbox::class, static fn (Application $app): OutboxRecorder => new OutboxRecorder(
            $app->make(DatabaseManager::class),
            new Codec,
            static fn (): OperationRunner => $app->make(OperationRunner::class),
        ));
        $this->app->singleton(DatabaseInstallation::class, static function (Application $app): DatabaseInstallation {
            $enabled = config('billium.modules.enabled');

            return new DatabaseInstallation(
                $app->make(DatabaseManager::class),
                self::connection(),
                $app->make(OperationRunner::class),
                static fn (): CallerContext => $app->make(CallerContext::class),
                $app->make(ModuleRegistry::class),
                $app->make(ModuleTransitionPlanner::class),
                is_array($enabled) ? array_values(array_filter($enabled, is_string(...))) : [],
                self::int('billium.financial_session.seal_lock_timeout_ms', 5000),
                static fn (): ?ModuleTransitionEvidence => $app->bound(ModuleTransitionEvidence::class) ? $app->make(ModuleTransitionEvidence::class) : null,
            );
        });
        $this->app->alias(DatabaseInstallation::class, Installation::class);
        $this->app->alias(DatabaseInstallation::class, ModuleStateReader::class);
    }

    public function boot(): void
    {
        // Capture the host runtime before migrate --database temporarily changes database.default to the owner.
        $this->app->make(RuntimeGrants::class);
        if ($this->app->runningInConsole()) {
            $this->commands([InitInstallationCommand::class]);
        }
    }

    private static function connection(): string
    {
        $name = config('billium.connection') ?? config('database.default');

        return is_string($name) ? $name : 'default';
    }

    private static function int(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) ? $value : $default;
    }
}
