<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class IssuePositiveDocumentCorrection
{
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $correctionRef,
        public string $originalLegalDocumentRef,
        public string $obligationRef,
        public string $customerRef,
        public string $componentRef,
        public string $parentOccurrenceRef,
        public int $correctionRevision,
        public int $deltaNetMinor,
        public int $deltaTaxMinor,
        public string $currency,
        public DocumentTaxCorrectionPolicy $taxPolicy,
        public string $reason,
        public bool $issueLegalDocument = false,
        public ?string $seriesRef = null,
        public ?string $periodKey = null,
        public ?string $finalizationRef = null,
        public ?string $approvedSnapshotDigest = null,
    ) {}
}
