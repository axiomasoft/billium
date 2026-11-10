<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Enums\StrategyId;

#[Api]
#[Spi(SpiKind::Call)]
interface PricingStrategyRegistry
{
    public function resolve(StrategyId $id, int $version): PricingStrategy;

    public function validate(PriceVersion $version): void;
}
