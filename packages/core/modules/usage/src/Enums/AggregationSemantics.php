<?php

declare(strict_types=1);

namespace Billium\Usage\Enums;

enum AggregationSemantics: string
{
    case Additive = 'additive';
    case Gauge = 'gauge';
    case DistinctExact = 'distinct_exact';
}
