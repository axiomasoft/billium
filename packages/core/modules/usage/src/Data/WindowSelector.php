<?php

declare(strict_types=1);

namespace Billium\Usage\Data;

use Billium\Usage\Internal\Canonical\CanonicalJson;
use Billium\Usage\Internal\Errors\UsageProblems;
use Packman\Attributes\Api;

#[Api]
final readonly class WindowSelector
{
    public string $metricVersion;

    /** @var array<string, string> */
    public array $groupKey;

    /** @param array<array-key, mixed> $groupKey */
    public function __construct(
        public string $sellerRef,
        public string $environment,
        public string $metricRef,
        int|string $metricVersion,
        array $groupKey,
        public int $startMicros,
        public int $endMicros,
    ) {
        if ($sellerRef === '' || ! in_array($environment, ['test', 'live'], true) || $metricRef === '' || (string) $metricVersion === '' || $startMicros >= $endMicros) {
            throw UsageProblems::error('window_invalid');
        }
        $normalized = [];
        foreach ($groupKey as $key => $value) {
            if (! is_string($key) || ! is_string($value) || $key === '') {
                throw UsageProblems::error('group_invalid');
            }
            $normalized[$key] = $value;
        }
        ksort($normalized, SORT_STRING);
        $this->metricVersion = (string) $metricVersion;
        $this->groupKey = $normalized;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'sellerRef' => $this->sellerRef, 'environment' => $this->environment,
            'metricRef' => $this->metricRef, 'metricVersion' => $this->metricVersion,
            'group' => (object) $this->groupKey, 'startMicros' => $this->startMicros, 'endMicros' => $this->endMicros,
        ];
    }

    public function seriesKey(): string
    {
        return CanonicalJson::encode([
            'sellerRef' => $this->sellerRef, 'environment' => $this->environment,
            'metricRef' => $this->metricRef, 'metricVersion' => $this->metricVersion,
            'groupHash' => CanonicalJson::digest((object) $this->groupKey),
            'startMicros' => $this->startMicros, 'endMicros' => $this->endMicros,
        ]);
    }
}
