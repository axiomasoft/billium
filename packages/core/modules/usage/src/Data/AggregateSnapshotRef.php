<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

final readonly class AggregateSnapshotRef
{
    public function __construct(public string $snapshotRef, public string $snapshotDigest) {}
}
