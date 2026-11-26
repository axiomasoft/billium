<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\ConsentGuardStatus;
use Billium\Collection\Enums\TombstoneKind;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Durable revocation/cancellation receipt defining when local revocation became effective.
 */
#[Api(since: '1.0')]
final readonly class RevocationReceipt
{
    public function __construct(
        public string $tombstoneRef,
        public int $consentGuardId,
        public string $consentRef,
        public int $consentVersion,
        public TombstoneKind $tombstoneKind,
        public ConsentGuardStatus $status,
        public int $guardRevision,
        public int $sourceRevision,
        public int $cancellationGeneration,
        public ?string $targetAuthorizationRef,
        public ?string $targetBusinessIntentRef,
        public string $reason,
        public string $evidenceDigest,
        public DateTimeImmutable $effectiveAt,
        public DateTimeImmutable $recordedAt,
    ) {}
}
