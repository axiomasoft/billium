<?php

declare(strict_types=1);

namespace Billium\Usage\Contracts;

use Billium\Usage\Data\AggregationPolicy;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(SpiKind::Call)]
interface MetricReducerRegistry
{
    public function resolve(string $algorithmId, int $version): MetricReducer;

    public function validate(AggregationPolicy $policy): void;
}
