<?php

declare(strict_types=1);

namespace Billium\Contracts\Internal\Money;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;

/**
 * A validated decimal string of money.round split into its digits and fraction length.
 */
final readonly class RoundInput
{
    /**
     * @param  string  $unscaled  the signed digits without the decimal point
     */
    private function __construct(public string $unscaled, public int $fraction) {}

    /**
     * @throws ProblemException billium.validation.invalid for any form but -?(0|[1-9][0-9]*)(\.[0-9]+)?
     */
    public static function parse(string $value): self
    {
        if (preg_match('/^(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $value, $match) !== 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, "'{$value}' is not a decimal string");
        }
        $fraction = $match[3] ?? '';
        $digits = ltrim($match[2].$fraction, '0');
        $unscaled = $digits === '' ? '0' : $match[1].$digits;

        return new self($unscaled, strlen($fraction));
    }
}
