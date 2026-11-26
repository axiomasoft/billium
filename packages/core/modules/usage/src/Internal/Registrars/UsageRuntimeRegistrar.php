<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Registrars;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Usage\Contracts\AggregationLocks;
use Billium\Usage\Contracts\AggregationParticipants;
use Billium\Usage\Contracts\AggregationQueries;
use Billium\Usage\Contracts\AggregationService;
use Billium\Usage\Contracts\MetricReducer;
use Billium\Usage\Contracts\MetricReducerRegistry;
use Billium\Usage\Contracts\SourceCompletenessIntake;
use Billium\Usage\Contracts\Usage;
use Billium\Usage\Contracts\UsageAttribution;
use Billium\Usage\Internal\DatabaseUsage;
use Billium\Usage\Internal\Participants\DatabaseAggregationLocks;
use Billium\Usage\Internal\Participants\DatabaseAggregationParticipants;
use Billium\Usage\Internal\Reducers\ReducerRegistry;
use Billium\Usage\Internal\Services\DatabaseAggregationQueries;
use Billium\Usage\Internal\Services\DatabaseAggregationService;
use Billium\Usage\Internal\Services\DatabaseSourceCompletenessIntake;
use Billium\Usage\Internal\Storage\DatabaseEffectiveFactSource;
use Billium\Usage\Internal\Storage\EffectiveFactSource;
use Billium\Usage\Internal\UnresolvedAttribution;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Internal]
final class UsageRuntimeRegistrar implements PackageRegistrar
{
    #[Override]
    public function register(Application $app): void
    {
        $app->bindIf(MetricReducerRegistry::class, static fn (Application $app): ReducerRegistry => new ReducerRegistry(
            reducers: self::reducers($app),
        ), shared: true);
        $app->bind(EffectiveFactSource::class, DatabaseEffectiveFactSource::class);
        $app->bind(AggregationService::class, DatabaseAggregationService::class);
        $app->bind(AggregationQueries::class, DatabaseAggregationQueries::class);
        $app->bind(AggregationParticipants::class, DatabaseAggregationParticipants::class);
        $app->bind(AggregationLocks::class, DatabaseAggregationLocks::class);
        $app->bind(SourceCompletenessIntake::class, DatabaseSourceCompletenessIntake::class);
        $app->bindIf(UsageAttribution::class, UnresolvedAttribution::class);
        $app->bind(Usage::class, static fn (Application $app): DatabaseUsage => new DatabaseUsage(
            databases: $app->make(DatabaseManager::class),
            runner: $app->make(OperationRunner::class),
            callers: $app->make(CallerContext::class),
            attribution: $app->make(UsageAttribution::class),
            codec: new Codec,
            options: $app->make(BilliumOptions::class),
        ));
    }

    #[Override]
    public function boot(Application $app): void {}

    /** @return iterable<MetricReducer> */
    private static function reducers(Application $app): iterable
    {
        yield from ReducerRegistry::builtins();
        foreach ($app->tagged('billium.metric_reducers') as $reducer) {
            if (! $reducer instanceof MetricReducer) {
                throw new LogicException('Metric reducer tag requires a trusted MetricReducer implementation');
            }
            yield $reducer;
        }
    }
}
