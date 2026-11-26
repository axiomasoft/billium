<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Migration;

use Packman\Attributes\Internal;

#[Internal]
final class MigrationDigest
{
    public static function valid(string $digest): bool
    {
        return preg_match('/^sha256:[0-9a-f]{64}$/D', $digest) === 1;
    }
}
