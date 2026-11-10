<?php

declare(strict_types=1);

namespace Billium\Schedule\Data\Commands;

use Billium\Contracts\Attributes\Api;
use DateTimeImmutable;

#[Api]
final readonly class RecordScheduleFailure
{
    public function __construct(
        public string $sellerRef, public string $environment, public string $scheduleRef,
        public int $expectedRowVersion, public DateTimeImmutable $asOf, public string $commandKey,
    ) {}
}
