<?php

declare(strict_types=1);

namespace Billium\Usage\Internal;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Contracts\CallerContext;
use Billium\Core\Contracts\OperationRunner;
use Billium\Usage\Contracts\AggregationLocks;
use Billium\Usage\Contracts\AggregationParticipants;
use Billium\Usage\Contracts\AggregationQueries;
use Billium\Usage\Contracts\AggregationService;
use Billium\Usage\Contracts\SourceCompletenessIntake;
use Billium\Usage\Contracts\Usage;
use Billium\Usage\Contracts\UsageAttribution;
use Billium\Usage\Internal\Participants\DatabaseAggregationLocks;
use Billium\Usage\Internal\Participants\DatabaseAggregationParticipants;
use Billium\Usage\Internal\Services\DatabaseAggregationQueries;
use Billium\Usage\Internal\Services\DatabaseAggregationService;
use Billium\Usage\Internal\Services\DatabaseSourceCompletenessIntake;
use Billium\Usage\Internal\Storage\DatabaseEffectiveFactSource;
use Billium\Usage\Internal\Storage\EffectiveFactSource;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

final class UsageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EffectiveFactSource::class, DatabaseEffectiveFactSource::class);
        $this->app->bind(AggregationService::class, DatabaseAggregationService::class);
        $this->app->bind(AggregationQueries::class, DatabaseAggregationQueries::class);
        $this->app->bind(AggregationParticipants::class, DatabaseAggregationParticipants::class);
        $this->app->bind(AggregationLocks::class, DatabaseAggregationLocks::class);
        $this->app->bind(SourceCompletenessIntake::class, DatabaseSourceCompletenessIntake::class);
        $this->app->bindIf(UsageAttribution::class, UnresolvedAttribution::class);
        $this->app->bind(Usage::class, static fn (Application $app): DatabaseUsage => new DatabaseUsage(
            databases: $app->make(DatabaseManager::class),
            runner: $app->make(OperationRunner::class),
            callers: $app->make(CallerContext::class),
            attribution: $app->make(UsageAttribution::class),
            codec: new Codec,
        ));
    }

    public function boot(): void
    {
        // D55: discovery only; install/upgrade and module registration are explicit host operations.
        $this->loadMigrationsFrom(__DIR__.'/../../../../database/migrations/usage');
    }
}
