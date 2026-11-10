<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Pricing;

use Billium\Contracts\Attributes\Api;

#[Api]
final readonly class PricingInput
{
    public function __construct(
        public PriceVersion $price,
        public string $quantity,
        public ?CommitmentBasis $commitmentBasis,
    ) {}
}
