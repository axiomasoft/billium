<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Outbox;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Immutable outbox event content (..088): its own id, versioned type, aggregate reference and economic
 * revision, and a bounded JSON object payload without secrets. Seller/environment come from the root context.
 */
#[Api(since: '1.0')]
final readonly class OutboxEvent
{
    public const int MAX_PAYLOAD_BYTES = 65_536;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $eventId,
        public string $type,
        public string $schemaVersion,
        public string $aggregateRef,
        public int $aggregateRevision,
        public DateTimeImmutable $occurredAt,
        public array $payload,
    ) {
        foreach (['eventId' => $eventId, 'aggregateRef' => $aggregateRef] as $name => $value) {
            if ($value === '' || strlen($value) > 200) {
                throw new InvalidArgumentException("Outbox {$name} is a nonempty bounded string");
            }
        }
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $type) !== 1) {
            throw new InvalidArgumentException("Invalid outbox event type {$type}");
        }
        if (preg_match('/^[a-z][a-z0-9_.-]*\.v[0-9]+$/D', $schemaVersion) !== 1) {
            throw new InvalidArgumentException("Invalid outbox schema version {$schemaVersion}");
        }
        if ($aggregateRevision < 0) {
            throw new InvalidArgumentException('Aggregate revision is nonnegative');
        }
    }
}
