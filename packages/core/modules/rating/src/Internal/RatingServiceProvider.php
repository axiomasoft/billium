<?php

declare(strict_types=1);

namespace Billium\Rating\Internal;

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
use Billium\Rating\Internal\Services\DatabaseRatingBooking;
use Billium\Rating\Internal\Services\DatabaseRatingCorrection;
use Billium\Rating\Internal\Services\DatabaseRatingPreparer;
use Billium\Rating\Internal\Services\DatabaseRatingQueries;
use Billium\Rating\Internal\Storage\DatabasePriceVersionStore;
use Billium\Rating\Internal\Storage\TaxPolicyFrontier;
use Billium\Rating\Internal\Strategies\StrategyCatalog;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final class RatingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(PricingStrategyRegistry::class, static fn (Application $app): StrategyCatalog => new StrategyCatalog(
            strategies: self::strategies($app),
        ), shared: true);
        $this->app->bind(ProrationPolicyRegistry::class, ProrationPolicyCatalog::class);
        $this->app->bind(PriceVersionStore::class, DatabasePriceVersionStore::class);
        $this->app->bind(TaxSnapshotStore::class, DatabasePriceVersionStore::class);
        $this->app->bind(TaxCorrectionPolicyFrontier::class, TaxPolicyFrontier::class);
        $this->app->bindIf(TaxSnapshotPort::class, UnboundTaxSnapshotPort::class);
        $this->app->bind(RatingQuoter::class, RatingComputation::class);
        $this->app->bind(RatingPreparer::class, DatabaseRatingPreparer::class);
        $this->app->bind(RatingBooking::class, DatabaseRatingBooking::class);
        $this->app->bind(RatingCorrection::class, DatabaseRatingCorrection::class);
        $this->app->bind(RatingQueries::class, DatabaseRatingQueries::class);
        $this->app->bind('billium.conformance.rating', RatingConformance::class);
    }

    public function boot(): void
    {
        // D55: migration discovery only; install and upgrade are explicit host operations.
        $this->loadMigrationsFrom(__DIR__.'/../../../../database/migrations/rating');
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
