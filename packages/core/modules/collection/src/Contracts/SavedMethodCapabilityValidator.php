<?php

declare(strict_types=1);

namespace Billium\Collection\Contracts;

use Billium\Collection\Data\SavedMethodCapabilityRequirement;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\MerchantRevision;
use Packman\Attributes\Api;

/**
 * Validates that a merchant revision and its qualified capability snapshot support saved-method/mandate collection.
 */
#[Api(since: '1.0')]
interface SavedMethodCapabilityValidator
{
    public function assertMerchantSupportsSavedMethod(
        MerchantRevision $merchant,
        CapabilitySnapshot $capabilities,
        SavedMethodCapabilityRequirement $requirement,
    ): void;
}
