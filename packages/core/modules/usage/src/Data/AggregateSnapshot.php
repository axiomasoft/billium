<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

use Billium\Contracts\Attributes\Api;
use Billium\Usage\Enums\Reproducibility;

#[Api]
final readonly class AggregateSnapshot
{
    /** @param list<array{factId:string, factRevision:int, factDigest:string}> $effectiveRevisions */
    public function __construct(
        public string $snapshotRef,
        public string $snapshotDigest,
        public int $snapshotRevision,
        public ?string $predecessorDigest,
        public WindowSelector $window,
        public string $algorithmId,
        public int $algorithmVersion,
        public string $quantity,
        public int $quantityScale,
        public array $effectiveRevisions,
        public ?int $distinctCount,
        public ?string $memberSetDigest,
        public Reproducibility $reproducibility,
        public CompletenessFrontier $completeness,
    ) {}
}
