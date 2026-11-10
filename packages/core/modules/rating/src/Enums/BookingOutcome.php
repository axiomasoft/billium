<?php

declare(strict_types=1);

namespace Billium\Rating\Enums;

use Billium\Contracts\Attributes\Api;

#[Api]
enum BookingOutcome: string
{
    case Booked = 'booked';
    case Unposted = 'unposted';
    case NoCharge = 'no_charge';
    case Existing = 'existing';
}
