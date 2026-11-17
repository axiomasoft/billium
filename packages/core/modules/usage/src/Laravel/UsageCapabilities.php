<?php

declare(strict_types=1);

namespace Billium\Usage\Laravel;

use Billium\Usage\Contracts\AggregationLocks;
use Billium\Usage\Contracts\AggregationParticipants;
use Billium\Usage\Contracts\AggregationQueries;
use Billium\Usage\Contracts\AggregationService;
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
use Billium\Usage\Internal\UnresolvedAttribution;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Capability declarations provided by the Billium Usage module for Core plugin wiring.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class UsageCapabilities
{
    /** @return array<class-string, class-string> */
    public static function map(): array
    {
        return [
            MetricReducerRegistry::class => ReducerRegistry::class,
            AggregationService::class => DatabaseAggregationService::class,
            AggregationQueries::class => DatabaseAggregationQueries::class,
            AggregationParticipants::class => DatabaseAggregationParticipants::class,
            AggregationLocks::class => DatabaseAggregationLocks::class,
            SourceCompletenessIntake::class => DatabaseSourceCompletenessIntake::class,
            UsageAttribution::class => UnresolvedAttribution::class,
            Usage::class => DatabaseUsage::class,
        ];
    }
}
