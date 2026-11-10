<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Rating;

use Billium\Contracts\Attributes\Api;

#[Api]
final readonly class FrozenAggregate
{
    public string $metricVersion;

    /** @param array<string, mixed> $snapshot */
    public function __construct(
        public string $componentRef,
        public string $metricRef,
        int|string $metricVersion,
        public string $snapshotRef,
        public string $snapshotDigest,
        public int $windowStartMicros,
        public int $windowEndMicros,
        public string $completenessFrontierDigest,
        public string $quantity,
        public int $quantityScale,
        public array $snapshot,
    ) {
        $this->metricVersion = (string) $metricVersion;
    }
}
