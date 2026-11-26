<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\ManualOverrideAction;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ApplyManualIntervention
{
    public function __construct(
        public string $commandKey,
        public string $interventionRef,
        public CollectionScope $scope,
        public string $caseRef,
        public ManualOverrideAction $action,
        public int $expectedCaseRevision,
        public string $reason,
        public string $recoveryProofRef,
        public string $recoveryProofDigest,
        public ?string $stepRef = null,
        public ?int $expectedStepRevision = null,
        public ?DateTimeImmutable $nextAttemptAt = null,
        public ?DateTimeImmutable $recordedAt = null,
    ) {}
}
