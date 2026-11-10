<?php

declare(strict_types=1);

namespace Billium\Rating\Enums;

use Billium\Contracts\Attributes\Api;

#[Api]
enum StrategyId: string
{
    case Fixed = 'fixed';
    case PerUnit = 'per_unit';
    case TieredGraduated = 'tiered_graduated';
    case TieredVolume = 'tiered_volume';
}
