<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Accounting;

use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Explicit transport delivery acknowledgment, tracked independently of accounting application receipts.
 */
#[Api(since: '1.0')]
final readonly class AccountingDeliveryAck
{
    public function __construct(
        public string $ackRef,
        public string $sourceFactRef,
        public string $deliveryAttemptRef,
        public string $consumerRef,
        public DateTimeImmutable $acknowledgedAt,
        public bool $duplicateDelivery = false,
    ) {
        foreach ([
            'ackRef' => $ackRef,
            'sourceFactRef' => $sourceFactRef,
            'deliveryAttemptRef' => $deliveryAttemptRef,
            'consumerRef' => $consumerRef,
        ] as $name => $value) {
            if (trim($value) === '' || strlen($value) > 256 || str_contains($value, "\0")) {
                throw new InvalidArgumentException("AccountingDeliveryAck {$name} must be a bounded nonempty string");
            }
        }
    }
}
