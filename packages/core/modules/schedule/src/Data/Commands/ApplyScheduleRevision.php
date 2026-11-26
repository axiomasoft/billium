<?php

declare(strict_types=1);

namespace Billium\Schedule\Data\Commands;

use Billium\Schedule\Data\ScheduleRevisionTerms;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api]
final readonly class ApplyScheduleRevision
{
    public function __construct(
        public string $sellerRef, public string $environment, public string $scheduleRef,
        public int $expectedRevision, public int $expectedRowVersion, public int $newRevision,
        public ScheduleRevisionTerms $terms, public DateTimeImmutable $effectiveFrom,
        public string $inputsDigest, public string $commandKey,
    ) {}
}
