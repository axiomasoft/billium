<?php

declare(strict_types=1);

namespace Billium\Schedule\Enums;

use Billium\Contracts\Attributes\Api;

#[Api]
enum OccurrencePurpose: string
{
    case RecurringCharge = 'recurring_charge';
    case UsageSettlement = 'usage_settlement';
    case Proration = 'proration';
}
