<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Corrections;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Internal\Storage\RatingRows;

final class DeltaCalculator
{
    /**
     * @param  array{net: string, tax: string, gross: string}  $lastEffective
     * @param  array{net: string, tax: string, gross: string}  $corrected
     * @return array{net: string, tax: string, gross: string, branch: string}
     */
    public static function delta(array $lastEffective, array $corrected): array
    {
        foreach ([$lastEffective, $corrected] as $amount) {
            foreach ($amount as $value) {
                if (preg_match('/^-?[0-9]+$/D', $value) !== 1) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.terms_invalid: non-integer amount');
                }
            }
            if (bccomp(bcadd(RatingRows::decimal($amount['net']), RatingRows::decimal($amount['tax']), 0), RatingRows::decimal($amount['gross']), 0) !== 0) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.terms_invalid: gross mismatch');
            }
        }
        $net = bcsub(RatingRows::decimal($corrected['net']), RatingRows::decimal($lastEffective['net']), 0);
        $tax = bcsub(RatingRows::decimal($corrected['tax']), RatingRows::decimal($lastEffective['tax']), 0);
        $gross = bcsub(RatingRows::decimal($corrected['gross']), RatingRows::decimal($lastEffective['gross']), 0);
        $n = bccomp($net, '0', 0);
        $t = bccomp($tax, '0', 0);
        $g = bccomp($gross, '0', 0);
        $branch = match (true) {
            $g > 0 && $n >= 0 && $t >= 0 => 'obligation',
            $g < 0 && $n <= 0 && $t <= 0 => 'credit_note',
            $g === 0 && $n === 0 && $t === 0 => 'none',
            default => throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.correction_mixed_sign'),
        };

        return ['net' => $net, 'tax' => $tax, 'gross' => $gross, 'branch' => $branch];
    }
}
