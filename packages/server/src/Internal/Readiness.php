<?php

declare(strict_types=1);

namespace Billium\Server\Internal;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Diagnostics\BilliumCompatibilityDoctorCheck;
use Billium\Core\Enums\Modules\ModulePresence;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Server\Contracts\Authenticator;
use Billium\Server\Contracts\AuthorizationVerdict;
use Billium\Server\Contracts\InstallationContextResolver;
use Billium\Server\Contracts\SellerContextResolver;
use Billium\Server\Contracts\SellerEligibility;
use Billium\Server\Internal\Http\HttpLimits;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Packman\Attributes\Internal;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\CheckStatus;
use PDOException;
use Throwable;

#[Internal]
final readonly class Readiness
{
    public function __construct(
        private Application $app,
        private BilliumOptions $options,
        private ModuleRegistry $modules,
        private DatabaseManager $databases,
        private BilliumCompatibilityDoctorCheck $doctor,
    ) {}

    public function acceptsObservations(): bool
    {
        return in_array($this->options->modules->composition, ['ledger-only', 'embedded-commerz'], true);
    }

    public function hasExecutionDelivery(): bool
    {
        return $this->options->modules->composition === 'remote-billium' && $this->app->bound(RootPaymentCommands::class);
    }

    public function hasAuthPorts(): bool
    {
        return $this->app->bound(Authenticator::class) && $this->app->bound(SellerContextResolver::class)
            && $this->app->bound(SellerEligibility::class) && $this->app->bound(AuthorizationVerdict::class);
    }

    public function ready(bool $read = false): bool
    {
        return $this->failure($read) === null;
    }

    public function failure(bool $read = false): ?string
    {
        $composition = $this->modules->manifest()->composition($this->options->modules->composition);
        if ($composition === null || $composition->server === ModulePresence::Forbidden) {
            return 'billium.server.composition_unavailable';
        }
        if (! $this->hasAuthPorts() || ! $this->app->bound(InstallationContextResolver::class)) {
            return 'billium.server.auth_unavailable';
        }

        try {
            $this->app->make(HttpLimits::class);
        } catch (Throwable) {
            return 'billium.server.limits_invalid';
        }

        try {
            $database = $this->databases->connection($this->options->connection);
            if ($database->getDriverName() !== 'pgsql') {
                return 'billium.server.database_incompatible';
            }
            $version = $database->scalar("SELECT current_setting('server_version_num')::integer", [], false);
            if (! is_int($version) || $version < 160000) {
                return 'billium.server.database_incompatible';
            }
        } catch (InvalidArgumentException|PDOException) {
            return 'billium.server.database_unavailable';
        }

        // Force real checkpoints even in non-production environments. Doctor is a read-only check.
        foreach ($this->doctor->run(new DoctorContext(production: true)) as $finding) {
            if ($finding->status !== CheckStatus::Error) {
                continue;
            }
            if ($finding->code === 'billium.modules.module.port_missing' && $finding->slice === 'execution') {
                if ($read) {
                    continue;
                }

                return 'billium.server.execution_ports_unavailable';
            }

            return match ($finding->code) {
                'billium.database.unavailable' => 'billium.server.database_unavailable',
                default => 'billium.server.modules_incompatible',
            };
        }

        return null;
    }
}
