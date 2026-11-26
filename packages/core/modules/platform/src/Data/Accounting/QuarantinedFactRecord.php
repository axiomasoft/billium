<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Accounting;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Durable quarantine entry preserving an unsupported/unknown schema source record for post-upgrade replay.
 */
#[Api(since: '1.0')]
final readonly class QuarantinedFactRecord
{
    public function __construct(
        public string $quarantineRef,
        public string $schemaVersion,
        public string $eventType,
        public string $reason,
        public AccountingSourceRecord $sourceRecord,
        public DateTimeImmutable $quarantinedAt,
    ) {
        foreach ([
            'quarantineRef' => $quarantineRef,
            'schemaVersion' => $schemaVersion,
            'eventType' => $eventType,
            'reason' => $reason,
        ] as $name => $value) {
            if (trim($value) === '' || strlen($value) > 256 || str_contains($value, "\0")) {
                throw new InvalidArgumentException("QuarantinedFactRecord {$name} must be a bounded nonempty string");
            }
        }
    }
}
