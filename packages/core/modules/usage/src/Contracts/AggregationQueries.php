<?php

declare(strict_types=1);

namespace Billium\Usage\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Usage\Data\LateClassification;
use Billium\Usage\Data\WindowSelector;

#[Api]
interface AggregationQueries
{
    /** @return array<string, mixed> */
    public function snapshot(string $snapshotRef): array;

    /** @return array<string, mixed> */
    public function windowStatus(WindowSelector $window): array;

    public function classifyLateFact(WindowSelector $window, int $observedAtMicros, string $factId, int $factRevision): LateClassification;
}
