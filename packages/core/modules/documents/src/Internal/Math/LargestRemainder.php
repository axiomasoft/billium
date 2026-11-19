<?php

declare(strict_types=1);

namespace Billium\Documents\Internal\Math;

use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final class LargestRemainder
{
    /**
     * @param  array<string, numeric-string>  $nums
     * @return array<string, numeric-string>
     */
    public static function allocate(array $nums, string $den, string $target): array
    {
        if (! is_numeric($den) || ! is_numeric($target) || bccomp($den, '0', 0) <= 0 || bccomp($target, '0', 0) < 0) {
            throw new LogicException('Invalid allocation denominator or target');
        }
        $floors = $remainders = [];
        $sum = '0';
        $nonzero = 0;
        foreach ($nums as $key => $num) {
            if (bccomp($num, '0', 0) < 0) {
                throw new LogicException('Negative allocation numerator');
            }
            $floors[$key] = bcdiv($num, $den, 0);
            $remainders[$key] = bcmod($num, $den, 0);
            $sum = bcadd($sum, $floors[$key], 0);
            $nonzero += bccomp($remainders[$key], '0', 0) > 0 ? 1 : 0;
        }
        $residual = bcsub($target, $sum, 0);
        if (bccomp($residual, '0', 0) < 0 || bccomp($residual, (string) $nonzero, 0) > 0) {
            throw new LogicException('Allocation residual out of bounds');
        }
        $keys = array_keys($nums);
        usort($keys, static function (string $a, string $b) use ($remainders): int {
            $comparison = bccomp($remainders[$b], $remainders[$a], 0);

            return $comparison !== 0 ? $comparison : strcmp($a, $b);
        });
        for ($i = 0; bccomp((string) $i, $residual, 0) < 0; $i++) {
            $floors[$keys[$i]] = bcadd($floors[$keys[$i]], '1', 0);
        }

        return $floors;
    }
}
