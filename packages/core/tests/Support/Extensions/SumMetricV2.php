<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Support\Extensions;

use Billium\Usage\Contracts\MetricReducer;
use Billium\Usage\Data\AggregationPolicy;
use Billium\Usage\Data\MetricReduction;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Enums\AggregationSemantics;

/** Host metric extension double: versioned normalized additive inputs, no persistence. */
final class SumMetricV2 implements MetricReducer
{
    public function algorithmId(): string
    {
        return 'additive_sum';
    }

    public function version(): int
    {
        return 2;
    }

    public function semantics(): AggregationSemantics
    {
        return AggregationSemantics::Additive;
    }

    public function reduce(AggregationPolicy $policy, WindowSelector $window, array $effective): MetricReduction
    {
        $sum = '0';
        foreach ($effective as $fact) {
            $sum = bcadd($sum, $fact->quantityUnscaled, 0);
        }

        return new MetricReduction($sum);
    }
}
