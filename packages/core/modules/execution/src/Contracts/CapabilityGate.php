<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\QualificationManifest;
use Billium\Execution\Data\UnsupportedCapability;
use Billium\Execution\Enums\CapabilityRequirement;

/** Pure preflight, before OperationRunner admission (D57). No persistence, authority service or network. */
#[Api]
final readonly class CapabilityGate
{
    public function check(
        CapabilitySnapshot $snapshot,
        CapabilityRequirement $requirement,
        ?QualificationManifest $qualification = null,
        bool $production = false,
    ): ?UnsupportedCapability {
        if (! $snapshot->supports($requirement)) {
            return new UnsupportedCapability(requirement: $requirement);
        }
        if ($qualification === null || ! $qualification->qualifies(snapshot: $snapshot, production: $production)) {
            return new UnsupportedCapability(requirement: $requirement, reason: 'qualification_missing_or_mismatched');
        }

        return null;
    }
}
