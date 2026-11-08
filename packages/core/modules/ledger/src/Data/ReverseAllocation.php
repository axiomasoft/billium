<?php

declare(strict_types=1);

namespace Billium\Ledger\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Data\Money;

/**
 * Reverses the whole original allocation `allocationRef` with an immutable linked negative row (UNIQUE per original);
 * a partial correction names the `remainder` that is placed again from the same source in the same Tx (COV-289).
 * A string `allocationRef` names an Allocate reference; an integer selects the immutable journal id returned as
 * `remainder_allocation_ref` by a partial reversal. Numeric strings retain their original reference semantics.
 */
#[Api]
final readonly class ReverseAllocation
{
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $reversalRef,
        public string|int $allocationRef,
        public string $causeRef,
        public ?Money $remainder = null,
    ) {}
}
