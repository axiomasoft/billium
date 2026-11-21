<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Invoices;

use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Api;

/**
 * One business credit that may span several invoices. Ledger issues one credit note per invoice and owns how the part
 * references derive from `creditNoteRef`; callers keep only the reference returned in CreditNoteGroupReceipt.
 */
#[Api(since: '1.0')]
final readonly class IssueCreditNoteGroup
{
    /** @param list<CreditNotePart> $parts */
    public function __construct(
        public BillingAccountScope $scope,
        public string $creditNoteRef,
        public CreditNoteMode $mode,
        public array $parts,
        public string $reason,
    ) {}
}
