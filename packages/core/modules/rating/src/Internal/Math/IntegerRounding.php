<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Math;

use InvalidArgumentException;

final class IntegerRounding
{
    /** @return numeric-string */
    public static function divide(string $num, string $den, string $mode): string
    {
        if (! is_numeric($num) || ! is_numeric($den) || preg_match('/^-?[0-9]+$/D', $num) !== 1 || preg_match('/^[0-9]+$/D', $den) !== 1 || bccomp($den, '0', 0) <= 0) {
            throw new InvalidArgumentException('Integer numerator and positive denominator required');
        }
        $negative = bccomp($num, '0', 0) < 0;
        $absolute = $negative ? bcsub('0', $num, 0) : $num;
        $quotient = bcdiv($absolute, $den, 0);
        $remainder = bcmod($absolute, $den, 0);
        $twice = bccomp(bcmul($remainder, '2', 0), $den, 0);
        $up = match ($mode) {
            'down' => false,
            'up' => bccomp($remainder, '0', 0) > 0,
            'half_up' => $twice >= 0,
            'half_even' => $twice > 0 || ($twice === 0 && bcmod($quotient, '2', 0) === '1'),
            default => throw new InvalidArgumentException('Unknown rounding mode'),
        };
        if ($up) {
            $quotient = bcadd($quotient, '1', 0);
        }

        return $negative && $quotient !== '0' ? bcsub('0', $quotient, 0) : $quotient;
    }
}
