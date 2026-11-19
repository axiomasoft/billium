<?php

declare(strict_types=1);

namespace Billium\Documents\Internal\Tax;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Documents\Contracts\DocumentTaxArithmetic;
use Billium\Documents\Data\TaxArithmeticLineInput;
use Billium\Documents\Data\TaxArithmeticLineResult;
use Billium\Documents\Data\TaxArithmeticResult;
use Billium\Documents\Enums\DiscountTaxOrder;
use Billium\Documents\Enums\TaxConvention;
use Billium\Documents\Internal\Math\Dec;
use Billium\Documents\Internal\Math\IntegerRounding;
use Billium\Documents\Internal\Math\LargestRemainder;
use InvalidArgumentException;
use Packman\Attributes\Internal;

/**
 * Pure zero-float bcmath document tax calculator supporting heterogeneous rates, inclusive/exclusive conventions,
 * discount/tax ordering, and deterministic largest-remainder rounding distribution across lines.
 */
#[Internal]
final class BcDocumentTaxArithmetic implements DocumentTaxArithmetic
{
    /** @param list<TaxArithmeticLineInput> $lines */
    public function calculate(
        array $lines,
        TaxConvention $convention,
        string $currency,
        string $roundingMode,
        DiscountTaxOrder $order,
        ?string $noTaxReason = null,
    ): TaxArithmeticResult {
        if ($lines === []) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'document tax arithmetic requires at least one line');
        }
        if (! in_array($roundingMode, ['down', 'up', 'half_up', 'half_even'], true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'unsupported rounding mode: '.$roundingMode);
        }
        $currencyObj = Currency::of($currency);
        $exp = $currencyObj->scale;
        $seen = [];
        $parsedLines = [];
        $maxScale = 0;
        foreach ($lines as $line) {
            if ($line->lineRef === '' || isset($seen[$line->lineRef])) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'duplicate or empty lineRef');
            }
            $seen[$line->lineRef] = true;
            try {
                $amount = Dec::parse($line->amountDecimal);
                $discount = Dec::parse($line->discountDecimal);
                $rate = Dec::parse($line->rateDecimal);
            } catch (InvalidArgumentException $exception) {
                throw new ProblemException(ProblemCode::ValidationInvalid, $exception->getMessage());
            }
            if ($amount->sign() < 0 || $discount->sign() < 0 || $rate->sign() < 0 || $rate->cmp(Dec::parse('10')) >= 0 || $rate->scale > 8) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'invalid line amount, discount or tax rate');
            }
            if ($discount->cmp($amount) > 0) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'line discount exceeds line amount');
            }
            if ($convention === TaxConvention::NoneDeclared && (! $rate->isZero() || $noTaxReason === null || trim($noTaxReason) === '')) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'none_declared tax requires zero rate and non-empty reason');
            }
            if ($convention !== TaxConvention::NoneDeclared && $noTaxReason !== null) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'noTaxReason is valid only with none_declared');
            }
            $parsedLines[] = ['input' => $line, 'amount' => $amount, 'discount' => $discount, 'rate' => $rate];
            $maxScale = max($maxScale, $rate->scale, $amount->scale, $discount->scale);
        }

        if ($convention === TaxConvention::Inclusive) {
            return $this->calculateInclusive($parsedLines, $currencyObj->code, $exp, $roundingMode, $order);
        }

        return $this->calculateExclusive($parsedLines, $convention, $currencyObj->code, $exp, $roundingMode, $order, $maxScale);
    }

    /**
     * @param  list<array{input: TaxArithmeticLineInput, amount: Dec, discount: Dec, rate: Dec}>  $parsedLines
     */
    private function calculateExclusive(
        array $parsedLines,
        TaxConvention $convention,
        string $currency,
        int $exp,
        string $roundingMode,
        DiscountTaxOrder $order,
        int $maxScale,
    ): TaxArithmeticResult {
        $netMinors = [];
        $exactTaxes = [];
        $taxScale = $maxScale;
        foreach ($parsedLines as $entry) {
            $ref = $entry['input']->lineRef;
            $afterDiscount = $entry['amount']->sub($entry['discount']);
            $taxBase = $order === DiscountTaxOrder::DiscountBeforeTax ? $afterDiscount : $entry['amount'];
            $netMinors[$ref] = (int) $afterDiscount->toMinor($exp, $roundingMode);
            $exactTax = $taxBase->mul($entry['rate']);
            $exactTaxes[$ref] = $exactTax;
            $taxScale = max($taxScale, $exactTax->scale);
        }

        $den = bcpow('10', (string) $taxScale, 0);
        $expPow = bcpow('10', (string) $exp, 0);
        /** @var array<string, numeric-string> $nums */
        $nums = [];
        $sumNum = '0';
        foreach ($exactTaxes as $ref => $tax) {
            $num = bcmul($tax->rescale($taxScale)->unscaled, $expPow, 0);
            $nums[$ref] = $num;
            $sumNum = bcadd($sumNum, $num, 0);
        }

        $targetTax = IntegerRounding::divide($sumNum, $den, $roundingMode);
        $allocatedTaxes = LargestRemainder::allocate($nums, $den, $targetTax);

        $results = [];
        $subtotalTotal = 0;
        $taxTotal = 0;
        $grandTotal = 0;
        foreach ($parsedLines as $entry) {
            $ref = $entry['input']->lineRef;
            $net = $netMinors[$ref];
            $tax = (int) $allocatedTaxes[$ref];
            $gross = $net + $tax;
            $floorTax = (int) bcdiv($nums[$ref], $den, 0);
            $residual = $tax - $floorTax;

            $results[] = new TaxArithmeticLineResult($ref, $net, $tax, $gross, $residual);
            $subtotalTotal += $net;
            $taxTotal += $tax;
            $grandTotal += $gross;
        }

        return new TaxArithmeticResult($convention, $currency, $exp, $roundingMode, $order, $subtotalTotal, $taxTotal, $grandTotal, $results);
    }

    /**
     * @param  list<array{input: TaxArithmeticLineInput, amount: Dec, discount: Dec, rate: Dec}>  $parsedLines
     */
    private function calculateInclusive(
        array $parsedLines,
        string $currency,
        int $exp,
        string $roundingMode,
        DiscountTaxOrder $order,
    ): TaxArithmeticResult {
        $grossMinors = [];
        $lineFractions = [];
        /** @var numeric-string $commonDen */
        $commonDen = '1';

        foreach ($parsedLines as $entry) {
            $ref = $entry['input']->lineRef;
            $payableGross = max(0, (int) $entry['amount']->sub($entry['discount'])->toMinor($exp, $roundingMode));
            $grossMinors[$ref] = $payableGross;

            /** @var numeric-string $power */
            $power = bcpow('10', (string) $entry['rate']->scale, 0);
            /** @var numeric-string $den */
            $den = bcadd($power, $entry['rate']->unscaled, 0);
            if ($order === DiscountTaxOrder::TaxBeforeDiscount) {
                $preDiscountGross = max(0, (int) $entry['amount']->toMinor($exp, $roundingMode));
                $num = bcmul((string) $preDiscountGross, $entry['rate']->unscaled, 0);
            } else {
                $num = bcmul((string) $payableGross, $power, 0);
            }
            $lineFractions[$ref] = ['num' => $num, 'den' => $den];
            $commonDen = self::lcm($commonDen, $den);
        }

        /** @var array<string, numeric-string> $scaledNums */
        $scaledNums = [];
        $sumNum = '0';
        foreach ($lineFractions as $ref => $frac) {
            $factor = bcdiv($commonDen, $frac['den'], 0);
            $scaled = bcmul($frac['num'], $factor, 0);
            $scaledNums[$ref] = $scaled;
            $sumNum = bcadd($sumNum, $scaled, 0);
        }

        $targetComponent = IntegerRounding::divide($sumNum, $commonDen, $roundingMode);
        $allocated = LargestRemainder::allocate($scaledNums, $commonDen, $targetComponent);

        $results = [];
        $subtotalTotal = 0;
        $taxTotal = 0;
        $grandTotal = 0;
        foreach ($parsedLines as $entry) {
            $ref = $entry['input']->lineRef;
            $gross = $grossMinors[$ref];
            $allocVal = (int) $allocated[$ref];
            $floorVal = (int) bcdiv($scaledNums[$ref], $commonDen, 0);
            if ($order === DiscountTaxOrder::TaxBeforeDiscount) {
                $tax = $allocVal;
                if ($tax > $gross) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'inclusive pre-discount tax exceeds payable line gross');
                }
                $net = $gross - $tax;
            } else {
                $net = $allocVal;
                $tax = $gross - $net;
            }
            $residual = $allocVal - $floorVal;

            $results[] = new TaxArithmeticLineResult($ref, $net, $tax, $gross, $residual);
            $subtotalTotal += $net;
            $taxTotal += $tax;
            $grandTotal += $gross;
        }

        return new TaxArithmeticResult(TaxConvention::Inclusive, $currency, $exp, $roundingMode, $order, $subtotalTotal, $taxTotal, $grandTotal, $results);
    }

    /**
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     * @return numeric-string
     */
    private static function gcd(string $a, string $b): string
    {
        while ($b !== '0') {
            $rem = bcmod($a, $b);
            $a = $b;
            $b = $rem;
        }

        return $a === '0' ? '1' : $a;
    }

    /**
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     * @return numeric-string
     */
    private static function lcm(string $a, string $b): string
    {
        return bcmul(bcdiv($a, self::gcd($a, $b), 0), $b, 0);
    }
}
