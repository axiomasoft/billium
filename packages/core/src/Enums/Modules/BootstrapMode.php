<?php

declare(strict_types=1);

namespace Billium\Core\Enums\Modules;

use Packman\Attributes\Api;

/** How a public event stream joins a bootstrap: a SnapshotSource, or an explicit replay-only/no-bootstrap policy. */
#[Api(since: '1.0')]
enum BootstrapMode: string
{
    case Snapshot = 'snapshot';
    case ReplayOnly = 'replay_only';
    case NonBootstrappable = 'non_bootstrappable';
}
