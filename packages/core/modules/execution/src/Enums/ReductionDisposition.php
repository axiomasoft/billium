<?php

declare(strict_types=1);

namespace Billium\Execution\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ReductionDisposition: string
{
    case Applied = 'applied';
    case Duplicate = 'duplicate';
    case Stale = 'stale';
    case HeldDiscrepancy = 'held_discrepancy';
    case Unmatched = 'unmatched';
    case Quarantined = 'quarantined';
}
