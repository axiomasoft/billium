<?php

declare(strict_types=1);

namespace Billium\Core\Plugins;

use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\PricingStrategyRegistry;
use Billium\Rating\Contracts\ProrationPolicyRegistry;
use Billium\Rating\Contracts\RatingBooking;
use Billium\Rating\Contracts\RatingCorrection;
use Billium\Rating\Contracts\RatingPreparer;
use Billium\Rating\Contracts\RatingQueries;
use Billium\Rating\Contracts\RatingQuoter;
use Billium\Rating\Contracts\TaxCorrectionPolicyFrontier;
use Billium\Rating\Contracts\TaxSnapshotPort;
use Billium\Rating\Contracts\TaxSnapshotStore;
use Billium\Rating\Laravel\RatingCapabilities;
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
 * Stateless Packman extension plugin composing Billium Rating schema ownership and capability contracts.
 */
#[Api(since: '1.0')]
final class RatingPlugin implements ExtensionPlugin, WithCapabilities, WithSchema
{
    use OwnsSchema;
    use ProvidesCapabilities;

    #[Override]
    public function manifest(): PluginManifest
    {
        return new PluginManifest(
            id: 'axiomasoft.billium.rating',
            version: '0.1.0',
            requires: ['axiomasoft.billium.platform'],
            optional: [
                'axiomasoft.billium.ledger',
                'axiomasoft.billium.usage',
            ],
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
            'rating_price_publication',
            'rating_price_version',
            'rating_price_tier',
            'rating_commitment_term',
            'rating_tax_snapshot',
            'rating_run',
            'rating_rated_basis',
            'rating_rated_line',
            'rating_booking',
            'rating_source_frontier',
            'rating_event',
            'rating_correction',
            'rating_correction_booking',
        ];
    }

    #[Override]
    public function migrationPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations/rating';
    }

    /** @return array<class-string, string> */
    #[Override]
    public function definedCapabilities(): array
    {
        return [
            PricingStrategyRegistry::class => Cardinality::OptionalSingle->value,
            ProrationPolicyRegistry::class => Cardinality::OptionalSingle->value,
            PriceVersionStore::class => Cardinality::OptionalSingle->value,
            TaxSnapshotStore::class => Cardinality::OptionalSingle->value,
            TaxCorrectionPolicyFrontier::class => Cardinality::OptionalSingle->value,
            TaxSnapshotPort::class => Cardinality::OptionalSingle->value,
            RatingQuoter::class => Cardinality::OptionalSingle->value,
            RatingPreparer::class => Cardinality::OptionalSingle->value,
            RatingBooking::class => Cardinality::OptionalSingle->value,
            RatingCorrection::class => Cardinality::OptionalSingle->value,
            RatingQueries::class => Cardinality::OptionalSingle->value,
        ];
    }

    /** @return array<class-string, class-string> */
    #[Override]
    public function providedCapabilities(): array
    {
        return RatingCapabilities::map();
    }
}
