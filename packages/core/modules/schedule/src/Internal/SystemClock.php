<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal;

use Billium\Schedule\Contracts\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Packman\Attributes\Internal;

#[Internal]
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
