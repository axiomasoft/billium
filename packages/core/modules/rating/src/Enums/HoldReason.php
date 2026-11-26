<?php

declare(strict_types=1);

namespace Billium\Rating\Enums;

use Packman\Attributes\Api;

#[Api]
enum HoldReason: string
{
    case IncompleteSource = 'incomplete_source';
    case UnknownPolicy = 'unknown_policy';
    case MissingTax = 'missing_tax';
    case MissingPrice = 'missing_price';
    case AggregateNotReproducible = 'aggregate_not_reproducible';
    case FrontierStale = 'frontier_stale';
}
