<?php

declare(strict_types=1);

namespace Billium\Core;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Laravel\Registrars\BilliumCoreRegistrar;
use Billium\Execution\Contracts\ExecutionReadiness;
use Billium\Execution\Internal\ExecutionServiceProvider;
use Billium\Ledger\Internal\LedgerServiceProvider;
use Billium\Platform\Internal\PlatformServiceProvider;
use Billium\Rating\Internal\RatingServiceProvider;
use Billium\Schedule\Internal\ScheduleServiceProvider;
use Billium\Usage\Internal\UsageServiceProvider;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Override;
use Packman\Attributes\Api;
use Packman\Config\Laravel\ConfigurationValidator;
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
            $validator = new ConfigurationValidator;
            if (! in_array($options->modules->composition, ['remote-billium', 'standalone-billium'], true)) {
                $validator->add('billium.modules.composition', 'execution requires remote-billium or standalone-billium');
            }
            if (! in_array('platform', $options->modules->enabled, true) || ! in_array('ledger', $options->modules->enabled, true)) {
                $validator->add('billium.modules.enabled', 'an execution composition requires platform and ledger');
            }
            $validator->failIfAny();
            if ($options->execution->outgoingEnabled) {
                $release = $options->execution->releaseEvidenceRef;
                $retention = $options->execution->retentionPolicyRef;
                if ($release === null) {
                    $validator->add('billium.execution.release_evidence_ref', 'is required for outgoing startup');
                }
                if ($retention === null) {
                    $validator->add('billium.execution.retention_policy_ref', 'is required for outgoing startup');
                }
                if (! $this->app->bound(ExecutionReadiness::class)) {
                    $validator->add('billium.execution.outgoing_enabled', 'requires ExecutionReadiness verification of qualification, authority and hard-fence integration');
                }
                $validator->failIfAny();
                if ($release !== null && $retention !== null) {
                    foreach ($this->app->make(DatabaseManager::class)->getConnections() as $connection) {
                        if ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction()) {
                            throw new LogicException('Execution startup verification requires transaction level zero');
                        }
                    }
                    try {
                        $this->app->make(ExecutionReadiness::class)->assertConfigured($release, $retention);
                    } catch (ProblemException $error) {
                        $validator->add('billium.execution.outgoing_enabled', 'ExecutionReadiness refused configured operational evidence: '.$error->problem->value);
                        $validator->failIfAny();
                    }
                }
            }
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
