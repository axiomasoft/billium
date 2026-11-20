<?php

declare(strict_types=1);

namespace Billium\Collection\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ConsentPurpose: string
{
    case RecurringCharge = 'recurring_charge';
    case UsageSettlement = 'usage_settlement';
    case DunningRetry = 'dunning_retry';
    case OneOffSavedMethod = 'one_off_saved_method';
}
