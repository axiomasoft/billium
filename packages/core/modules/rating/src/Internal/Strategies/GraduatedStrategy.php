<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Strategies;

use Billium\Rating\Data\Pricing\GraduatedTerms;
use Billium\Rating\Data\Pricing\PriceTerms;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Internal\Math\Dec;

final class GraduatedStrategy extends AbstractStrategy
{
    public function id(): StrategyId
    {
        return StrategyId::TieredGraduated;
    }

    protected function raw(Dec $quantity, PriceTerms $terms): Dec
    {
        assert($terms instanceof GraduatedTerms);
        $total = Dec::parse('0');
        foreach ($terms->tiers as $tier) {
            $lower = Dec::parse($tier->lower);
            if ($quantity->cmp($lower) <= 0) {
                break;
            }
            $top = $tier->upper === null ? $quantity : $quantity->min(Dec::parse($tier->upper));
            $total = $total->add($top->sub($lower)->mul(Dec::parse($tier->unitPrice)))->add(Dec::parse($tier->flatFee));
        }

        return $total;
    }
}
