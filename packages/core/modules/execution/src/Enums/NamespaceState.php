<?php

declare(strict_types=1);

namespace Billium\Execution\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum NamespaceState: string
{
    case Open = 'open';
    case Closed = 'closed';
    case RecoveryRequired = 'recovery_required';
}
