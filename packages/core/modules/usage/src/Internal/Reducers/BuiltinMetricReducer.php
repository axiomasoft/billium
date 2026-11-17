<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Reducers;

use Billium\Usage\Contracts\MetricReducer;
use Billium\Usage\Data\AggregationPolicy;
use Billium\Usage\Data\MetricReduction;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Enums\AggregationSemantics;
use Packman\Attributes\Internal;

#[Internal]
final readonly class BuiltinMetricReducer implements MetricReducer
{
    public function __construct(private string $algorithm, private AggregationSemantics $model) {}

    public function algorithmId(): string
    {
        return $this->algorithm;
    }

    public function version(): int
    {
        return 1;
    }

    public function semantics(): AggregationSemantics
    {
        return $this->model;
    }

    public function reduce(AggregationPolicy $policy, WindowSelector $window, array $effective): MetricReduction
    {
        if ($this->model === AggregationSemantics::DistinctExact) {
            $distinct = (new DistinctReducer)->reduce($effective);

            return new MetricReduction(unscaled: $distinct['count'], memberSetDigest: $distinct['digest']);
        }
        $unscaled = $this->model === AggregationSemantics::Additive
            ? (new AdditiveReducer)->reduce(effective: $effective, allowNegative: $policy->allowNegative)
            : (new GaugeReducer)->reduce(policy: $policy, start: $window->startMicros, end: $window->endMicros, allEffective: $effective);

        return new MetricReduction($unscaled);
    }
}
