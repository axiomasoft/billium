<?php

declare(strict_types=1);

namespace Billium\Schedule\Contracts;

use Billium\Schedule\Data\PeriodQuote;
use Billium\Schedule\Data\ScheduleReceipt;
use Billium\Schedule\Data\ScheduleRevisionTerms;
use Packman\Attributes\Api;

#[Api]
interface AgreementRevisionCommit
{
    public function preview(ScheduleRevisionTerms $terms, int $ordinal, string $validUntil, string $commandKey): PeriodQuote;

    public function commit(string $quoteRef, string $quoteDigest, int $expectedRevision, int $cancellationGeneration, string $commandKey): ScheduleReceipt;

    /** Versioned integration query; always scoped to the authenticated seller/environment (D23/D56). */
    public function coverage(string $coverageRef): ?ScheduleReceipt;
}
