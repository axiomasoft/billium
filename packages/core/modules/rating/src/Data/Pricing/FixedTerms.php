<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Pricing;

use Packman\Attributes\Api;

#[Api]
final readonly class FixedTerms implements PriceTerms
{
    public function __construct(
        public string $amount,
        public string $scope,
    ) {}
}
