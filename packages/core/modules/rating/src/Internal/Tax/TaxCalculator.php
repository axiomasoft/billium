<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Tax;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Data\Pricing\CommitmentTerms;
use Billium\Rating\Data\Tax\TaxSnapshot;
use Billium\Rating\Internal\Math\Dec;
use Billium\Rating\Internal\Math\IntegerRounding;
use Billium\Rating\Internal\Math\LargestRemainder;
use Billium\Rating\Internal\Proration\ProrationCalculator;
use Billium\Rating\Internal\Storage\RatingRows;
use InvalidArgumentException;
use Packman\Attributes\Internal;

#[Internal]
final class TaxCalculator
{
    /**
     * @param  array<string, string>  $amounts
     * @param  array{elapsed: string, total: string}|null  $proration
     * @return array<string, array{net:string, tax:string, gross:string, residual:string}>
     */
    public function calculate(array $amounts, string $mode, string $rate, int $exp, string $rounding, ?string $noTaxReason = null, ?CommitmentTerms $commitment = null, ?array $proration = null): array
    {
        try {
            $r = Dec::parse($rate);
        } catch (InvalidArgumentException) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.terms_invalid: invalid tax rate');
        }
        if (! in_array($mode, ['exclusive', 'inclusive', 'none_declared'], true) || $r->sign() < 0 || $r->cmp(Dec::parse('10')) >= 0 || $r->scale > 8) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.terms_invalid');
        }
        if ($mode === 'none_declared' && (! $r->isZero() || $noTaxReason === null || $noTaxReason === '')) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.terms_invalid');
        }
        if ($mode !== 'none_declared' && $noTaxReason !== null) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.terms_invalid');
        }
        if ($mode === 'inclusive' && $commitment !== null) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.unsupported_combination: commitment and inclusive tax');
        }
        $sign = 0;
        foreach ($amounts as $amount) {
            $current = Dec::parse($amount)->sign();
            if ($sign !== 0 && $current !== 0 && $current !== $sign) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.mixed_sign_allocation');
            }
            $sign = $current === 0 ? $sign : $current;
        }
        if ($mode === 'inclusive') {
            return $this->inclusive($amounts, $r, $exp, $rounding, $sign < 0, $proration);
        }

        return $this->exclusive($amounts, $r, $exp, $rounding, $sign < 0, $proration);
    }

    /** @param array<string, string> $amounts
     * @param  array{elapsed: string, total: string}|null  $proration
     * @return array<string, array{net:string, tax:string, gross:string, residual:string}>
     */
    public function calculateSnapshot(array $amounts, TaxSnapshot $snapshot, int $exp, string $rounding, ?CommitmentTerms $commitment = null, ?array $proration = null): array
    {
        if ($snapshot->snapshotRef === '' || $snapshot->jurisdiction === '' || $snapshot->taxCode === '' || $snapshot->resolver === '' || $snapshot->resolverVersion === '' || $snapshot->inputDigest === '' || $snapshot->evidenceDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.terms_invalid: incomplete tax evidence');
        }

        return $this->calculate(amounts: $amounts, mode: $snapshot->mode->value, rate: $snapshot->rate, exp: $exp, rounding: $rounding, noTaxReason: $snapshot->noTaxReason, commitment: $commitment, proration: $proration);
    }

    /**
     * @param  array<string, string>  $amounts
     * @param  array{elapsed: string, total: string}|null  $proration
     * @return array<string, array{net:string, tax:string, gross:string, residual:string}>
     */
    private function exclusive(array $amounts, Dec $rate, int $exp, string $mode, bool $negative, ?array $proration): array
    {
        $net = $taxes = [];
        $scale = 0;
        foreach ($amounts as $key => $amount) {
            $value = Dec::parse($amount);
            $net[$key] = $this->minor(value: $value, exp: $exp, mode: $mode, proration: $proration);
            $taxes[$key] = $value->mul($rate);
            $scale = max($scale, $taxes[$key]->scale);
        }
        $elapsed = RatingRows::decimal($proration['elapsed'] ?? '1');
        $den = bcmul(bcpow('10', (string) $scale, 0), RatingRows::decimal($proration['total'] ?? '1'), 0);
        $nums = [];
        $sumNum = '0';
        foreach ($taxes as $key => $tax) {
            // Keep the fraction rational through group rounding and largest-remainder allocation.
            $num = bcmul(bcmul($tax->rescale($scale)->unscaled, $elapsed, 0), bcpow('10', (string) $exp, 0), 0);
            $nums[$key] = ltrim($num, '-');
            $sumNum = bcadd($sumNum, $num, 0);
        }
        $target = IntegerRounding::divide(num: $sumNum, den: $den, mode: $mode);
        $allocation = LargestRemainder::allocate(nums: $nums, den: $den, target: ltrim($target, '-'));
        $result = [];
        foreach ($net as $key => $value) {
            $tax = $negative ? bcsub('0', $allocation[$key], 0) : $allocation[$key];
            $result[$key] = ['net' => $value, 'tax' => $tax, 'gross' => bcadd(RatingRows::decimal($value), $tax, 0), 'residual' => bcsub($allocation[$key], bcdiv($nums[$key], $den, 0), 0)];
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $amounts
     * @param  array{elapsed: string, total: string}|null  $proration
     * @return array<string, array{net:string, tax:string, gross:string, residual:string}>
     */
    private function inclusive(array $amounts, Dec $rate, int $exp, string $mode, bool $negative, ?array $proration): array
    {
        $gross = [];
        $total = '0';
        foreach ($amounts as $key => $amount) {
            $gross[$key] = $this->minor(value: Dec::parse($amount), exp: $exp, mode: $mode, proration: $proration);
            $total = bcadd($total, RatingRows::decimal($gross[$key]), 0);
        }
        $power = bcpow('10', (string) $rate->scale, 0);
        $den = bcadd($power, $rate->unscaled, 0);
        $target = IntegerRounding::divide(num: bcmul($total, $power, 0), den: $den, mode: $mode);
        $nums = [];
        foreach ($gross as $key => $value) {
            $nums[$key] = bcmul(RatingRows::decimal(ltrim($value, '-')), $power, 0);
        }
        $allocation = LargestRemainder::allocate(nums: $nums, den: $den, target: ltrim($target, '-'));
        $result = [];
        foreach ($gross as $key => $value) {
            $net = $negative ? bcsub('0', $allocation[$key], 0) : $allocation[$key];
            $result[$key] = ['net' => $net, 'tax' => bcsub(RatingRows::decimal($value), $net, 0), 'gross' => $value, 'residual' => bcsub($allocation[$key], bcdiv($nums[$key], $den, 0), 0)];
        }

        return $result;
    }

    /** @param array{elapsed: string, total: string}|null $proration */
    private function minor(Dec $value, int $exp, string $mode, ?array $proration): string
    {
        return $proration === null ? $value->toMinor(exponent: $exp, mode: $mode)
            : (new ProrationCalculator)->prorateMinor(fullMajor: $value, exponent: $exp,
                elapsed: $proration['elapsed'], total: $proration['total'], roundingMode: $mode);
    }
}
