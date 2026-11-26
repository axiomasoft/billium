<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use DateTimeImmutable;
use Packman\Attributes\Api;

/**
 * Durable platform-owned mapping from external source identity to Billium economic fact lineage.
 */
#[Api(since: '1.0')]
final readonly class ImportMappingRecord
{
    public function __construct(
        public int $id,
        public string $mappingRef,
        public ImportSourceIdentity $identity,
        public int $sourceRecordRevision,
        public string $sourceIdentityDigest,
        public string $payloadDigest,
        public string $sourceFactRef,
        public ImportMappingStatus $status,
        public ?string $billiumTargetKind = null,
        public ?string $billiumTargetRef = null,
        public ?string $originalIssuer = null,
        public ?string $originalSourceNumber = null,
        public ?string $importPolicyVersion = null,
        public ?string $tombstoneReason = null,
        public ?DateTimeImmutable $tombstonedAt = null,
        public ?int $supersededByRevision = null,
    ) {}
}
