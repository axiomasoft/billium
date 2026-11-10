<?php

declare(strict_types=1);

namespace Billium\Rating\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Rating\Data\Pricing\PricingInput;
use Billium\Rating\Data\Pricing\PricingResult;
use Billium\Rating\Enums\StrategyId;

#[Api]
#[Spi(SpiKind::Implement)]
interface PricingStrategy
{
    public function id(): StrategyId;

    public function version(): int;

    public function price(PricingInput $input): PricingResult;
}
