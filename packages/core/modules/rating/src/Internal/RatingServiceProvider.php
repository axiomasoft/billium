<?php

declare(strict_types=1);

namespace Billium\Rating\Internal;

use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Contracts\PricingStrategyRegistry;
use Billium\Rating\Contracts\TaxSnapshotPort;
use Billium\Rating\Contracts\TaxSnapshotStore;
use Billium\Rating\Internal\Storage\DatabasePriceVersionStore;
use Billium\Rating\Internal\Strategies\StrategyCatalog;
use Illuminate\Support\ServiceProvider;

final class RatingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PricingStrategyRegistry::class, StrategyCatalog::class);
        $this->app->bind(PriceVersionStore::class, DatabasePriceVersionStore::class);
        $this->app->bind(TaxSnapshotStore::class, DatabasePriceVersionStore::class);
        $this->app->bindIf(TaxSnapshotPort::class, UnboundTaxSnapshotPort::class);
    }

    public function boot(): void
    {
        // D55: migration discovery only; install and upgrade are explicit host operations.
        $this->loadMigrationsFrom(__DIR__.'/../../../../database/migrations/rating');
    }
}
