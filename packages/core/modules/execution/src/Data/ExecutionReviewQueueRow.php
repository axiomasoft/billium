<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ExecutionReviewQueueRow
{
    /**
     * @param  array<string,mixed>  $expected
     * @param  array<string,mixed>  $observed
     */
    public function __construct(
        public string $caseRef,
        public string $reviewRef,
        public string $status,
        public string $code,
        public string $severity,
        public string $ownerRef,
        public string $merchantAccountRef,
        public string $merchantRevisionRef,
        public string $currency,
        public ?string $rootOperationRef,
        public string $evidenceRef,
        public string $sourceDigest,
        public string $basisDigest,
        public array $expected,
        public array $observed,
        public string $provenance,
        public ?string $receiptRef,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $nextReviewAt,
        public int $ageSeconds,
    ) {}
}
