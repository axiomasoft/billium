<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Strategies;

use Billium\Rating\Data\Pricing\PriceTerms;
use Billium\Rating\Data\Pricing\VolumeTerms;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Internal\Errors\RatingProblems;
use Billium\Rating\Internal\Math\Dec;
use Packman\Attributes\Internal;

#[Internal]
final class VolumeStrategy extends AbstractStrategy
{
    public function id(): StrategyId
    {
        return StrategyId::TieredVolume;
    }

    protected function raw(Dec $quantity, PriceTerms $terms): Dec
    {
        assert($terms instanceof VolumeTerms);
        if ($quantity->isZero()) {
            return Dec::parse('0');
        }
        foreach ($terms->tiers as $tier) {
            if ($quantity->cmp(Dec::parse($tier->lower)) >= 0 && ($tier->upper === null || $quantity->cmp(Dec::parse($tier->upper)) < 0)) {
                return $quantity->mul(Dec::parse($tier->unitPrice))->add(Dec::parse($tier->flatFee));
            }
        }

        throw RatingProblems::invalid('no matching tier');
    }
}
