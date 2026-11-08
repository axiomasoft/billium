<?php

declare(strict_types=1);

namespace Billium\Ledger\Data;

use Billium\Contracts\Attributes\Api;

/** Issue an existing draft; legal numbering is supplied by the composing root through LedgerParticipants (D49). */
#[Api]
final readonly class IssueInvoice
{
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $invoiceRef,
    ) {}
}
