<?php

declare(strict_types=1);

namespace Billium\Ledger\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Data\Money;

/**
 * Reverses the whole original allocation `allocationRef` with an immutable linked negative row (UNIQUE per original);
 * a partial correction names the `remainder` that is placed again from the same source in the same Tx (COV-289).
 */
#[Api]
final readonly class ReverseAllocation
{
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $reversalRef,
        public string $allocationRef,
        public string $causeRef,
        public ?Money $remainder = null,
    ) {}
}
