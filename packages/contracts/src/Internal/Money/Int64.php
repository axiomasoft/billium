<?php

declare(strict_types=1);

namespace Billium\Contracts\Internal\Money;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;

/**
 * The int64 bound of minor units (BC-MW-09): an overflow is an error, never a wrap.
 */
final class Int64
{
    /**
     * @param  string  $integer  a decimal integer string
     *
     * @throws ProblemException billium.validation.invalid outside int64
     */
    public static function fromString(string $integer): int
    {
        $negative = str_starts_with($integer, '-');
        $digits = ltrim($negative ? substr($integer, 1) : $integer, '0');
        $limit = $negative ? '9223372036854775808' : '9223372036854775807';
        if (strlen($digits) > 19 || strlen($digits) === 19 && strcmp($digits, $limit) > 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'the result is outside the int64 range of minor units');
        }

        return $digits === '' ? 0 : (int) $integer;
    }
}
