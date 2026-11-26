<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DocumentCreditNoteLineView
{
    public function __construct(
        public int $id,
        public string $sellerRef,
        public string $environment,
        public int $creditNoteId,
        public string $originalInvoiceLineId,
        public string $originalObligationRef,
        public string $originalComponentRef,
        public string $originalTaxSnapshotRef,
        public string $originalRoundingPolicyVersion,
        public string $creditedQuantity,
        public int $netMinor,
        public int $taxMinor,
        public int $grossMinor,
        public string $currency,
        public int $cumulativeNetMinor,
        public int $cumulativeTaxMinor,
        public int $cumulativeGrossMinor,
        public int $originalBasisNetMinor,
        public int $originalBasisTaxMinor,
        public int $originalBasisGrossMinor,
    ) {}
}
