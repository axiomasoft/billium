<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\FinalizationState;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class DocumentFinalizationView
{
    /**
     * @param  array<string, mixed>  $buyerSnapshot
     * @param  array<string, mixed>  $termsSnapshot
     * @param  list<DocumentLineBasis>  $lines
     */
    public function __construct(
        public int $id,
        public string $sellerRef,
        public string $environment,
        public string $finalizationRef,
        public DocumentType $documentType,
        public string $economicRef,
        public int $basisRevision,
        public string $basisDigest,
        public int $profileId,
        public int $profileVersion,
        public int $finalizationRevision,
        public FinalizationState $state,
        public string $currency,
        public int $currencyExponent,
        public string $inputDigest,
        public ?string $taxSnapshotRef,
        public ?string $taxSnapshotDigest,
        public ?int $subtotalMinor,
        public ?int $taxMinor,
        public ?int $totalMinor,
        public ?string $approvedSnapshotDigest,
        public ?DocumentTaxSnapshot $taxSnapshot,
        public array $buyerSnapshot,
        public array $termsSnapshot,
        public array $lines,
    ) {}
}
