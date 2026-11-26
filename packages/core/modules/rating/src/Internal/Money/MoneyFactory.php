<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Money;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Internal;

#[Internal]
final class MoneyFactory
{
    public static function fromMinor(string $minor, string $currency, int $exponent): Money
    {
        $unit = Currency::of($currency);
        if ($unit->scale !== $exponent || ! is_numeric($minor) || preg_match('/^-?(0|[1-9][0-9]*)$/D', $minor) !== 1 || $minor === '-0' || bccomp($minor, '-9223372036854775808', 0) < 0 || bccomp($minor, '9223372036854775807', 0) > 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.rating.terms_invalid: invalid money');
        }

        return new Money(minor: (int) $minor, currency: $unit);
    }
}
