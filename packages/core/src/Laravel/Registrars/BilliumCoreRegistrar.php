<?php

declare(strict_types=1);

namespace Billium\Core\Laravel\Registrars;

use Billium\Contracts\Errors\BilliumProblemDefinitions;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Modules\ModuleAdmissionGate;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Modules\ModuleTransitionPlanner;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runtime\AuthenticatedObservationContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Contracts\Runtime\IncomingCommandAuthenticator;
use Billium\Core\Contracts\Runtime\InternalCommandReceiver;
use Billium\Core\Diagnostics\BilliumCompatibilityDoctorCheck;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Core\Internal\Modules\ModuleLifecycle;
use Billium\Core\Internal\Runner\DatabaseOperationRunner;
use Billium\Core\Internal\Runner\UnboundCallerContext;
use Billium\Core\Internal\Runner\UnboundFinancialAuthorityEvidence;
use Billium\Core\Internal\Runner\UnboundSessionAdmission;
use Billium\Core\Internal\Runtime\PaymentObservationCommandReceiver;
use Billium\Core\Internal\Runtime\TransportObservationContext;
use Billium\Core\Plugins\ExecutionPlugin;
use Billium\Core\Plugins\LedgerPlugin;
use Billium\Core\Plugins\PlatformPlugin;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\CallbackPayloadArchive;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\ExecutionOriginResolver;
use Billium\Execution\Contracts\ExecutionReadiness;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\OriginalIdentityArchive;
use Billium\Execution\Contracts\OriginalReplayAuthority;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\PaymentObservationSink;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Platform\Contracts\Idempotency\IdempotencyStore;
use Billium\Platform\Contracts\Installation\RootAdmission;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Config\Laravel\ConfigSectionRegistration;
use Packman\Errors\Laravel\ProblemDefinitions;
use Packman\Extensions\Laravel\ExtensionFacetRegistrar;
use Packman\Foundation\Contracts\PackageRegistrar;
use Packman\PackmanServiceProvider;
use Packman\Plugins\Laravel\ExtensionRegistrar;
use Packman\Plugins\Laravel\PluginsServiceProvider;
use Packman\Schema\Laravel\SchemaExtensions;
use Packman\Schema\Laravel\SchemaServiceProvider;
use Throwable;

