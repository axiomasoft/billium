<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class NotificationTransportAck
{
    public function __construct(
        public string $messageId,
        public string $transportAckRef,
        public DateTimeImmutable $acknowledgedAt,
        public bool $transportDeliveryOnly = true,
    ) {}
}
