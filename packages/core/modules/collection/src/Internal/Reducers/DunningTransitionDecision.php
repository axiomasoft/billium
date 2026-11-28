<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Reducers;

use Billium\Collection\Enums\DunningCaseStatus;
use DateTimeImmutable;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DunningTransitionDecision
{
    public function __construct(
        public DunningCaseStatus $caseStatus,
        public int $currentOpenMinor,
        public int $businessDeclineAttemptsUsed,
        public ?int $activeStepNo,
        public int $lastCompletedStepNo,
        public ?string $parkedReason,
        public ?string $parkedStepRef,
        public ?DateTimeImmutable $nextAttemptAt,
        public ?string $closedReason,
        public ?DateTimeImmutable $closedAt,
        public bool $nextStepEligible,
        public ?int $nextStepNo,
    ) {}
}
