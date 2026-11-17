<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Rating;

use Billium\Rating\Data\Pricing\PriceVersion;
use Packman\Attributes\Api;

#[Api]
final readonly class FrozenPrice
{
    public function __construct(
        public string $componentRef,
        public string $priceRef,
        public int $priceVersion,
        public string $strategyId,
        public int $strategyVersion,
        public string $termsDigest,
        public ?string $commitmentScopeDigest,
        public ?string $commitmentBasisDigest,
        public ?string $alreadyBilledNetMinor,
        public string $roundingPolicyVersion,
        public string $exponentVersion,
        public PriceVersion $snapshot,
    ) {}
}
