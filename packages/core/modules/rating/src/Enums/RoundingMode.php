<?php

declare(strict_types=1);

namespace Billium\Rating\Enums;

use Billium\Contracts\Attributes\Api;

#[Api]
enum RoundingMode: string
{
    case Down = 'down';
    case Up = 'up';
    case HalfUp = 'half_up';
    case HalfEven = 'half_even';
}
