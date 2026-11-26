<?php

declare(strict_types=1);

namespace Billium\Collection\Data;

use Billium\Collection\Enums\TombstoneKind;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class RevokeConsentCommand
{
    public function __construct(
        public string $commandKey,
        public CollectionScope $scope,
        public string $consentRef,
        public string $tombstoneRef,
        public int $sourceRevision,
        public int $cancellationGeneration,
        public string $reason,
        public string $evidenceDigest,
        public TombstoneKind $tombstoneKind = TombstoneKind::ConsentRevoked,
        public ?string $targetAuthorizationRef = null,
        public ?string $targetBusinessIntentRef = null,
        public ?int $expectedGuardRevision = null,
    ) {}
}
