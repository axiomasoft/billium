<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

use Billium\Contracts\Attributes\Api;

#[Api]
final readonly class AggregateSnapshotRef
{
    public function __construct(public string $snapshotRef, public string $snapshotDigest) {}
}
