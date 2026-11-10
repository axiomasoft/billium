<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Pricing;

use Billium\Contracts\Attributes\Api;

#[Api]
final readonly class Tier
{
    public function __construct(
        public int $index,
        public string $lower,
        public ?string $upper,
        public string $unitPrice,
        public string $flatFee,
    ) {}
}
