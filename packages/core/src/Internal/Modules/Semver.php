<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Modules;

/** MAJOR.MINOR.PATCH only: module and core API versions are not package SemVer and carry no pre-release. */
final class Semver
{
    public static function isValid(string $version): bool
    {
        return preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/', $version) === 1;
    }

    public static function compare(string $left, string $right): int
    {
        return array_map(intval(...), explode('.', $left)) <=> array_map(intval(...), explode('.', $right));
    }
}
