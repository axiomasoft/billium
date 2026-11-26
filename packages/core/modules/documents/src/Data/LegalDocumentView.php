<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\LegalDocumentStatus;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class LegalDocumentView
{
    /**
     * @param  array<string, mixed>  $sellerSnapshot
     * @param  array<string, mixed>  $buyerSnapshot
     * @param  array<string, mixed>  $taxSnapshot
     * @param  array<string, mixed>  $termsSnapshot
     * @param  list<array<string, mixed>>  $linesSnapshot
     * @param  array<string, mixed>  $displayMetadata
     */
    public function __construct(
        public int $id,
        public string $legalDocumentRef,
        public string $sellerRef,
        public string $environment,
        public DocumentType $documentType,
        public string $economicRef,
        public int $documentRevision,
        public string $seriesRef,
        public string $periodKey,
        public int $sequenceNumber,
        public string $legalNumber,
        public LegalDocumentStatus $status,
        public int $profileVersion,
        public int $finalizationId,
        public int $basisRevision,
        public string $basisDigest,
        public string $approvedSnapshotDigest,
        public string $currency,
        public int $subtotalMinor,
        public int $taxMinor,
        public int $totalMinor,
        public array $sellerSnapshot,
        public array $buyerSnapshot,
        public array $taxSnapshot,
        public array $termsSnapshot,
        public array $linesSnapshot,
        public array $displayMetadata,
        public ?string $originalLegalDocumentRef,
        public ?string $voidReason,
        public ?string $voidedAt,
        public string $receiptRef,
        public string $issuedAt,
    ) {}
}
