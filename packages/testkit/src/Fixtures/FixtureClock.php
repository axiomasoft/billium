<?php

declare(strict_types=1);

namespace Billium\Testkit\Fixtures;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final class FixtureClock
{
    public function __construct(public DateTimeImmutable $now) {}

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }
}
