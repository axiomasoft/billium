<?php

declare(strict_types=1);

namespace Billium\Schedule\Data\Commands;

use Billium\Contracts\Attributes\Api;
use Billium\Schedule\Enums\OccurrenceState;

#[Api]
final readonly class AdvanceOccurrence
{
    public function __construct(
        public string $sellerRef, public string $environment, public string $occurrenceRef,
        public OccurrenceState $to, public ?string $ratedBasisRef,
    ) {}
}
