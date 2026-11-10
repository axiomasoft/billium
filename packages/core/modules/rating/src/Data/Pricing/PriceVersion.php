<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Pricing;

use Billium\Contracts\Attributes\Api;
use Billium\Rating\Enums\RoundingMode;
use Billium\Rating\Enums\RoundingStage;
use Billium\Rating\Enums\StrategyId;

#[Api]
final readonly class PriceVersion
{
    public function __construct(
        public string $sellerRef,
        public string $priceRef,
        public int $version,
        public StrategyId $strategyId,
        public int $strategyVersion,
        public string $currency,
        public int $currencyExponent,
        public string $exponentVersion,
        public string $unit,
        public QuantityRules $quantityRules,
        public PriceTerms $terms,
        public RoundingMode $roundingMode,
        public RoundingStage $roundingStage,
        public string $roundingPolicyVersion,
        public ?CommitmentTerms $commitment,
        public string $termsDigest,
    ) {}
}
