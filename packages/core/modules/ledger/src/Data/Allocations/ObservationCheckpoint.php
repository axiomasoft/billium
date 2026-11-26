<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Allocations;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ObservationCheckpoint
{
    public function __construct(
        public bool $projectionApplied,
        public ?AllocationReceipt $allocationReceipt,
        public ?string $pendingReason,
        public bool $recoveryRequired,
    ) {}
}
