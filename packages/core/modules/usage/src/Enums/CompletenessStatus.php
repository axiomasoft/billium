<?php

declare(strict_types=1);

namespace Billium\Usage\Enums;

use Packman\Attributes\Api;

#[Api]
enum CompletenessStatus: string
{
    case Complete = 'complete';
    case Delayed = 'delayed';
    case Gap = 'gap';
    case Dropped = 'dropped';
    case RetentionExpired = 'retention_expired';
}
