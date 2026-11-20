<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DunningCaseTransitionResult
{
    public function __construct(
        public DunningCaseView $case,
        public CollectionStepView $step,
        public bool $nextStepEligible,
        public ?int $nextStepNo,
        public ?DateTimeImmutable $nextEligibleAt,
    ) {}
}
