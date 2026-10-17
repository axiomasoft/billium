<?php

declare(strict_types=1);

namespace Billium\Contracts\Enums;

use Billium\Contracts\Attributes\Api;

/**
 * Rounding modes of money.round by name (BC-MW-18).
 */
#[Api]
enum RoundingMode: string
{
    /** Half goes to the even neighbour. */
    case HalfEven = 'HALF_EVEN';

    /** Half goes away from zero. */
    case HalfUp = 'HALF_UP';
}
