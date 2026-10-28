<?php

declare(strict_types=1);

namespace Billium\Ledger\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Data\Money;

/** Credit an issued invoice without rewriting it. Owning validation and mutation belong to P3.8. */
#[Api]
final readonly class IssueCreditNote
{
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $creditNoteRef,
        public string $invoiceRef,
        public Money $money,
        public string $reason,
        public string $legalDocumentRef,
    ) {}
}
