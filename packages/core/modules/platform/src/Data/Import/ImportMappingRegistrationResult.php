<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Packman\Attributes\Api;

/**
 * Outcome of registering a source identity mapping revision in the platform store.
 */
#[Api(since: '1.0')]
final readonly class ImportMappingRegistrationResult
{
    public const string CREATED = 'created';

    public const string DUPLICATE = 'duplicate';

    public const string CONFLICT = 'conflict';

    public const string TOMBSTONED_CONFLICT = 'tombstoned_conflict';

    public function __construct(
        public string $outcome,
        public ImportMappingRecord $mapping,
        public ?string $conflictReason = null,
    ) {}
}
