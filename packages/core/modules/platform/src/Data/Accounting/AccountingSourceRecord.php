<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Accounting;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Neutral authenticated public event or normalized actual-effect input for accounting fact mapping.
 *
 * Platform owns this boundary and intentionally carries only neutral public payload data, never module Internal types.
 */
#[Api(since: '1.0')]
final readonly class AccountingSourceRecord
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $schemaVersion,
        public string $eventType,
        public string $installationRef,
        public string $sellerRef,
        public string $environment,
        public string $sourceLineage,
        public string $aggregateRef,
        public int $aggregateRevision,
        public DateTimeImmutable $occurredAt,
        public array $payload,
        public ?string $deliveryAttemptRef = null,
        public ?string $snapshotDigest = null,
    ) {
        foreach ([
            'schemaVersion' => $schemaVersion,
            'eventType' => $eventType,
            'installationRef' => $installationRef,
            'sellerRef' => $sellerRef,
            'environment' => $environment,
            'sourceLineage' => $sourceLineage,
            'aggregateRef' => $aggregateRef,
        ] as $name => $value) {
            if (trim($value) === '' || strlen($value) > 200 || str_contains($value, "\0")) {
                throw new InvalidArgumentException("AccountingSourceRecord {$name} must be a bounded nonempty string");
            }
        }
        if ($aggregateRevision < 0) {
            throw new InvalidArgumentException('AccountingSourceRecord aggregateRevision must be nonnegative');
        }
        if ($deliveryAttemptRef !== null && (trim($deliveryAttemptRef) === '' || strlen($deliveryAttemptRef) > 200 || str_contains($deliveryAttemptRef, "\0"))) {
            throw new InvalidArgumentException('AccountingSourceRecord deliveryAttemptRef must be a bounded nonempty string when provided');
        }
        if ($snapshotDigest !== null && preg_match('/^sha256:[0-9a-f]{64}$/', $snapshotDigest) !== 1) {
            throw new InvalidArgumentException('AccountingSourceRecord snapshotDigest must be a valid sha256 digest when provided');
        }
    }
}
