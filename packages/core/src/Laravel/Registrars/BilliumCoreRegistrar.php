<?php

declare(strict_types=1);

namespace Billium\Core\Laravel\Registrars;

use Billium\Contracts\Errors\BilliumProblemDefinitions;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Modules\ModuleAdmissionGate;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Modules\ModuleTransitionPlanner;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\AuthenticatedObservationContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\EventPublisher;
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
use Billium\Core\Plugins\RatingPlugin;
use Billium\Core\Plugins\SchedulePlugin;
use Billium\Core\Plugins\UsagePlugin;
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
use Billium\Platform\Contracts\EventContracts\EventContractRegistrar;
use Billium\Platform\Contracts\Feed\EventFeed;
use Billium\Platform\Contracts\Feed\FeedPartitions;
use Billium\Platform\Contracts\Feed\FeedRecoveryQueries;
use Billium\Platform\Contracts\Feed\SnapshotSource;
use Billium\Platform\Contracts\Idempotency\IdempotencyStore;
use Billium\Platform\Contracts\Inbox\BootstrapItemHandler;
use Billium\Platform\Contracts\Inbox\CustodyHandler;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Platform\Contracts\Installation\RootAdmission;
use Billium\Platform\Contracts\Outbox\CommandOutbox;
use Billium\Platform\Contracts\Outbox\Outbox;
use Illuminate\Container\Container;
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
use Packman\Plugins\Contracts\Registry\PluginActivationGate;
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
    /** @var array<class-string, array{0: string, 1: string}> */
    private const array LEGACY_CONTRACT_SPECS = [
        CallerContext::class => ['Core', 'CallerContext'],
        EventPublisher::class => ['Core', 'EventPublisher'],
        FinancialSessionAdmission::class => ['Core', 'FinancialSessionAdmission'],
        OperationRunner::class => ['Core', 'OperationRunner'],
        TransactionContext::class => ['Core', 'TransactionContext'],
        BootstrapItemHandler::class => ['Platform', 'BootstrapItemHandler'],
        CommandOutbox::class => ['Platform', 'CommandOutbox'],
        CustodyHandler::class => ['Platform', 'CustodyHandler'],
        EventContractRegistrar::class => ['Platform', 'EventContractRegistrar'],
        EventFeed::class => ['Platform', 'EventFeed'],
        FeedPartitions::class => ['Platform', 'FeedPartitions'],
        FeedRecoveryQueries::class => ['Platform', 'FeedRecoveryQueries'],
        IdempotencyStore::class => ['Platform', 'IdempotencyStore'],
        Installation::class => ['Platform', 'Installation'],
        Outbox::class => ['Platform', 'Outbox'],
        RootAdmission::class => ['Platform', 'RootAdmission'],
        SnapshotSource::class => ['Platform', 'SnapshotSource'],
    ];

    /** @var array<class-string, true> */
    private const array HOST_PORT_CONTRACTS = [
        CallerContext::class => true,
        FinancialSessionAdmission::class => true,
        EventPublisher::class => true,
    ];

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

        $app->singletonIf(BilliumOptions::class, static function (Application $app): BilliumOptions {
            $raw = $app->bound(Repository::class) ? $app->make(Repository::class)->get('billium', []) : [];

            return BilliumOptions::fromArray(is_array($raw) ? $raw : ['__invalid_root' => $raw], 'billium');
        });

        ExtensionRegistrar::builtIn(
            PlatformPlugin::class,
            LedgerPlugin::class,
            ExecutionPlugin::class,
            UsagePlugin::class,
            SchedulePlugin::class,
            RatingPlugin::class,
        );

        $app->singletonIf(PluginActivationGate::class, static fn (Application $app): PluginActivationGate => new class($app) implements PluginActivationGate
        {
            public function __construct(private Application $app) {}

            public function isEnabled(string $pluginId): bool
            {
                $options = $this->app->make(BilliumOptions::class);
                $enabled = $options->modules->enabled;

                return match ($pluginId) {
                    'axiomasoft.billium.platform' => true,
                    'axiomasoft.billium.ledger' => in_array('ledger', $enabled, true),
                    'axiomasoft.billium.execution' => in_array('execution', $enabled, true),
                    'axiomasoft.billium.usage' => $options->usage->enabled && in_array('usage', $enabled, true),
                    'axiomasoft.billium.schedule' => $options->schedule->enabled && in_array('schedule', $enabled, true),
                    'axiomasoft.billium.rating' => $options->rating->enabled && in_array('rating', $enabled, true),
                    default => true,
                };
            }
        });

        $coreManifest = dirname(__DIR__, 3).'/composer.json';
        $plugins = [
            'axiomasoft.billium.platform' => PlatformPlugin::class,
            'axiomasoft.billium.ledger' => LedgerPlugin::class,
            'axiomasoft.billium.execution' => ExecutionPlugin::class,
            'axiomasoft.billium.usage' => UsagePlugin::class,
            'axiomasoft.billium.schedule' => SchedulePlugin::class,
            'axiomasoft.billium.rating' => RatingPlugin::class,
        ];
        foreach ($plugins as $pluginClass) {
            $app->singletonIf($pluginClass);
        }

        $app->singleton(TransportObservationContext::class, static fn (Application $app): TransportObservationContext => new TransportObservationContext(
            static fn (): IncomingCommandAuthenticator => $app->make(IncomingCommandAuthenticator::class),
            $app->make(DatabaseManager::class),
        ));
        $app->alias(TransportObservationContext::class, AuthenticatedObservationContext::class);
        $app->bindIf(InternalCommandReceiver::class, PaymentObservationCommandReceiver::class);

        $app->singleton(BilliumCompatibilityDoctorCheck::class, static fn (Application $app): BilliumCompatibilityDoctorCheck => new BilliumCompatibilityDoctorCheck(
            config: $app->make(Repository::class),
            registry: $app->make(ModuleRegistry::class),
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
            databases: $app->make(DatabaseManager::class),
        ));

        ConfigSectionRegistration::own($app, 'billium', static function (array $section): void {
            BilliumOptions::fromArray($section, 'billium');
        });

        if ($app->bound(Repository::class)) {
            $repo = $app->make(Repository::class);
            if (! $repo->has('packman.schema.module_prerequisite_set')) {
                $repo->set('packman.schema.module_prerequisite_set', 'axiomasoft/billium-core:billium-platform');
            }
        }

        foreach ($plugins as $ownerId => $pluginClass) {
            ExtensionFacetRegistrar::register($app, $pluginClass, $coreManifest);
            SchemaExtensions::register($app, $pluginClass, $ownerId);
        }

        // D55: packaged descriptors only; validation is explicit (doctor/readiness), never a hidden boot side effect.
        $app->singleton(ModuleRegistry::class, static fn (): ModuleCatalog => ModuleCatalog::packaged());
        $app->singleton(ModuleLifecycle::class, static fn (Application $app): ModuleLifecycle => new ModuleLifecycle($app->make(ModuleRegistry::class)));
        $app->alias(ModuleLifecycle::class, ModuleAdmissionGate::class);
        $app->alias(ModuleLifecycle::class, ModuleTransitionPlanner::class);

        self::registerHostPortFallbacks($app);
        self::registerLegacyContractAliases($app);
        $app->bindIf(FinancialAuthorityEvidence::class, UnboundFinancialAuthorityEvidence::class, shared: true);

        $app->singletonIf(OperationRunner::class, static function (Application $app): DatabaseOperationRunner {
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
        $legacy = isset(self::LEGACY_CONTRACT_SPECS[$contract]) ? self::legacyName($contract) : null;

        if (! $app->bound($contract) && ($legacy === null || ! $app->bound($legacy))) {
            return false;
        }

        try {
            $resolved = $app->make($contract);

            return is_object($resolved) && $resolved::class !== $default;
        } catch (Throwable) {
            return true;
        }
    }

    private static function registerHostPortFallbacks(Application $app): void
    {
        // Host SPIs fail closed until the host binds its authenticated caller and process-token authority (D62),
        // while still forwarding host bindings registered under pre-reorganization legacy interface aliases.
        foreach ([
            CallerContext::class => UnboundCallerContext::class,
            FinancialSessionAdmission::class => UnboundSessionAdmission::class,
        ] as $canonical => $default) {
            $app->singletonIf($default);
            $legacy = self::legacyName($canonical);

            $app->bindIf(
                $canonical,
                static fn (Application $app): mixed => self::isLegacyConcreteBound($app, $legacy, $canonical)
                    ? $app->make($legacy)
                    : $app->make($default),
            );
        }

        $legacyPublisher = self::legacyName(EventPublisher::class);
        $app->beforeResolving(EventPublisher::class, static function (string $abstract, array $parameters, mixed $container) use ($legacyPublisher): void {
            if (! $container instanceof Application) {
                return;
            }
            if (! $container->bound(EventPublisher::class) && self::isLegacyConcreteBound($container, $legacyPublisher, EventPublisher::class)) {
                $container->bind(EventPublisher::class, static fn (Application $app): mixed => $app->make($legacyPublisher));
            }
        });
    }

    private static function registerLegacyContractAliases(Application $app): void
    {
        foreach (array_keys(self::LEGACY_CONTRACT_SPECS) as $canonical) {
            $legacy = self::legacyName($canonical);
            if (self::isLegacyConcreteBound($app, $legacy, $canonical)) {
                if (! $app->bound($canonical) || isset(self::HOST_PORT_CONTRACTS[$canonical])) {
                    $app->bind($canonical, static fn (Application $app): mixed => $app->make($legacy));
                }

                continue;
            }

            if (! $app->bound($legacy)) {
                $app->alias($canonical, $legacy);
            }
        }
    }

    /** @param class-string $canonical */
    private static function legacyName(string $canonical): string
    {
        [$module, $name] = self::LEGACY_CONTRACT_SPECS[$canonical];

        return 'Billium\\'.$module.'\\Contracts\\'.$name;
    }

    /** @param class-string $canonical */
    private static function isLegacyConcreteBound(Application $app, string $legacy, string $canonical): bool
    {
        if (! $app->bound($legacy)) {
            return false;
        }

        // A legacy name that merely aliases the canonical contract is not a host binding of its own.
        return ! $app instanceof Container || ! self::aliases($app, $legacy, $canonical);
    }

    private static function aliases(Container $container, string $alias, string $abstract): bool
    {
        return $container->isAlias($alias) && $container->getAlias($alias) === $abstract;
    }
}
