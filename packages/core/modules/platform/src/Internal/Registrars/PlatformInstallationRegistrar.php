<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Registrars;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Modules\ModuleAdmissionGate;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Modules\ModuleStateReader;
use Billium\Core\Contracts\Modules\ModuleTransitionEvidence;
use Billium\Core\Contracts\Modules\ModuleTransitionPlanner;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Platform\Contracts\EventContracts\EventContractRegistrar;
use Billium\Platform\Contracts\Idempotency\IdempotencyStore;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Platform\Contracts\Installation\RootAdmission;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use Billium\Platform\Internal\Idempotency\DatabaseIdempotencyStore;
use Billium\Platform\Internal\Installation\DatabaseInstallation;
use Billium\Platform\Internal\Installation\PlatformRootAdmission;
use Billium\Platform\Internal\Installation\RuntimeGrants;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Override;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Internal]
final class PlatformInstallationRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->singleton(EventContractRegistrar::class, static function (Application $app): DatabaseEventContractRegistrar {
            $options = $app->make(BilliumOptions::class);
            $databases = $app->make(DatabaseManager::class);

            return new DatabaseEventContractRegistrar(
                $databases->connection($options->connection),
                $app->make(ModuleRegistry::class),
            );
        });

        $app->singleton(RuntimeGrants::class, static function (Application $app): RuntimeGrants {
            $config = $app->bound(Repository::class) ? $app->make(Repository::class) : null;
            $connection = $config !== null ? ($config->get('billium.connection') ?? $config->get('database.default')) : null;

            return new RuntimeGrants(is_string($connection) ? $connection : 'default');
        });

        $app->singleton(IdempotencyStore::class, static function (Application $app): DatabaseIdempotencyStore {
            $options = $app->make(BilliumOptions::class);
            $databases = $app->make(DatabaseManager::class);

            return new DatabaseIdempotencyStore(
                $databases->connection($options->connection),
                $options->idempotency->lockTimeoutMs,
            );
        });

        $app->singleton(RootAdmission::class, static fn (Application $app): PlatformRootAdmission => new PlatformRootAdmission(
            $app->make(DatabaseManager::class),
            static fn (): FinancialSessionAdmission => $app->make(FinancialSessionAdmission::class),
            $app->make(ModuleRegistry::class),
            $app->make(ModuleAdmissionGate::class),
        ));

        $app->singleton(DatabaseInstallation::class, static function (Application $app): DatabaseInstallation {
            $options = $app->make(BilliumOptions::class);
            $databases = $app->make(DatabaseManager::class);

            return new DatabaseInstallation(
                $databases,
                $options->connectionName($databases->getDefaultConnection()),
                $app->make(OperationRunner::class),
                static fn (): CallerContext => $app->make(CallerContext::class),
                $app->make(ModuleRegistry::class),
                $app->make(ModuleTransitionPlanner::class),
                $options->modules->enabled,
                $options->financialSession->sealLockTimeoutMs,
                static fn (): ?ModuleTransitionEvidence => $app->bound(ModuleTransitionEvidence::class) ? $app->make(ModuleTransitionEvidence::class) : null,
            );
        });

        $app->alias(DatabaseInstallation::class, Installation::class);
        $app->alias(DatabaseInstallation::class, ModuleStateReader::class);
    }

    #[Override]
    public function boot(Application $app): void
    {
        // Capture the host runtime before migrate --database temporarily changes database.default to the owner.
        $app->make(RuntimeGrants::class);
    }
}
