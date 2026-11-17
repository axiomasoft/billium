<?php

declare(strict_types=1);

namespace Billium\Testkit\Drivers;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ProviderSettlement
{
    public function __construct(public string $identity, public string $status, public DateTimeImmutable $at, public int $pid) {}
}
