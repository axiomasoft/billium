<?php

declare(strict_types=1);

namespace Billium\Contracts\Enums;

use Packman\Attributes\Api;

/**
 * Rounding modes of money.round by name (BC-MW-18).
 */
#[Api(since: '1.0')]
enum RoundingMode: string
{
    /** Half goes to the even neighbour. */
    case HalfEven = 'HALF_EVEN';

    /** Half goes away from zero. */
    case HalfUp = 'HALF_UP';
}
