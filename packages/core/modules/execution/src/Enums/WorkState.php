<?php

declare(strict_types=1);

namespace Billium\Execution\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum WorkState: string
{
    case Due = 'due';
    case Leased = 'leased';
    case Processed = 'processed';
    case Parked = 'parked';
}
