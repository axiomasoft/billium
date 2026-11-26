<?php

declare(strict_types=1);

namespace Billium\Schedule\Data\Commands;

use Packman\Attributes\Api;

#[Api]
final readonly class CancelSchedule
{
    public function __construct(
        public string $sellerRef, public string $environment, public string $scheduleRef,
        public int $expectedRevision, public int $expectedRowVersion, public int $expectedCancelGeneration,
        public string $commandKey,
    ) {}
}
