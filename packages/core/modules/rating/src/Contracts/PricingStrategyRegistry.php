<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Rating\Data\Pricing\PriceVersion;
use Billium\Rating\Enums\StrategyId;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(SpiKind::Call)]
interface PricingStrategyRegistry
{
    public function resolve(StrategyId $id, int $version): PricingStrategy;

    public function validate(PriceVersion $version): void;
}
