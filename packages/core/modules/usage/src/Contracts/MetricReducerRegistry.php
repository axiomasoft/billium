<?php

declare(strict_types=1);

namespace Billium\Usage\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Usage\Data\AggregationPolicy;

#[Api]
#[Spi(SpiKind::Call)]
interface MetricReducerRegistry
{
    public function resolve(string $algorithmId, int $version): MetricReducer;

    public function validate(AggregationPolicy $policy): void;
}
