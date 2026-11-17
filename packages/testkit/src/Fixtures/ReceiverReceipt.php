<?php

declare(strict_types=1);

namespace Billium\Testkit\Fixtures;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ReceiverReceipt
{
    public function __construct(public string $eventRef, public string $businessRef, public int $revision, public string $digest, public int $captured, public int $refunded, public int $pid) {}
}
