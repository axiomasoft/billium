<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Storage;

use Packman\Attributes\Internal;

#[Internal]
final class IntegerRoundingAdapter
{
    /** Time conversion only; quantities are handled by Internal\\Math. */
    public static function seconds(int $micros): string
    {
        $seconds = bcdiv((string) $micros, '1000000', 0);
        if ($micros < 0 && bcmod((string) $micros, '1000000', 0) !== '0') {
            return bcsub($seconds, '1', 0);
        }

        return $seconds;
    }
}
