<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Pricing;

use Packman\Attributes\Api;

#[Api]
final readonly class PricingResult
{
    /** @param list<PricingStep> $steps */
    public function __construct(
        public string $billableQuantity,
        public string $rawAmount,
        public string $commitmentAdjustment,
        public string $pricedAmount,
        public array $steps,
        public string $resultDigest,
    ) {}
}
