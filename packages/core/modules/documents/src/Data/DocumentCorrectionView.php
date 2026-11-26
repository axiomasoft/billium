<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Billium\Documents\Enums\CorrectionKind;
use Billium\Documents\Enums\TaxCorrectionTreatment;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DocumentCorrectionView
{
    /** @param array<string, mixed> $taxPolicySnapshot */
    public function __construct(
        public int $id,
        public string $sellerRef,
        public string $environment,
        public string $correctionRef,
        public CorrectionKind $correctionKind,
        public string $originalLegalDocumentRef,
        public ?string $resultingLegalDocumentRef,
        public ?string $ledgerCreditNoteRef,
        public ?string $ledgerObligationRef,
        public ?string $parentOccurrenceRef,
        public int $correctionRevision,
        public string $taxCorrectionPolicyRef,
        public string $taxCorrectionPolicyVersion,
        public TaxCorrectionTreatment $taxTreatment,
        public array $taxPolicySnapshot,
        public string $currency,
        public int $netMinor,
        public int $taxMinor,
        public int $grossMinor,
        public string $reason,
        public ?string $allocationMode,
        public string $receiptRef,
        public string $createdAt,
    ) {}
}
