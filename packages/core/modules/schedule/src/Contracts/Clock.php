<?php

declare(strict_types=1);

namespace Billium\Schedule\Contracts;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api]
interface Clock
{
    public function now(): DateTimeImmutable;
}
