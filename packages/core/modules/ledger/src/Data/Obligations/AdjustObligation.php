<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Obligations;

use Billium\Contracts\Data\Money;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Api;

/** Signed Money appends an increase (>0) or decrease (<0); the journal stores a positive magnitude. */
#[Api(since: '1.0')]
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
