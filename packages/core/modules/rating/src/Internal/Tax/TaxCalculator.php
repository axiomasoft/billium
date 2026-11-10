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
use InvalidArgumentException;

final class TaxCalculator
{
    /**
     * @param  array<string, string>  $amounts
     * @return array<string, array{net:string, tax:string, gross:string, residual:string}>
     */
    public function calculate(array $amounts, string $mode, string $rate, int $exp, string $rounding, ?string $noTaxReason = null, ?CommitmentTerms $commitment = null): array
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
            return $this->inclusive($amounts, $r, $exp, $rounding, $sign < 0);
        }

        return $this->exclusive($amounts, $r, $exp, $rounding, $sign < 0);
    }

    /** @param array<string, string> $amounts
     * @return array<string, array{net:string, tax:string, gross:string, residual:string}>
     */
    public function calculateSnapshot(array $amounts, TaxSnapshot $snapshot, int $exp, string $rounding, ?CommitmentTerms $commitment = null): array
    {
        if ($snapshot->snapshotRef === '' || $snapshot->jurisdiction === '' || $snapshot->taxCode === '' || $snapshot->resolver === '' || $snapshot->resolverVersion === '' || $snapshot->inputDigest === '' || $snapshot->evidenceDigest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.terms_invalid: incomplete tax evidence');
        }

        return $this->calculate(amounts: $amounts, mode: $snapshot->mode->value, rate: $snapshot->rate, exp: $exp, rounding: $rounding, noTaxReason: $snapshot->noTaxReason, commitment: $commitment);
    }

    /**
     * @param  array<string, string>  $amounts
     * @return array<string, array{net:string, tax:string, gross:string, residual:string}>
     */
    private function exclusive(array $amounts, Dec $rate, int $exp, string $mode, bool $negative): array
    {
        $net = $taxes = [];
        $sum = Dec::parse('0');
        $scale = 0;
        foreach ($amounts as $key => $amount) {
            $value = Dec::parse($amount);
            $net[$key] = $value->toMinor(exponent: $exp, mode: $mode);
            $taxes[$key] = $value->mul($rate);
            $sum = $sum->add($taxes[$key]);
            $scale = max($scale, $taxes[$key]->scale);
        }
        $den = bcpow('10', (string) $scale, 0);
        $nums = [];
        foreach ($taxes as $key => $tax) {
            $nums[$key] = bcmul(ltrim($tax->rescale($scale)->unscaled, '-'), bcpow('10', (string) $exp, 0), 0);
        }
        $allocation = LargestRemainder::allocate(nums: $nums, den: $den, target: ltrim($sum->toMinor(exponent: $exp, mode: $mode), '-'));
        $result = [];
        foreach ($net as $key => $value) {
            $tax = $negative ? bcsub('0', $allocation[$key], 0) : $allocation[$key];
            $result[$key] = ['net' => $value, 'tax' => $tax, 'gross' => bcadd($value, $tax, 0), 'residual' => bcsub($allocation[$key], bcdiv($nums[$key], $den, 0), 0)];
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $amounts
     * @return array<string, array{net:string, tax:string, gross:string, residual:string}>
     */
    private function inclusive(array $amounts, Dec $rate, int $exp, string $mode, bool $negative): array
    {
        $gross = [];
        $total = '0';
        foreach ($amounts as $key => $amount) {
            $gross[$key] = Dec::parse($amount)->toMinor(exponent: $exp, mode: $mode);
            $total = bcadd($total, $gross[$key], 0);
        }
        $power = bcpow('10', (string) $rate->scale, 0);
        $den = bcadd($power, $rate->unscaled, 0);
        $target = IntegerRounding::divide(num: bcmul($total, $power, 0), den: $den, mode: $mode);
        $nums = [];
        foreach ($gross as $key => $value) {
            $nums[$key] = bcmul(ltrim($value, '-'), $power, 0);
        }
        $allocation = LargestRemainder::allocate(nums: $nums, den: $den, target: ltrim($target, '-'));
        $result = [];
        foreach ($gross as $key => $value) {
            $net = $negative ? bcsub('0', $allocation[$key], 0) : $allocation[$key];
            $result[$key] = ['net' => $net, 'tax' => bcsub($value, $net, 0), 'gross' => $value, 'residual' => bcsub($allocation[$key], bcdiv($nums[$key], $den, 0), 0)];
        }

        return $result;
    }
}
