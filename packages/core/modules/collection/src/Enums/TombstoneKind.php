<?php

declare(strict_types=1);

namespace Billium\Collection\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum TombstoneKind: string
{
    case ConsentRevoked = 'consent_revoked';
    case HostCancelled = 'host_cancelled';
    case AuthorizationTombstone = 'authorization_tombstone';
}
