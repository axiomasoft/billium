<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Pricing;

use Packman\Attributes\Api;

#[Api]
final readonly class PricingStep
{
    public function __construct(
        public string $label,
        public string $quantityOrBase,
        public ?string $rate,
        public string $amount,
    ) {}
}
