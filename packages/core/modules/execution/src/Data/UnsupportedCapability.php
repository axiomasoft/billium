<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Execution\Enums\CapabilityRequirement;

#[Api]
final readonly class UnsupportedCapability
{
    public ProblemCode $problem;

    public function __construct(
        public CapabilityRequirement $requirement,
        public string $reason = 'unsupported_or_unverified',
    ) {
        $this->problem = ProblemCode::CapabilityUnsupported;
    }
}
