<?php

declare(strict_types=1);

namespace Billium\Schedule\Data\Commands;

use Billium\Contracts\Attributes\Api;
use Billium\Schedule\Enums\OccurrencePurpose;
use DateTimeImmutable;

#[Api]
final readonly class EnsureOccurrence
{
    public function __construct(
        public string $sellerRef, public string $environment, public string $componentRef,
        public OccurrencePurpose $purpose, public DateTimeImmutable $periodStart, public DateTimeImmutable $periodEnd,
        public string $scheduleRef, public int $scheduleRevision, public string $inputsDigest,
        public ?string $parentOccurrenceRef = null, public ?string $commandKey = null, public int $ordinal = 0,
    ) {}
}
