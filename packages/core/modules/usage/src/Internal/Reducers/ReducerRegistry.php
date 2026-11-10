<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Reducers;

use Billium\Usage\Data\AggregationPolicy;
use Billium\Usage\Enums\AggregationSemantics;
use Billium\Usage\Internal\Errors\UsageProblems;

final class ReducerRegistry
{
    public function get(string $algorithm): AdditiveReducer|GaugeReducer|DistinctReducer
    {
        return match ($algorithm) {
            'additive_sum@1' => new AdditiveReducer,
            'gauge_latest_as_of@1', 'gauge_max@1', 'gauge_time_weighted@1' => new GaugeReducer,
            'distinct_exact_union@1' => new DistinctReducer,
            default => throw UsageProblems::error('metric_semantics_unsupported'),
        };
    }

    public function validate(AggregationPolicy $policy): void
    {
        $this->get($policy->algorithmId.'@'.$policy->algorithmVersion);
        $expected = match ($policy->semantics) {
            AggregationSemantics::Additive => 'additive_sum',
            AggregationSemantics::Gauge => 'gauge_'.$policy->gaugePolicy?->value,
            AggregationSemantics::DistinctExact => 'distinct_exact_union',
        };
        if ($policy->algorithmId !== $expected) {
            throw UsageProblems::error('metric_semantics_unsupported');
        }
    }
}
