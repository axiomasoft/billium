<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\ConsentGuardStatus;
use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Current durable mutable consent/cancellation guard head (locked at LockRank::DunningCase).
 */
#[Api(since: '1.0')]
final readonly class ConsentGuardStateView
{
    public function __construct(
        public int $id,
        public string $sellerRef,
        public string $environment,
        public string $billingAccountRef,
        public string $consentRef,
        public int $activeBindingId,
        public int $activeConsentVersion,
        public int $guardRevision,
        public int $sourceRevision,
        public int $cancellationGeneration,
        public ConsentGuardStatus $status,
        public ?DateTimeImmutable $revokedAt,
        public ?string $revocationReceiptRef,
        public ?string $revocationReason,
        public ?DateTimeImmutable $effectiveAt,
        public DateTimeImmutable $updatedAt,
    ) {}
}
