<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Strategies;

use Billium\Rating\Data\Pricing\PerUnitTerms;
use Billium\Rating\Data\Pricing\PriceTerms;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Internal\Math\Dec;
use Packman\Attributes\Internal;

#[Internal]
final class PerUnitStrategy extends AbstractStrategy
{
    public function id(): StrategyId
    {
        return StrategyId::PerUnit;
    }

    protected function raw(Dec $quantity, PriceTerms $terms): Dec
    {
        assert($terms instanceof PerUnitTerms);

        return $quantity->mul(Dec::parse($terms->unitPrice));
    }
}
