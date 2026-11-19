<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Billium\Documents\Enums\DiscountTaxOrder;
use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\TaxConvention;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class CreateDocumentDraft
{
    /**
     * @param  list<DocumentLineBasis>  $lines
     * @param  array<string, mixed>  $buyerSnapshot
     * @param  array<string, mixed>  $termsSnapshot
     */
    public function __construct(
        public DocumentScope $scope,
        public string $finalizationRef,
        public DocumentType $documentType,
        public string $economicRef,
        public int $basisRevision,
        public string $basisDigest,
        public string $profileRef,
        public int $profileVersion,
        public string $currency,
        public int $currencyExponent,
        public TaxConvention $convention,
        public string $roundingMode,
        public DiscountTaxOrder $discountTaxOrder,
        public array $lines,
        public array $buyerSnapshot,
        public array $termsSnapshot,
    ) {}
}
