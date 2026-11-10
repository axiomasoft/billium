<?php

declare(strict_types=1);

namespace Billium\Schedule\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Schedule\Data\DueBatch;
use DateTimeImmutable;

#[Api]
interface ScheduleQueries
{
    /** @return array<string, mixed>|null */
    public function occurrence(string $occurrenceRef): ?array;

    public function dueSchedules(string $sellerRef, DateTimeImmutable $asOf, int $limit = 100, ?string $cursor = null): DueBatch;

    /** @return array{dueCount: int, oldestDueAgeSeconds: int} */
    public function dueStats(string $sellerRef, DateTimeImmutable $asOf): array;

    /** @return array{revision: int, frontierDigest: ?string} */
    public function correctionHead(string $sellerRef, string $parentOccurrenceRef): array;
}
