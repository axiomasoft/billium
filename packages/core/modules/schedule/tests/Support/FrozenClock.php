<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Support;

use Billium\Schedule\Contracts\Clock;
use DateTimeImmutable;

final class FrozenClock implements Clock
{
    public function __construct(public DateTimeImmutable $instant = new DateTimeImmutable('2024-01-01T00:00:00Z')) {}

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }
}
