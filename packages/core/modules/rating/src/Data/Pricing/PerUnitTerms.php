<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Pricing;

use Billium\Contracts\Attributes\Api;

#[Api]
final readonly class PerUnitTerms implements PriceTerms
{
    public function __construct(
        public string $unitPrice,
    ) {}
}
