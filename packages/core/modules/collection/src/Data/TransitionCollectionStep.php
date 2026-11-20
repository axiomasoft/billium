<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\CollectionStepStatus;
use Billium\Collection\Enums\DeclineCategory;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class TransitionCollectionStep
{
    public function __construct(
        public string $commandKey,
        public CollectionScope $scope,
        public string $caseRef,
        public string $stepRef,
        public CollectionStepStatus $targetStatus,
        public int $expectedStepRevision,
        public DateTimeImmutable $occurredAt,
        public ?DeclineCategory $declineCategory = null,
        public ?string $declineReason = null,
        public ?string $outcomeEvidenceDigest = null,
        public ?string $stepAuthorizationRef = null,
        public ?int $updatedOpenMinor = null,
    ) {}
}
