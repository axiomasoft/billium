<?php

declare(strict_types=1);

namespace Billium\Collection\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum CollectionStepStatus: string
{
    case Planned = 'planned';
    case Authorized = 'authorized';
    case Dispatched = 'dispatched';
    case Unknown = 'unknown';
    case Succeeded = 'succeeded';
    case FailedTerminal = 'failed_terminal';
    case Cancelled = 'cancelled';
}
