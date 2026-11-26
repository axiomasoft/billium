<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Time;

use DateTimeImmutable;
use LogicException;
use Packman\Attributes\Internal;

/** Frozen rating inputs carry period instants as integer Unix microseconds so digests never depend on a timezone. */
#[Internal]
final class Micros
{
    public static function of(DateTimeImmutable $instant): int
    {
        return $instant->getTimestamp() * 1000000 + (int) $instant->format('u');
    }

    public static function instant(int $micros): DateTimeImmutable
    {
        $seconds = intdiv($micros, 1000000);
        $fraction = str_pad((string) ($micros % 1000000), 6, '0', STR_PAD_LEFT);
        $instant = DateTimeImmutable::createFromFormat('U.u', $seconds.'.'.$fraction);

        return $instant !== false ? $instant : throw new LogicException('Invalid commercial period instant');
    }
}
