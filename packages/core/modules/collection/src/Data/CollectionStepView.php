<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\CollectionChannel;
use Billium\Collection\Enums\CollectionStepStatus;
use Billium\Collection\Enums\DeclineCategory;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class CollectionStepView
{
    public function __construct(
        public int $id,
        public string $sellerRef,
        public string $environment,
        public int $dunningCaseId,
        public string $caseRef,
        public string $stepRef,
        public int $stepNo,
        public int $policyId,
        public string $policyRef,
        public int $policyVersion,
        public CollectionChannel $channel,
        public string $businessPaymentIntentRef,
        public string $operationRef,
        public ?string $stepAuthorizationRef,
        public string $currency,
        public int $amountMinor,
        public CollectionStepStatus $status,
        public int $stepRevision,
        public int $deliveryAttemptsUsed,
        public int $maxDeliveryRetries,
        public ?int $priorStepId,
        public ?DeclineCategory $declineCategory,
        public ?string $declineReason,
        public ?string $outcomeEvidenceDigest,
        public DateTimeImmutable $scheduledAt,
        public ?DateTimeImmutable $lastDeliveryAttemptAt,
        public ?DateTimeImmutable $terminalAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public string $stepDigest,
    ) {}
}
