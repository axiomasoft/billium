<?php

declare(strict_types=1);

namespace Billium\Execution\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ReservationState: string
{
    case Held = 'held';
    case Consumed = 'consumed';
    case Released = 'released';
}
