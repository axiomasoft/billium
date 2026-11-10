<?php

declare(strict_types=1);

namespace Billium\Rating\Enums;

use Billium\Contracts\Attributes\Api;

#[Api]
enum ChargeKind: string
{
    case Charge = 'charge';
    case NoCharge = 'no_charge';
}
