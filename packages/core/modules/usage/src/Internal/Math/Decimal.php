<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Math;

use Billium\Usage\Internal\Errors\UsageProblems;

final class Decimal
{
    public static function unscaled(string $value, int $scale): string
    {
        if ($scale < 0 || $scale > 12 || preg_match('/^-?(0|[1-9][0-9]*)(?:\\.([0-9]+))?$/D', $value, $parts) !== 1) {
            throw UsageProblems::error('quantity_invalid');
        }
        $fraction = $parts[2] ?? '';
        if (strlen($fraction) > $scale && trim(substr($fraction, $scale), '0') !== '') {
            throw UsageProblems::error('quantity_scale_invalid');
        }
        $negative = str_starts_with($value, '-');
        $whole = $negative ? substr($parts[1], 0) : $parts[1];
        $integer = ltrim($whole.str_pad(substr($fraction, 0, $scale), $scale, '0'), '0');
        $integer = $integer === '' ? '0' : $integer;

        return $negative && $integer !== '0' ? '-'.$integer : $integer;
    }

    public static function format(string $unscaled, int $scale): string
    {
        if (preg_match('/^-?(0|[1-9][0-9]*)$/D', $unscaled) !== 1 || $scale < 0 || $scale > 12) {
            throw UsageProblems::error('quantity_invalid');
        }
        $negative = str_starts_with($unscaled, '-');
        $absolute = $negative ? substr($unscaled, 1) : $unscaled;
        $digits = str_pad($absolute, $scale + 1, '0', STR_PAD_LEFT);
        $value = $scale === 0 ? $digits : substr($digits, 0, -$scale).'.'.substr($digits, -$scale);

        return $negative && trim($absolute, '0') !== '' ? '-'.$value : $value;
    }
}
