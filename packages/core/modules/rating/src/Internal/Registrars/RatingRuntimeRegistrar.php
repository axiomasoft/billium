<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Registrars;

use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\PricingStrategy;
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
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Internal\Proration\ProrationPolicyCatalog;
use Billium\Rating\Internal\RatingConformance;
use Billium\Rating\Internal\Services\DatabaseRatingBooking;
use Billium\Rating\Internal\Services\DatabaseRatingCorrection;
use Billium\Rating\Internal\Services\DatabaseRatingPreparer;
use Billium\Rating\Internal\Services\DatabaseRatingQueries;
use Billium\Rating\Internal\Storage\DatabasePriceVersionStore;
use Billium\Rating\Internal\Storage\TaxPolicyFrontier;
use Billium\Rating\Internal\Strategies\StrategyCatalog;
use Billium\Rating\Internal\UnboundTaxSnapshotPort;
use Billium\Schedule\Contracts\ScheduleQueries;
use Illuminate\Contracts\Foundation\Application;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Internal]
final class RatingRuntimeRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->bindIf(PricingStrategyRegistry::class, static fn (Application $app): StrategyCatalog => new StrategyCatalog(
            strategies: self::strategies($app),
        ), shared: true);
        $app->bind(ProrationPolicyRegistry::class, ProrationPolicyCatalog::class);
        $app->bind(PriceVersionStore::class, DatabasePriceVersionStore::class);
        $app->bind(TaxSnapshotStore::class, DatabasePriceVersionStore::class);
        $app->bind(TaxCorrectionPolicyFrontier::class, TaxPolicyFrontier::class);
        $app->bindIf(TaxSnapshotPort::class, UnboundTaxSnapshotPort::class);
        $app->bind(RatingQuoter::class, RatingComputation::class);
        $app->bind(RatingQueries::class, DatabaseRatingQueries::class);
        $app->bind('billium.conformance.rating', RatingConformance::class);
        $this->bindScheduledCapabilities($app);
    }

    #[Override]
    public function boot(Application $app): void
    {
        $this->bindScheduledCapabilities($app);
    }

    private function bindScheduledCapabilities(Application $app): void
    {
        if (! $app->bound(ScheduleQueries::class)) {
            return;
        }
        $app->bindIf(RatingPreparer::class, DatabaseRatingPreparer::class);
        $app->bindIf(RatingBooking::class, DatabaseRatingBooking::class);
        $app->bindIf(RatingCorrection::class, DatabaseRatingCorrection::class);
    }

    /** @return iterable<PricingStrategy> */
    private static function strategies(Application $app): iterable
    {
        yield from StrategyCatalog::builtins();
        foreach ($app->tagged('billium.pricing_strategies') as $strategy) {
            if (! $strategy instanceof PricingStrategy) {
                throw new LogicException('Pricing strategy tag requires a trusted PricingStrategy implementation');
            }
            yield $strategy;
        }
    }
}
