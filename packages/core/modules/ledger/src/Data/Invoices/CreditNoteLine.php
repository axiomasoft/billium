<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Invoices;

use Billium\Contracts\Data\Money;
use Packman\Attributes\Api;

/** One credited obligation of the invoice, the positive amount credited, and optional legal/tax/rounding provenance. */
#[Api(since: '1.0')]
final readonly class CreditNoteLine
{
    public function __construct(
        public string $obligationRef,
        public Money $money,
        public ?string $originalInvoiceLineId = null,
        public ?string $originalComponentRef = null,
        public ?string $originalTaxSnapshotRef = null,
        public ?string $originalRoundingPolicyVersion = null,
        public ?string $creditedQuantity = null,
    ) {}
}
