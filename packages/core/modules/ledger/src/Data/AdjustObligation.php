<?php

declare(strict_types=1);

namespace Billium\Ledger\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Data\Money;

/** Signed Money appends an increase (>0) or decrease (<0); the journal stores a positive magnitude (INV-12). */
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
