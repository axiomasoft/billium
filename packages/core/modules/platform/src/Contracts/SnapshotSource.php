<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Platform\Data\Feed\FeedSelection;

/** D60/D63: register with billium.snapshot_sources. Read on the supplied REPEATABLE READ connection; no writes/network. */
#[Api]
#[Spi(SpiKind::Implement)]
interface SnapshotSource
{
    public function key(): string;

    /** @return list<array<string,mixed>> Complete selected immutable economic revisions, with stable identities/digests. */
    public function snapshot(FeedSelection $selection, string $connectionName): array;
}
