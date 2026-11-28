<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Reducers;

use Billium\Collection\Data\DunningPolicyStepDefinition;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DunningStepPlanDecision
{
    public function __construct(
        public ?int $priorStepId,
        public DunningPolicyStepDefinition $stepDefinition,
        public int $amountMinor,
    ) {}
}
