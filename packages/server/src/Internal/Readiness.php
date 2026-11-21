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
use Billium\Server\Contracts\SellerContextResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Packman\Attributes\Internal;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Data\DoctorFinding;
use Packman\Doctor\Enums\CheckStatus;
use PDOException;

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

    public function ready(): bool
    {
        $composition = $this->modules->manifest()->composition($this->options->modules->composition);
        if ($composition === null || $composition->server === ModulePresence::Forbidden
            || ! $this->app->bound(Authenticator::class) || ! $this->app->bound(SellerContextResolver::class)
            || ! $this->app->bound(AuthorizationVerdict::class)) {
            return false;
        }

        try {
            $database = $this->databases->connection($this->options->connection);
            if ($database->getDriverName() !== 'pgsql') {
                return false;
            }
            $version = $database->scalar("SELECT current_setting('server_version_num')::integer", [], false);
            if (! is_int($version) || $version < 160000) {
                return false;
            }
        } catch (InvalidArgumentException|PDOException) {
            return false;
        }

        // Force real checkpoints even in non-production environments. Doctor is a read-only check.
        return array_all(
            $this->doctor->run(new DoctorContext(production: true)),
            static fn (DoctorFinding $finding): bool => $finding->status !== CheckStatus::Error,
        );
    }
}
