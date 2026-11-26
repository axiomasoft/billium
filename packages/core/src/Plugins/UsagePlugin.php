<?php

declare(strict_types=1);

namespace Billium\Core\Plugins;

use Billium\Usage\Contracts\AggregationLocks;
use Billium\Usage\Contracts\AggregationParticipants;
use Billium\Usage\Contracts\AggregationQueries;
use Billium\Usage\Contracts\AggregationService;
use Billium\Usage\Contracts\MetricReducerRegistry;
use Billium\Usage\Contracts\SourceCompletenessIntake;
use Billium\Usage\Contracts\Usage;
use Billium\Usage\Contracts\UsageAttribution;
use Billium\Usage\Laravel\UsageCapabilities;
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
 * Stateless Packman extension plugin composing Billium Usage schema ownership and capability contracts.
 */
#[Api(since: '1.0')]
final class UsagePlugin implements ExtensionPlugin, WithCapabilities, WithSchema
{
    use OwnsSchema;
    use ProvidesCapabilities;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.billium.usage',
            version: '0.1.0',
            requires: ['axiomasoft.billium.platform'],
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
            'usage_stream',
            'usage_metric',
            'usage_fact',
            'usage_source_finality_contract',
            'usage_aggregation_policy',
            'usage_source_head',
            'usage_source_completeness',
            'usage_aggregate_series_head',
            'usage_aggregate_snapshot',
            'usage_aggregate_member',
            'usage_aggregate_distinct_member',
            'usage_aggregate_reproducibility_event',
            'usage_aggregate_event',
        ];
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations/usage';
    }

    /** @return array<class-string, string> */
    #[Override]
    public function definedCapabilities(): array
    {
        return [
            MetricReducerRegistry::class => Cardinality::OptionalSingle->value,
            AggregationService::class => Cardinality::OptionalSingle->value,
            AggregationQueries::class => Cardinality::OptionalSingle->value,
            AggregationParticipants::class => Cardinality::OptionalSingle->value,
            AggregationLocks::class => Cardinality::OptionalSingle->value,
            SourceCompletenessIntake::class => Cardinality::OptionalSingle->value,
            UsageAttribution::class => Cardinality::OptionalSingle->value,
            Usage::class => Cardinality::OptionalSingle->value,
        ];
    }

    /** @return array<class-string, class-string> */
    #[Override]
    public function providedCapabilities(): array
    {
        return UsageCapabilities::map();
    }
}
