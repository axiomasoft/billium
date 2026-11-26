<?php

declare(strict_types=1);

namespace Billium\Collection\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum RecurringOwnerMode: string
{
    case BilliumScheduler = 'billium_scheduler';
    case ProviderAutonomousSubscription = 'provider_autonomous_subscription';
}
