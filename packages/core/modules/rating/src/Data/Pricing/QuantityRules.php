<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Pricing;

use Billium\Contracts\Attributes\Api;
use Billium\Rating\Enums\IncrementMode;

#[Api]
final readonly class QuantityRules
{
    public function __construct(
        public int $quantityScale,
        public string $conversionNumerator,
        public string $conversionDenominator,
        public string $billingIncrement,
        public IncrementMode $incrementMode,
    ) {}
}
