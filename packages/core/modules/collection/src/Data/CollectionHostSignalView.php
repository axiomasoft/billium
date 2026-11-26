<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\CollectionSignalKind;
use DateTimeImmutable;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class CollectionHostSignalView
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $id,
        public string $sellerRef,
        public string $environment,
        public string $signalRef,
        public int $dunningCaseId,
        public string $caseRef,
        public string $billingAccountRef,
        public string $obligationRef,
        public ?string $componentRef,
        public CollectionSignalKind $signalKind,
        public string $signalDedupeKey,
        public int $caseRevision,
        public string $policyRef,
        public int $policyVersion,
        public bool $signalOnly,
        public array $payload,
        public string $signalDigest,
        public DateTimeImmutable $effectiveAt,
        public DateTimeImmutable $recordedAt,
    ) {}
}
