<?php

declare(strict_types=1);

namespace Billium\Testkit\Fixtures;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final class FixtureValue
{
    public static function same(object $a, object $b): bool
    {
        return serialize($a) === serialize($b);
    }
}
