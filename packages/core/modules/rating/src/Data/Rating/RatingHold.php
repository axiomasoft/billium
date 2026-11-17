<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Rating;

use Billium\Rating\Enums\HoldReason;
use Packman\Attributes\Api;

#[Api]
final readonly class RatingHold
{
    /** @param list<string> $repairOrReplayRefs */
    public function __construct(
        public HoldReason $reason,
        public array $repairOrReplayRefs,
        public string $detail,
    ) {}
}
