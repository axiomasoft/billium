<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Billium\Ledger\Data\Invoices\CreditNoteMode;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class IssueDocumentCreditNote
{
    /** @param list<CreditNoteLineRequest> $lines */
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $correctionRef,
        public string $creditNoteRef,
        public string $originalLegalDocumentRef,
        public string $invoiceRef,
        public CreditNoteMode $mode,
        public array $lines,
        public DocumentTaxCorrectionPolicy $taxPolicy,
        public string $reason,
        public bool $issueLegalDocument = false,
        public ?string $seriesRef = null,
        public ?string $periodKey = null,
    ) {}
}
