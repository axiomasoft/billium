<?php

declare(strict_types=1);

namespace Billium\Core\Enums\Modules;

use Billium\Contracts\Attributes\Api;

/** How a public event stream joins a bootstrap (D63): a SnapshotSource, or an explicit replay-only/no-bootstrap policy. */
#[Api]
enum BootstrapMode: string
{
    case Snapshot = 'snapshot';
    case ReplayOnly = 'replay_only';
    case NonBootstrappable = 'non_bootstrappable';
}
