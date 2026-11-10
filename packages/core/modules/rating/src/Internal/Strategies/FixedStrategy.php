<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Strategies;

use Billium\Rating\Data\Pricing\FixedTerms;
use Billium\Rating\Data\Pricing\PriceTerms;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Internal\Math\Dec;

final class FixedStrategy extends AbstractStrategy
{
    public function id(): StrategyId
    {
        return StrategyId::Fixed;
    }

    protected function raw(Dec $quantity, PriceTerms $terms): Dec
    {
        assert($terms instanceof FixedTerms);

        return Dec::parse($terms->amount);
    }
}
