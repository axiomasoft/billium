<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Billium\Documents\Enums\DocumentType;
use Packman\Attributes\Api;

/** Immutable registered legal/tax profile version for a seller. */
#[Api(since: '1.0')]
final readonly class DocumentProfile
{
    /**
     * @param  list<DocumentType>  $supportedTypes
     * @param  array<string, mixed>  $numberingPolicy
     * @param  array<string, mixed>  $correctionPolicy
     * @param  list<string>  $requiredFields
     * @param  array<string, mixed>  $sellerSnapshot
     */
    public function __construct(
        public int $id,
        public string $sellerRef,
        public string $environment,
        public string $profileRef,
        public int $version,
        public string $jurisdictionRef,
        public array $supportedTypes,
        public array $numberingPolicy,
        public string $taxSnapshotSchema,
        public array $correctionPolicy,
        public array $requiredFields,
        public string $retentionPolicyRef,
        public array $sellerSnapshot,
        public bool $isComplete,
        public string $profileDigest,
    ) {}
}
