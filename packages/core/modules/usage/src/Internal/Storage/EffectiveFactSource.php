<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Storage;

use Billium\Usage\Data\FactRevision;
use Billium\Usage\Data\WindowSelector;
use Packman\Attributes\Internal;

#[Internal]
interface EffectiveFactSource
{
    /** @return list<FactRevision> */
    public function revisionsForWindow(WindowSelector $window, string $connectionName): array;

    public function metricDefinitionDigest(string $sellerRef, string $metricRef, int|string $metricVersion, string $connectionName): ?string;
}
