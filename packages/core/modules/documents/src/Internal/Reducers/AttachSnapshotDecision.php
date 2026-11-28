<?php

declare(strict_types=1);

namespace Billium\Documents\Internal\Reducers;

use Packman\Attributes\Internal;

#[Internal]
final readonly class AttachSnapshotDecision
{
    public const string ACTION_IDEMPOTENT = 'idempotent';

    public const string ACTION_STALE = 'stale';

    public const string ACTION_ATTACH = 'attach';

    /** @param array<string, mixed>|null $staleDetails */
    public function __construct(
        public string $action,
        public int $viewId,
        public ?string $approvedSnapshotDigest,
        public ?array $staleDetails,
    ) {}
}
