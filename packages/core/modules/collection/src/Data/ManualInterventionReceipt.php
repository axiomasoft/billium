<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\DunningCaseStatus;
use Billium\Collection\Enums\ManualOverrideAction;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ManualInterventionReceipt
{
    public function __construct(
        public int $id,
        public string $sellerRef,
        public string $environment,
        public string $interventionRef,
        public int $dunningCaseId,
        public string $caseRef,
        public ?int $stepId,
        public ?string $stepRef,
        public ManualOverrideAction $action,
        public string $actorRef,
        public string $privilegeRef,
        public string $reason,
        public string $recoveryProofRef,
        public string $recoveryProofDigest,
        public int $fromCaseRevision,
        public int $toCaseRevision,
        public DunningCaseStatus $beforeStatus,
        public DunningCaseStatus $afterStatus,
        public string $beforeDigest,
        public string $afterDigest,
        public string $receiptDigest,
        public string $signalRef,
        public DateTimeImmutable $recordedAt,
        public bool $paymentOutcomeMutated = false,
        public bool $unknownOutcomeCleared = false,
    ) {}
}
