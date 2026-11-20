<?php

declare(strict_types=1);

namespace Billium\Collection\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum DunningCaseStatus: string
{
    case Open = 'open';
    case Active = 'active';
    case ParkedUnknown = 'parked_unknown';
    case Paused = 'paused';
    case Exhausted = 'exhausted';
    case Settled = 'settled';
    case Cancelled = 'cancelled';
}
