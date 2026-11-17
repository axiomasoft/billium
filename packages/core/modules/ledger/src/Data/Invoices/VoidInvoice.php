<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Invoices;

use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Api;

/** issued → void while nothing is settled; history stays and the obligations are released for a new invoice. */
#[Api(since: '1.0')]
final readonly class VoidInvoice
{
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $invoiceRef,
        public string $causeRef,
    ) {}
}
