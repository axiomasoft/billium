<?php

declare(strict_types=1);

namespace Billium\Core\Internal\Modules;

use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Plugins\Data\Plugin\PluginManifest;

/** MAJOR.MINOR.PATCH only: delegates SemVer parsing to Packman PluginManifest without pre-release/build tags. */
#[Internal]
final class Semver
{
    public static function isValid(string $version): bool
    {
        if ($version === '' || str_contains($version, '-') || str_contains($version, '+')) {
            return false;
        }

        try {
            new PluginManifest(id: 'billium.semver', version: $version);

            return true;
        } catch (InvalidConfiguration) {
            return false;
        }
    }

    public static function compare(string $left, string $right): int
    {
        return version_compare($left, $right);
    }
}
