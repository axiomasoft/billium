<?php

declare(strict_types=1);

namespace Billium\Ledger\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Data\Money;

/**
 * Monetary customer credit in one explicit account; no reward/quota or persistence behavior (D60).
 */
#[Api]
final readonly class RecordCustomerCredit
{
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $creditRef,
        public string $sourceRef,
        public Money $money,
    ) {}
}
