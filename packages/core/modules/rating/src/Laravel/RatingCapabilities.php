<?php

declare(strict_types=1);

namespace Billium\Rating\Laravel;

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
use Billium\Rating\Internal\Adapters\RatedCorrectionBasis;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Internal\Proration\ProrationPolicyCatalog;
use Billium\Rating\Internal\Services\DatabaseRatingBooking;
use Billium\Rating\Internal\Services\DatabaseRatingCorrection;
use Billium\Rating\Internal\Services\DatabaseRatingPreparer;
use Billium\Rating\Internal\Services\DatabaseRatingQueries;
use Billium\Rating\Internal\Storage\DatabasePriceVersionStore;
use Billium\Rating\Internal\Storage\TaxPolicyFrontier;
use Billium\Rating\Internal\Strategies\StrategyCatalog;
use Billium\Rating\Internal\UnboundTaxSnapshotPort;
use Billium\Schedule\Contracts\CorrectionBasisPolicy;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Capability declarations provided by the Billium Rating module for standalone and scheduled plugin wiring.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class RatingCapabilities
{
    /** @return array<class-string, class-string> */
    public static function map(): array
    {
        return [
            PricingStrategyRegistry::class => StrategyCatalog::class,
            ProrationPolicyRegistry::class => ProrationPolicyCatalog::class,
            PriceVersionStore::class => DatabasePriceVersionStore::class,
            TaxSnapshotStore::class => DatabasePriceVersionStore::class,
            TaxCorrectionPolicyFrontier::class => TaxPolicyFrontier::class,
            TaxSnapshotPort::class => UnboundTaxSnapshotPort::class,
            RatingQuoter::class => RatingComputation::class,
            RatingQueries::class => DatabaseRatingQueries::class,
        ];
    }

    /** @return array<class-string, class-string> */
    public static function scheduled(): array
    {
        return [
            RatingPreparer::class => DatabaseRatingPreparer::class,
            RatingBooking::class => DatabaseRatingBooking::class,
            RatingCorrection::class => DatabaseRatingCorrection::class,
            CorrectionBasisPolicy::class => RatedCorrectionBasis::class,
        ];
    }
}
