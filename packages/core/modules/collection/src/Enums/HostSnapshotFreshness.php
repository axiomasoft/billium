<?php

declare(strict_types=1);

namespace Billium\Collection\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum HostSnapshotFreshness: string
{
    case Authoritative = 'authoritative';
    case Stale = 'stale';
    case Unknown = 'unknown';
}
