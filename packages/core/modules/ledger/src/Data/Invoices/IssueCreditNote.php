<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Invoices;

use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Api;

/**
 * Credits an issued invoice without rewriting it (COV-167, COV-174). `creditNoteRef` is the business identity; the
 * legal reference stays null until legal document numbering supplies it through the participant in the same root Tx.
 */
#[Api(since: '1.0')]
final readonly class IssueCreditNote
{
    /** @param list<CreditNoteLine> $lines */
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $creditNoteRef,
        public string $invoiceRef,
        public CreditNoteMode $mode,
        public array $lines,
        public string $reason,
        public ?string $legalDocumentRef = null,
    ) {}
}
