<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Pricing;

use Billium\Contracts\Attributes\Api;

#[Api]
final readonly class GraduatedTerms implements PriceTerms
{
    /** @param list<Tier> $tiers */
    public function __construct(
        public array $tiers,
    ) {}
}
