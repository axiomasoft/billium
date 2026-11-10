<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Pricing;

use Billium\Contracts\Attributes\Api;

#[Api]
final readonly class VolumeTerms implements PriceTerms
{
    /** @param list<Tier> $tiers */
    public function __construct(
        public array $tiers,
    ) {}
}
