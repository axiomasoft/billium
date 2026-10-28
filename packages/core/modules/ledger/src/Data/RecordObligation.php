<?php

declare(strict_types=1);

namespace Billium\Ledger\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Data\Money;

/**
 * Immutable command input; persistence and root transaction admission belong to the ledger runner (D60).
 */
#[Api]
final readonly class RecordObligation
{
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $obligationRef,
        public string $basisRef,
        public string $customerRef,
        public string $componentRef,
        public Money $money,
        public string $termsDigest,
        public ?string $priceVersionRef = null,
        public ?string $taxSnapshotRef = null,
        public ObligationDuePolicy $duePolicy = new ObligationDuePolicy,
    ) {}
}
