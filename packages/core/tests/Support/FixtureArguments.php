<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Support;

/** Keeps deliberate fixture overrides dynamic; the real constructor still checks every argument. */
final class FixtureArguments
{
    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  array<int|string, mixed>  $arguments
     * @return T
     */
    public static function construct(string $class, array $arguments): object
    {
        return new $class(...$arguments);
    }
}
