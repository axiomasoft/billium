<?php

declare(strict_types=1);

namespace Billium\Collection\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum CollectionSignalKind: string
{
    case Grace = 'grace';
    case ReadOnly = 'read_only';
    case RenewalSucceeded = 'renewal_succeeded';
    case RenewalFailed = 'renewal_failed';
    case CustomerActionRequired = 'customer_action_required';
    case ManualIntervention = 'manual_intervention';
}
