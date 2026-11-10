<?php

declare(strict_types=1);

namespace Billium\Usage\Enums;

enum CompletenessStatus: string
{
    case Complete = 'complete';
    case Delayed = 'delayed';
    case Gap = 'gap';
    case Dropped = 'dropped';
    case RetentionExpired = 'retention_expired';
}
