<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Invoices;

use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Api;

/** draft → discarded; releases the obligations of the lines. */
#[Api(since: '1.0')]
final readonly class DiscardInvoice
{
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $invoiceRef,
        public string $causeRef,
    ) {}
}
