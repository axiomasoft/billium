<?php

declare(strict_types=1);

namespace Billium\Contracts\Contracts;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\RoundingMode;
use Billium\Contracts\Exceptions\ProblemException;
use Packman\Attributes\Api;

/**
 * Money arithmetic without floats: integer minor units, big-integer intermediates, int64 results (BC-MW-09, BC-MW-10).
 */
#[Api(since: '1.0')]
interface MoneyMath
{
    /**
     * @throws ProblemException billium.money.currency_mismatch, or billium.validation.invalid beyond int64
     */
    public function add(Money $a, Money $b): Money;

    /**
     * Largest-remainder allocation: the parts sum to the total, in the order of the ratios (BC-MW-15, BC-MW-16).
     *
     * @param  array<mixed>  $ratios  non-negative integer strings, at least one positive
     * @return list<Money>
     *
     * @throws ProblemException billium.validation.invalid
     */
    public function allocate(Money $total, array $ratios): array;

    /**
     * Rounds a decimal string with any number of fraction digits to the scale of the currency (BC-MW-17).
     *
     * @throws ProblemException billium.validation.invalid for a malformed value or a result beyond int64
     */
    public function round(string $value, Currency $currency, RoundingMode $mode): Money;
}
