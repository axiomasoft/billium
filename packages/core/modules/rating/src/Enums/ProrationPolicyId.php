<?php

declare(strict_types=1);

namespace Billium\Rating\Enums;

use Billium\Contracts\Attributes\Api;

#[Api]
enum ProrationPolicyId: string
{
    case None = 'none';
    case ElapsedSeconds = 'elapsed_seconds';
    case LocalCalendarDays = 'local_calendar_days';
}
