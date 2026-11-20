<?php

declare(strict_types=1);

namespace Billium\Core\Plugins;

use Billium\Collection\Contracts\CollectionConsentGuard;
use Billium\Collection\Contracts\ConsentBindingService;
use Billium\Collection\Contracts\ConsentQueries;
use Billium\Collection\Contracts\DunningCaseService;
use Billium\Collection\Contracts\DunningPolicyService;
use Billium\Collection\Contracts\DunningQueries;
use Billium\Collection\Contracts\SavedMethodCapabilityValidator;
use Billium\Collection\Laravel\CollectionCapabilities;
use Override;
use Packman\Attributes\Api;
use Packman\Extensions\Concerns\Composition\ProvidesCapabilities;
use Packman\Extensions\Concerns\Persistence\OwnsSchema;
use Packman\Extensions\Contracts\Composition\WithCapabilities;
use Packman\Extensions\Contracts\Persistence\WithSchema;
use Packman\Plugins\Contracts\Registry\ExtensionPlugin;
use Packman\Plugins\Data\Plugin\PluginManifest;
use Packman\Plugins\Enums\Contributions\Cardinality;
use Packman\Plugins\Laravel\PluginRegistrar;

/**
 * Stateless Packman extension plugin composing Billium Collection schema ownership and capability contracts.
 */
#[Api(since: '1.0')]
final class CollectionPlugin implements ExtensionPlugin, WithCapabilities, WithSchema
{
    use OwnsSchema;
    use ProvidesCapabilities;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.billium.collection',
            version: '0.1.0',
            requires: [
                'axiomasoft.billium.platform',
                'axiomasoft.billium.ledger',
                'axiomasoft.billium.execution',
            ],
            optional: [],
            resourceRoot: dirname(__DIR__, 2),
            databases: ['pgsql'],
        );
    }

    #[Override]
    public function register(PluginRegistrar $registrar): void {}

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
            'collection_consent_binding',
            'collection_consent_guard',
            'collection_guard_tombstone',
            'collection_customer_action',
            'collection_refund_route',
            'collection_dunning_policy',
            'collection_recurring_owner',
            'collection_dunning_case',
            'collection_case_policy_migration',
            'collection_step',
        ];
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations/collection';
    }

    /** @return array<class-string, string> */
    #[Override]
    public function definedCapabilities(): array
    {
        return [
            CollectionConsentGuard::class => Cardinality::OptionalSingle->value,
            SavedMethodCapabilityValidator::class => Cardinality::OptionalSingle->value,
            ConsentBindingService::class => Cardinality::OptionalSingle->value,
            ConsentQueries::class => Cardinality::OptionalSingle->value,
            DunningPolicyService::class => Cardinality::OptionalSingle->value,
            DunningCaseService::class => Cardinality::OptionalSingle->value,
            DunningQueries::class => Cardinality::OptionalSingle->value,
        ];
    }

    /** @return array<class-string, class-string> */
    #[Override]
    public function providedCapabilities(): array
    {
        return CollectionCapabilities::map();
    }
}
