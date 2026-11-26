<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Host-supplied current consent/cancellation authority snapshot resolved strictly outside DB transactions.
 */
#[Api(since: '1.0')]
final readonly class HostConsentAuthoritySnapshot
{
    public function __construct(
        public string $sellerRef,
        public string $environment,
        public string $billingAccountRef,
        public string $consentRef,
        public int $consentVersion,
        public int $sourceRevision,
        public int $cancellationGeneration,
        public bool $active,
        public ?string $reason,
        public string $authorityDigest,
        public DateTimeImmutable $evaluatedAt,
    ) {}
}
