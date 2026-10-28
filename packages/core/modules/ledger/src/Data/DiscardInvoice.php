<?php

declare(strict_types=1);

namespace Billium\Ledger\Data;

use Billium\Contracts\Attributes\Api;

/** draft → discarded; releases the obligations of the lines. */
#[Api]
final readonly class DiscardInvoice
{
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $invoiceRef,
        public string $causeRef,
    ) {}
}
