<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Billium\Documents\Enums\DiscountTaxOrder;
use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\TaxConvention;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class TaxSnapshotRequest
{
    /**
     * @param  list<DocumentLineBasis>  $lines
     * @param  array<string, mixed>  $buyerSnapshot
     * @param  array<string, mixed>  $sellerSnapshot
     */
    public function __construct(
        public string $sellerRef,
        public string $environment,
        public string $finalizationRef,
        public int $finalizationRevision,
        public DocumentType $documentType,
        public string $economicRef,
        public int $basisRevision,
        public string $basisDigest,
        public string $inputDigest,
        public string $taxSnapshotSchema,
        public string $jurisdictionRef,
        public string $currency,
        public int $currencyExponent,
        public TaxConvention $convention,
        public string $roundingMode,
        public DiscountTaxOrder $discountTaxOrder,
        public array $lines,
        public array $buyerSnapshot,
        public array $sellerSnapshot,
    ) {}
}
