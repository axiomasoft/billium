<?php

declare(strict_types=1);

namespace Billium\Core\Plugins;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Modules\ModuleAdmissionGate;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Modules\ModuleStateReader;
use Billium\Core\Contracts\Modules\ModuleTransitionEvidence;
use Billium\Core\Contracts\Modules\ModuleTransitionPlanner;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\EventPublisher;
use Billium\Core\Contracts\Runtime\FinancialAuthorityEvidence;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Database\BilliumSchemaSetProvider;
use Billium\Core\Diagnostics\BilliumCompatibilityDoctorCheck;
use Billium\Core\Errors\BilliumCoreProblemDefinitions;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Core\Internal\Modules\ModuleLifecycle;
use Billium\Core\Internal\Runner\DatabaseOperationRunner;
use Billium\Core\Internal\Runner\UnboundCallerContext;
use Billium\Core\Internal\Runner\UnboundSessionAdmission;
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
use Billium\Platform\Laravel\PlatformCapabilities;
use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Composition\ContributesToSlots;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Configuration\Configurable;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Concerns\Runtime\ProvidesDiagnostics;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Composition\WithSlots;
use Packman\Extensions\Contracts\Configuration\WithConfiguration;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Extensions\Contracts\Runtime\WithDiagnostics;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Enums\Contributions\SlotKind;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * Stateless Packman extension plugin composing Billium Platform configuration, schema, capabilities, slots, and diagnostics.
 */
#[Api(since: '1.0')]
final class PlatformPlugin implements ExtensionPlugin, WithCapabilities, WithConfiguration, WithDiagnostics, WithSchema, WithSlots
{
    use Configurable;
    use ContributesToSlots;
    use OwnsSchema;
    use ProvidesCapabilities;
    use ProvidesDiagnostics;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.billium.platform',
            version: '0.1.0',
            resourceRoot: dirname(__DIR__, 2),
            databases: ['pgsql'],
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void {}

    #[Override]
    public function configRoot(): string
    {
        return 'billium';
    }

    #[Override]
    public function defaultConfigPath(): string
    {
        return dirname(__DIR__, 2).'/config/billium.php';
    }

    /** @return class-string<BilliumOptions> */
    #[Override]
    public function optionsClass(): string
    {
        return BilliumOptions::class;
    }

    #[Override]
    public function tablePrefix(): string
    {
        return 'billium_';
    }

    /** @return list<string> */
    #[Override]
    public function tableNouns(): array
    {
        return [
            'idempotency_receipt',
            'outbox_event',
            'outbox_watermark',
            'money_commit_log',
            'installation_state',
            'financial_session',
            'financial_session_event',
            'module_state',
            'module_transitions',
            'event_contract',
            'outbox_delivery',
            'outbox_command',
            'outbox_command_delivery',
            'feed_subscription',
            'feed_bootstrap_lease',
            'feed_partition',
            'feed_bootstrap_session',
            'feed_bootstrap_item',
            'inbox_message',
            'inbox_conflict',
            'feed_observation_root_scope',
            'feed_observation_scope_link',
            'feed_observation_command',
        ];
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations/platform';
    }

    /** @return list<class-string> */
    #[Override]
    public function schemaSetProviders(): array
    {
        return [BilliumSchemaSetProvider::class];
    }

    /** @return array<class-string, string> */
    #[Override]
    public function definedCapabilities(): array
    {
        return [
            OperationRunner::class => Cardinality::OptionalSingle->value,
            CallerContext::class => Cardinality::OptionalSingle->value,
            FinancialSessionAdmission::class => Cardinality::OptionalSingle->value,
            FinancialAuthorityEvidence::class => Cardinality::OptionalSingle->value,
            EventPublisher::class => Cardinality::OptionalSingle->value,
            ModuleRegistry::class => Cardinality::OptionalSingle->value,
            ModuleAdmissionGate::class => Cardinality::OptionalSingle->value,
            ModuleStateReader::class => Cardinality::OptionalSingle->value,
            ModuleTransitionPlanner::class => Cardinality::OptionalSingle->value,
            ModuleTransitionEvidence::class => Cardinality::OptionalSingle->value,
            Installation::class => Cardinality::OptionalSingle->value,
            IdempotencyStore::class => Cardinality::OptionalSingle->value,
            Outbox::class => Cardinality::OptionalSingle->value,
            CommandOutbox::class => Cardinality::OptionalSingle->value,
            EventFeed::class => Cardinality::OptionalSingle->value,
            FeedPartitions::class => Cardinality::OptionalSingle->value,
            FeedRecoveryQueries::class => Cardinality::OptionalSingle->value,
            RootAdmission::class => Cardinality::OptionalSingle->value,
            EventContractRegistrar::class => Cardinality::OptionalSingle->value,
        ];
    }

    /** @return array<class-string, class-string> */
    #[Override]
    public function providedCapabilities(): array
    {
        return [
            OperationRunner::class => DatabaseOperationRunner::class,
            CallerContext::class => UnboundCallerContext::class,
            FinancialSessionAdmission::class => UnboundSessionAdmission::class,
            ModuleRegistry::class => ModuleCatalog::class,
            ModuleAdmissionGate::class => ModuleLifecycle::class,
            ModuleTransitionPlanner::class => ModuleLifecycle::class,
            ...PlatformCapabilities::map(),
        ];
    }

    /** @return array<class-string, array{kind: string, cardinality: string}> */
    #[Override]
    public function definedSlots(): array
    {
        return [
            SnapshotSource::class => [
                'kind' => SlotKind::Contributor->value,
                'cardinality' => Cardinality::Many->value,
            ],
            CustodyHandler::class => [
                'kind' => SlotKind::Handler->value,
                'cardinality' => Cardinality::Many->value,
            ],
            BootstrapItemHandler::class => [
                'kind' => SlotKind::Handler->value,
                'cardinality' => Cardinality::Many->value,
            ],
        ];
    }

    /** @return list<class-string> */
    #[Override]
    public function healthChecks(): array
    {
        return [BilliumCompatibilityDoctorCheck::class];
    }

    /** @return list<class-string> */
    #[Override]
    public function problemProviders(): array
    {
        return [BilliumCoreProblemDefinitions::class];
    }
}
