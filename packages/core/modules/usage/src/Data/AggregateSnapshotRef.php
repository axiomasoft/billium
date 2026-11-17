<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

use Packman\Attributes\Api;

#[Api]
final readonly class AggregateSnapshotRef
{
    public function __construct(
        public string $snapshotRef, public string $snapshotDigest,
        public ?string $quantity = null, public ?int $quantityScale = null,
        public ?WindowSelector $window = null, public ?string $frontierDigest = null,
    ) {}
}
