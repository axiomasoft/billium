<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class IssueLegalDocument
{
    /** @param array<string, mixed> $displayMetadata */
    public function __construct(
        public string $commandKey,
        public BillingAccountScope $scope,
        public string $invoiceRef,
        public string $economicRef,
        public int $documentRevision,
        public string $finalizationRef,
        public string $approvedSnapshotDigest,
        public string $seriesRef,
        public string $periodKey,
        public array $displayMetadata = [],
        public ?string $legalDocumentRef = null,
    ) {}
}
