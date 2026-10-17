<?php

declare(strict_types=1);

namespace Billium\Contracts\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Internal\Money\MoneyMathBrick;

/**
 * The package's MoneyMath implementation.
 */
#[Api]
final class MoneyMathFactory
{
    public static function create(): MoneyMath
    {
        return new MoneyMathBrick;
    }
}
