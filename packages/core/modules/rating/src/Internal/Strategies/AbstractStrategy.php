<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Strategies;

use Billium\Rating\Contracts\PricingStrategy;
use Billium\Rating\Data\Pricing\PriceTerms;
use Billium\Rating\Data\Pricing\PricingInput;
use Billium\Rating\Data\Pricing\PricingResult;
use Billium\Rating\Data\Pricing\PricingStep;
use Billium\Rating\Enums\IncrementMode;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Commitments\CommitmentApplier;
use Billium\Rating\Internal\Errors\RatingProblems;
use Billium\Rating\Internal\Math\Dec;
use Billium\Rating\Internal\Math\IntegerRounding;
use InvalidArgumentException;
use Packman\Attributes\Internal;

#[Internal]
abstract class AbstractStrategy implements PricingStrategy
{
    public function version(): int
    {
        return 1;
    }

    public function price(PricingInput $input): PricingResult
    {
        (new StrategyCatalog)->validate($input->price);
        if ($input->price->strategyId !== $this->id()) {
            throw RatingProblems::invalid('strategy mismatch');
        }
        try {
            $quantity = Dec::parse($input->quantity);
        } catch (InvalidArgumentException) {
            throw RatingProblems::invalid('invalid quantity');
        }
        if ($quantity->sign() < 0) {
            throw RatingProblems::negative();
        }
        $rules = $input->price->quantityRules;
        if (! is_numeric($rules->conversionNumerator) || ! is_numeric($rules->conversionDenominator)) {
            throw RatingProblems::invalid('invalid conversion');
        }
        if ($quantity->scale > $rules->quantityScale) {
            throw RatingProblems::invalid('quantity scale exceeded');
        }
        $numerator = bcmul(bcmul($quantity->unscaled, $rules->conversionNumerator, 0), bcpow('10', (string) $rules->quantityScale, 0), 0);
        $denominator = bcmul(bcpow('10', (string) $quantity->scale, 0), $rules->conversionDenominator, 0);
        if (bcmod($numerator, $denominator, 0) !== '0') {
            throw RatingProblems::invalid('conversion is not exact at quantity scale');
        }
        $billable = Dec::ofUnscaled(unscaled: bcdiv($numerator, $denominator, 0), scale: $rules->quantityScale);
        if ($rules->incrementMode !== IncrementMode::None) {
            $increment = Dec::parse($rules->billingIncrement)->rescale($rules->quantityScale);
            $multiple = IntegerRounding::divide(num: $billable->unscaled, den: $increment->unscaled, mode: $rules->incrementMode === IncrementMode::Ceil ? 'up' : 'down');
            $billable = Dec::ofUnscaled(unscaled: bcmul($multiple, $increment->unscaled, 0), scale: $rules->quantityScale);
        }
        $raw = $this->raw($billable, $input->price->terms);
        $priced = $raw;
        $commitment = $input->price->commitment;
        if ($commitment !== null) {
            $basis = $input->commitmentBasis;
            if ($basis === null || preg_match('/^(0|[1-9][0-9]*)$/D', $basis->alreadyBilledNetMinor) !== 1 || $basis->basisDigest === '') {
                throw RatingProblems::invalid('frozen commitment basis required');
            }
            $priced = CommitmentApplier::apply(raw: $raw, exponent: $input->price->currencyExponent, minimumMinor: $commitment->minimumMinor, maximumMinor: $commitment->maximumMinor, alreadyBilledNetMinor: $basis->alreadyBilledNetMinor);
        } elseif ($input->commitmentBasis !== null) {
            throw RatingProblems::unsupported('basis without commitment');
        }
        $adjustment = $priced->sub($raw);
        $steps = [new PricingStep(label: 'raw', quantityOrBase: $billable->toString(), rate: null, amount: $raw->toString()), new PricingStep(label: 'commitment', quantityOrBase: $raw->toString(), rate: null, amount: $adjustment->toString())];
        $digest = CanonicalJson::digest(['input' => $input, 'billableQuantity' => $billable->toString(), 'rawAmount' => $raw->toString(), 'commitmentAdjustment' => $adjustment->toString(), 'pricedAmount' => $priced->toString(), 'steps' => $steps]);

        return new PricingResult(billableQuantity: $billable->toString(), rawAmount: $raw->toString(), commitmentAdjustment: $adjustment->toString(), pricedAmount: $priced->toString(), steps: $steps, resultDigest: $digest);
    }

    abstract protected function raw(Dec $quantity, PriceTerms $terms): Dec;
}
