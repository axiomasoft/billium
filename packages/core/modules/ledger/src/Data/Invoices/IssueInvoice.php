<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Invoices;

use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Api;

/** Issue an existing draft; legal numbering is supplied by the composing root through LedgerParticipants (D49). */
#[Api(since: '1.0')]
final readonly class IssueInvoice
{
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $invoiceRef,
    ) {}
}
