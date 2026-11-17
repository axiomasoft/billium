<?php

declare(strict_types=1);

namespace Billium\Contracts\Contracts;

use Billium\Contracts\Internal\Money\MoneyMathBrick;
use Packman\Attributes\Api;

/**
 * The package's MoneyMath implementation.
 */
#[Api(since: '1.0')]
final class MoneyMathFactory
{
    public static function create(): MoneyMath
    {
        return new MoneyMathBrick;
    }
}
