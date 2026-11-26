<?php

declare(strict_types=1);

namespace Billium\Execution\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum AttemptState: string
{
    case Admitted = 'admitted';
    case Dispatching = 'dispatching';
    case Pending = 'pending';
    case Unknown = 'unknown';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
