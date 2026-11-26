<?php

declare(strict_types=1);

namespace Billium\Documents\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum FinalizationState: string
{
    case Draft = 'draft';
    case Finalizing = 'finalizing';
    case SnapshotReady = 'snapshot_ready';
    case Superseded = 'superseded';
}
