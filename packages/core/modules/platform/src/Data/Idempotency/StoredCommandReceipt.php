<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Idempotency;

use Billium\Core\Data\Runner\StoredOutcome;
use DateTimeImmutable;
use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * The optional command input fingerprint is separate from the qualified key, claim digest and economic identity.
 * Execution supplies its original root operation reference as an opaque binding locator. Null represents unavailable provenance.
 */
#[Api(since: '1.0')]
final readonly class StoredCommandReceipt
{
    public function __construct(
        public StoredOutcome $outcome,
        public string $commandSchema,
        public string $commandKeyDigest,
        public string $semanticDigest,
        public DateTimeImmutable $firstSeenAt,
        public DateTimeImmutable $completedAt,
        public ?string $inputDigest = null,
        public string $canonicalizationVersion = 'jcs-rfc8785+sha256',
        public ?DateTimeImmutable $expiresAt = null,
        public ?string $originalExecutionBindingRef = null,
    ) {
        if ($commandSchema === '' || $commandKeyDigest === '' || $semanticDigest === '' || $outcome->receiptId === null) {
            throw new InvalidArgumentException('A stored command receipt has its original claim identity');
        }
    }
}
