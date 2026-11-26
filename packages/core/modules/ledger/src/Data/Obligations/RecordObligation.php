<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Obligations;

use Billium\Contracts\Data\Money;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Api;

/**
 * Immutable command input; persistence and root transaction admission belong to the ledger runner (D60).
 */
#[Api(since: '1.0')]
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
