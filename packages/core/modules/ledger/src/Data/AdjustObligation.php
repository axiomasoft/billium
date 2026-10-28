<?php

declare(strict_types=1);

namespace Billium\Ledger\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Data\Money;

/** Append a correction without changing the original terms. Owning validation and mutation belong to P3.8. */
#[Api]
final readonly class AdjustObligation
{
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $adjustmentRef,
        public string $obligationRef,
        public Money $money,
        public string $causeRef,
    ) {}
}
