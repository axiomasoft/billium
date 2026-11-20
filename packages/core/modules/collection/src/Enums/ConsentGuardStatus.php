<?php

declare(strict_types=1);

namespace Billium\Collection\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ConsentGuardStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Cancelled = 'cancelled';
}
