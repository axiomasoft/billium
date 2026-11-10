<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Reducers;

use Billium\Usage\Contracts\MetricReducer;
use Billium\Usage\Contracts\MetricReducerRegistry;
use Billium\Usage\Data\AggregationPolicy;
use Billium\Usage\Enums\AggregationSemantics;
use Billium\Usage\Internal\Errors\UsageProblems;

final class ReducerRegistry implements MetricReducerRegistry
{
    /** @var array<string, MetricReducer> */
    private array $reducers = [];

    /** @param iterable<MetricReducer>|null $reducers */
    public function __construct(?iterable $reducers = null)
    {
        foreach ($reducers ?? self::builtins() as $reducer) {
            $key = $reducer->algorithmId().'@'.$reducer->version();
            if ($reducer->version() < 1 || isset($this->reducers[$key])) {
                throw UsageProblems::error('metric_role_duplicate_or_invalid');
            }
            $this->reducers[$key] = $reducer;
        }
    }

    /** @return list<MetricReducer> */
    public static function builtins(): array
    {
        return [
            new BuiltinMetricReducer(algorithm: 'additive_sum', model: AggregationSemantics::Additive),
            new BuiltinMetricReducer(algorithm: 'gauge_latest_as_of', model: AggregationSemantics::Gauge),
            new BuiltinMetricReducer(algorithm: 'gauge_max', model: AggregationSemantics::Gauge),
            new BuiltinMetricReducer(algorithm: 'gauge_time_weighted', model: AggregationSemantics::Gauge),
            new BuiltinMetricReducer(algorithm: 'distinct_exact_union', model: AggregationSemantics::DistinctExact),
        ];
    }

    public function resolve(string $algorithmId, int $version): MetricReducer
    {
        return $this->reducers[$algorithmId.'@'.$version] ?? throw UsageProblems::error('metric_semantics_unsupported');
    }

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
        $role = $this->resolve(algorithmId: $policy->algorithmId, version: $policy->algorithmVersion);
        $expected = match ($policy->semantics) {
            AggregationSemantics::Additive => 'additive_sum',
            AggregationSemantics::Gauge => 'gauge_'.$policy->gaugePolicy?->value,
            AggregationSemantics::DistinctExact => 'distinct_exact_union',
        };
        if ($policy->algorithmId !== $expected || $role->semantics() !== $policy->semantics) {
            throw UsageProblems::error('metric_semantics_unsupported');
        }
    }
}
