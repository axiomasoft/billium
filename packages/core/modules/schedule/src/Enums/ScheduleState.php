<?php

declare(strict_types=1);

namespace Billium\Schedule\Enums;

use Packman\Attributes\Api;

#[Api]
enum ScheduleState: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
}
