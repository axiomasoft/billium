<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Feed;

use Billium\Platform\Data\Feed\FeedSelection;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Register with billium.snapshot_sources. Read on the supplied REPEATABLE READ connection; no writes/network. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface SnapshotSource
{
    public function key(): string;

    /** @return list<array<string,mixed>> Complete selected immutable economic revisions, with stable identities/digests. */
    public function snapshot(FeedSelection $selection, string $connectionName): array;
}
