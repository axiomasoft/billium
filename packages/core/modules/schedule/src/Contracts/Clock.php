<?php

declare(strict_types=1);

namespace Billium\Schedule\Contracts;

use Billium\Contracts\Attributes\Api;
use DateTimeImmutable;

#[Api]
interface Clock
{
    public function now(): DateTimeImmutable;
}
