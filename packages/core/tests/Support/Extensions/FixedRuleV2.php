<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Support\Extensions;

use Billium\Rating\Contracts\PricingStrategy;
use Billium\Rating\Data\Pricing\FixedTerms;
use Billium\Rating\Data\Pricing\PricingInput;
use Billium\Rating\Data\Pricing\PricingResult;
use Billium\Rating\Enums\StrategyId;
use LogicException;

/** Host extension double: public SPI/data only, no storage or neighbour imports. */
final class FixedRuleV2 implements PricingStrategy
{
    public function id(): StrategyId
    {
        return StrategyId::Fixed;
    }

    public function version(): int
    {
        return 2;
    }

    public function price(PricingInput $input): PricingResult
    {
        $terms = $input->price->terms;
        if (! $terms instanceof FixedTerms || $input->price->commitment !== null) {
            throw new LogicException('Unsupported test extension terms');
        }

        return new PricingResult(billableQuantity: '1', rawAmount: $terms->amount, commitmentAdjustment: '0',
            pricedAmount: $terms->amount, steps: [], resultDigest: 'sha256:'.hash('sha256', $terms->amount));
    }
}
