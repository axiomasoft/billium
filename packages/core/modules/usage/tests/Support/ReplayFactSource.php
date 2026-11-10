<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Support;

use Billium\Usage\Data\FactRevision;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Internal\Storage\EffectiveFactSource;

final class ReplayFactSource implements EffectiveFactSource
{
    /** @var list<FactRevision> */
    public array $revisions = [];

    public function __construct(private readonly string $metricDigest) {}

    /** @return list<FactRevision> */
    public function revisionsForWindow(WindowSelector $window, string $connectionName): array
    {
        return $this->revisions;
    }

    public function metricDefinitionDigest(string $sellerRef, string $metricRef, int|string $metricVersion, string $connectionName): string
    {
        return $this->metricDigest;
    }
}
