<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Inbox;

use Packman\Attributes\Api;

/** Query view of a staged bootstrap; reading it never mutates and never triggers an effect. */
#[Api(since: '1.0')]
final readonly class BootstrapStatus
{
    public function __construct(
        public int $sessionId,
        public string $status,
        public int $cursorSequence,
        public int $items,
        public int $staged,
        public int $applied,
        public int $parked,
        public int $discrepancy,
        public ?int $oldPartitionId,
        public ?int $newPartitionId,
    ) {}
}
