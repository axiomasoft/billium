<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\DunningCaseStatus;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DunningCaseView
{
    public function __construct(
        public int $id,
        public string $sellerRef,
        public string $environment,
        public string $caseRef,
        public string $billingAccountRef,
        public string $obligationRef,
        public ?string $componentRef,
        public int $collectionGeneration,
        public int $policyId,
        public string $policyRef,
        public int $policyVersion,
        public string $currency,
        public int $initialAmountMinor,
        public int $currentOpenMinor,
        public DunningCaseStatus $status,
        public int $caseRevision,
        public int $cancellationGeneration,
        public int $businessDeclineAttemptsUsed,
        public int $maxBusinessDeclineAttempts,
        public ?int $activeStepNo,
        public int $lastCompletedStepNo,
        public ?DateTimeImmutable $nextAttemptAt,
        public DateTimeImmutable $recoveryDeadlineAt,
        public ?string $parkedReason,
        public ?string $parkedStepRef,
        public ?string $closedReason,
        public ?DateTimeImmutable $closedAt,
        public DateTimeImmutable $openedAt,
        public DateTimeImmutable $updatedAt,
        public string $caseDigest,
    ) {}
}
