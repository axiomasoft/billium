<?php

declare(strict_types=1);

namespace Billium\Schedule\Enums;

use Billium\Contracts\Attributes\Api;

#[Api]
enum OccurrenceState: string
{
    case Planned = 'planned';
    case Rated = 'rated';
    case Obligated = 'obligated';
    case Superseded = 'superseded';
    case Cancelled = 'cancelled';
}
