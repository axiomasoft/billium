<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Outbox;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class OutboxDeliveryStatus
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public string $aggregateRef,
        public int $aggregateRevision,
        public string $destinationRef,
        public string $status,
        public DateTimeImmutable $availableAt,
        public ?DateTimeImmutable $claimUntil,
        public int $attempts,
        public int $consecutiveFailures,
        public ?string $lastErrorClass,
        public ?string $parkReason,
        public ?DateTimeImmutable $deliveredAt,
    ) {
        if ($eventId === '' || $eventType === '' || $aggregateRef === '' || $aggregateRevision < 0 || $destinationRef === ''
            || ! in_array($status, ['pending', 'in_flight', 'parked', 'delivered'], true) || $attempts < 0 || $consecutiveFailures < 0) {
            throw new InvalidArgumentException('Invalid durable outbox delivery status');
        }
    }
}
