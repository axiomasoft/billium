<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Usage\Enums\LateClass;
use Billium\Usage\Enums\LatePolicy;

#[Api]
final readonly class LateClassification
{
    public function __construct(
        public LateClass $classification,
        public LatePolicy $policy,
        public bool $reopenAllowed,
        public WindowSelector $window,
        public int $observedAtMicros,
        public string $factId,
        public int $factRevision,
        public ?string $finalSnapshotRef,
    ) {}
}