/**
 * Registers Packman infrastructure, compiled Billium options, plugins, schema sets, doctor check, and core runner bindings.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class BilliumCoreRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        foreach ([PackmanServiceProvider::class, SchemaServiceProvider::class, PluginsServiceProvider::class] as $provider) {
            if ($app->getProviders($provider) === []) {
                $app->register($provider);
            }
        }

        $contractsManifest = ExtensionFacetRegistrar::discoverComposerManifest(BilliumProblemDefinitions::class)
            ?? dirname(__DIR__, 4).'/contracts/composer.json';
        ProblemDefinitions::register($app, $contractsManifest, BilliumProblemDefinitions::class);

        $app->bind(BilliumOptions::class, static function (Application $app): BilliumOptions {
            $raw = $app->bound(Repository::class) ? $app->make(Repository::class)->get('billium', []) : [];

            return BilliumOptions::fromArray(is_array($raw) ? $raw : ['__invalid_root' => $raw], 'billium');
        });

        ExtensionRegistrar::builtIn(PlatformPlugin::class, LedgerPlugin::class, ExecutionPlugin::class);

        $coreManifest = dirname(__DIR__, 3).'/composer.json';
        $app->singletonIf(PlatformPlugin::class);
        $app->singletonIf(LedgerPlugin::class);
        $app->singletonIf(ExecutionPlugin::class);
        $app->singleton(TransportObservationContext::class, static fn (Application $app): TransportObservationContext => new TransportObservationContext(
            static fn (): IncomingCommandAuthenticator => $app->make(IncomingCommandAuthenticator::class),
            $app->make(DatabaseManager::class),
        ));
        $app->alias(TransportObservationContext::class, AuthenticatedObservationContext::class);
        $app->bindIf(InternalCommandReceiver::class, PaymentObservationCommandReceiver::class);

        $app->singleton(BilliumCompatibilityDoctorCheck::class, static fn (Application $app): BilliumCompatibilityDoctorCheck => new BilliumCompatibilityDoctorCheck(
            config: $app->make(Repository::class),
            registry: $app->make(ModuleRegistry::class),
            optionsResolver: static fn (): BilliumOptions => $app->make(BilliumOptions::class),
            portsResolver: static function () use ($app): array {
                $ports = [];
                if (self::hasCustomPort($app, CallerContext::class, UnboundCallerContext::class)) {
                    $ports[] = 'AuthContext';
                    $ports[] = 'SellerContext';
                    $ports[] = 'AuthorizationVerdict';
                }
                if (self::hasCustomPort($app, FinancialSessionAdmission::class, UnboundSessionAdmission::class)) {
                    $ports[] = 'FinancialSessionAdmission';
                }
                if (self::hasCustomPort($app, FinancialAuthorityEvidence::class, UnboundFinancialAuthorityEvidence::class)) {
                    $ports[] = 'FinancialAuthorityEvidence';
                }

                // Report configured bindings without resolving host services or performing qualification I/O.
                foreach ([
                    'PaymentDrivers' => PaymentDrivers::class,
                    'WriterAdmission' => WriterAdmission::class,
                    'PaymentObservationSink' => PaymentObservationSink::class,
                    'DispatchEgress' => DispatchEgress::class,
                    'MerchantCredentials' => MerchantCredentials::class,
                    'ProviderAbsenceVerifier' => ProviderAbsenceVerifier::class,
                    'ExecutionOriginResolver' => ExecutionOriginResolver::class,
                    'AdapterQualification' => AdapterQualification::class,
                    'CallbackPayloadArchive' => CallbackPayloadArchive::class,
                    'ExecutionReadiness' => ExecutionReadiness::class,
                    'OriginalReplayAuthority' => OriginalReplayAuthority::class,
                    'OriginalIdentityArchive' => OriginalIdentityArchive::class,
                ] as $port => $contract) {
                    if ($app->bound($contract)) {
                        $ports[] = $port;
                    }
                }

                return $ports;
            },
        ));

        ExtensionFacetRegistrar::register($app, PlatformPlugin::class, $coreManifest);
        ExtensionFacetRegistrar::register($app, LedgerPlugin::class, $coreManifest);
        ExtensionFacetRegistrar::register($app, ExecutionPlugin::class, $coreManifest);

        ConfigSectionRegistration::own($app, 'billium', static function (array $section): void {
            BilliumOptions::fromArray($section, 'billium');
        });

        SchemaExtensions::register($app, PlatformPlugin::class, 'axiomasoft.billium.platform');
        SchemaExtensions::register($app, LedgerPlugin::class, 'axiomasoft.billium.ledger');
        SchemaExtensions::register($app, ExecutionPlugin::class, 'axiomasoft.billium.execution');

        // D55: packaged descriptors only; validation is explicit (doctor/readiness), never a hidden boot side effect.
        $app->singleton(ModuleRegistry::class, static fn (): ModuleCatalog => ModuleCatalog::packaged());
        $app->singleton(ModuleLifecycle::class, static fn (Application $app): ModuleLifecycle => new ModuleLifecycle($app->make(ModuleRegistry::class)));
        $app->alias(ModuleLifecycle::class, ModuleAdmissionGate::class);
        $app->alias(ModuleLifecycle::class, ModuleTransitionPlanner::class);

        // Host SPIs fail closed until the host binds its authenticated caller and its process-token authority (D62).
        $app->bindIf(CallerContext::class, UnboundCallerContext::class, shared: true);
        $app->bindIf(FinancialSessionAdmission::class, UnboundSessionAdmission::class, shared: true);
        $app->bindIf(FinancialAuthorityEvidence::class, UnboundFinancialAuthorityEvidence::class, shared: true);

        $app->singleton(OperationRunner::class, static function (Application $app): DatabaseOperationRunner {
            $options = $app->make(BilliumOptions::class);
            $databases = $app->make(DatabaseManager::class);

            return new DatabaseOperationRunner(
                $databases->connection($options->connection),
                static fn (): CallerContext => $app->make(CallerContext::class),
                $app->make(IdempotencyStore::class),
                $app->make(RootAdmission::class),
                $options->runner->maxAttempts,
                $options->runner->rootDeadlineMs,
            );
        });
    }

    #[Override]
    public function boot(Application $app): void {}

    /**
     * @param  class-string  $contract
     * @param  class-string  $default
     */
    private static function hasCustomPort(Application $app, string $contract, string $default): bool
    {
        if (! $app->bound($contract)) {
            return false;
        }

        try {
            $resolved = $app->make($contract);

            return is_object($resolved) && $resolved::class !== $default;
        } catch (Throwable) {
            return true;
        }
    }
}
