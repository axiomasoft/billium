<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Billium\Documents\Enums\DocumentType;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class RenderDocumentInput
{
    /**
     * @param  array<string, mixed>  $sellerSnapshot
     * @param  array<string, mixed>  $buyerSnapshot
     * @param  array<string, mixed>  $taxSnapshot
     * @param  array<string, mixed>  $termsSnapshot
     * @param  list<array<string, mixed>>  $linesSnapshot
     * @param  array<string, mixed>  $displayMetadata
     * @param  list<string>  $assetRefs
     */
    public function __construct(
        public string $sellerRef,
        public string $environment,
        public string $legalDocumentRef,
        public int $documentRevision,
        public string $legalNumber,
        public DocumentType $documentType,
        public string $approvedSnapshotDigest,
        public string $templateId,
        public string $templateVersion,
        public string $templateDigest,
        public string $locale,
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
        public array $assetRefs = [],
    ) {}
}
