<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Rating\Data\Pricing\PricingInput;
use Billium\Rating\Data\Pricing\PricingResult;
use Billium\Rating\Enums\StrategyId;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(SpiKind::Implement)]
interface PricingStrategy
{
    public function id(): StrategyId;

    public function version(): int;

    public function price(PricingInput $input): PricingResult;
}
