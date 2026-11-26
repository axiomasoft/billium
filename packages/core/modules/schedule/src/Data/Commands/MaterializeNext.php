<?php

declare(strict_types=1);

namespace Billium\Schedule\Data\Commands;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api]
final readonly class MaterializeNext
{
    public function __construct(
        public string $sellerRef, public string $environment, public string $scheduleRef,
        public int $expectedRowVersion, public int $expectedCancelGeneration,
        public DateTimeImmutable $asOf, public string $commandKey,
    ) {}
}
