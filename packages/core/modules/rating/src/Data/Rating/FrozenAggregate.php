<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Rating;

use Billium\Contracts\Attributes\Api;

#[Api]
final readonly class FrozenAggregate
{
    public string $metricVersion;

    /** @param array<string, mixed> $snapshot
     * @param  array<string, mixed>|null  $quantitySnapshot
     */
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
        public ?array $quantitySnapshot = null,
    ) {
        $this->metricVersion = (string) $metricVersion;
    }
}
