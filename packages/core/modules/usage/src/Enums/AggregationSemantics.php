<?php

declare(strict_types=1);

namespace Billium\Usage\Enums;

use Packman\Attributes\Api;

#[Api]
enum AggregationSemantics: string
{
    case Additive = 'additive';
    case Gauge = 'gauge';
    case DistinctExact = 'distinct_exact';
}
