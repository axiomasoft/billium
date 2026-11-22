<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use InvalidArgumentException;
use Packman\Attributes\Api;

/**
 * Explicit capability scope, frozen manifest digest and chunking controls for import batch application.
 */
#[Api(since: '1.0')]
final readonly class ImportApplyOptions
{
    public const string REQUIRED_SCOPE = 'import:apply';

    public const string DEFAULT_MIGRATION_SOURCE_AUTHORITY = 'standalone-billium';

    public function __construct(
        public string $capabilityScope,
        public string $expectedManifestDigest,
        public ?string $approvalRef = null,
        public ?string $authorityFenceRef = null,
        public string $migrationSourceAuthority = self::DEFAULT_MIGRATION_SOURCE_AUTHORITY,
        public int $chunkSize = 50,
        public ?int $maxSteps = null,
        public bool $reduceObservations = true,
        public bool $adoptReceipts = true,
    ) {
        if ($chunkSize < 1 || $chunkSize > 10_000) {
            throw new InvalidArgumentException('ImportApplyOptions chunkSize must be between 1 and 10000');
        }
        if ($maxSteps !== null && $maxSteps < 0) {
            throw new InvalidArgumentException('ImportApplyOptions maxSteps must be non-negative when provided');
        }
        if (trim($migrationSourceAuthority) === '' || strlen($migrationSourceAuthority) > 200 || str_contains($migrationSourceAuthority, "\0")) {
            throw new InvalidArgumentException('ImportApplyOptions migrationSourceAuthority must be a bounded nonempty string');
        }
    }
}
