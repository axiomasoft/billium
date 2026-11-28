<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Invoices;

use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Api;

/** Creates a draft over unbilled obligations of one account and currency; the draft does not number or freeze anything. */
#[Api(since: '1.0')]
final readonly class CreateInvoice
{
    /**
     * @param  list<InvoiceLineInput>  $lines  ordered; the order is the line position
     * @param  array<string, mixed>  $snapshot  buyer and tax snapshot, frozen at issue
     */
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $invoiceRef,
        public string $customerRef,
        public array $lines,
        public array $snapshot = [],
    ) {}
}
