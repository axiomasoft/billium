<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Support;

use PHPUnit\Framework\Assert;

final class FixtureValues
{
    public static function text(mixed $value): string
    {
        Assert::assertIsString($value);

        return $value;
    }

    /** @template T of object
     * @param  class-string<T>  $class
     * @return T
     */
    public static function instance(mixed $value, string $class): object
    {
        Assert::assertInstanceOf($class, $value);

        return $value;
    }
}
