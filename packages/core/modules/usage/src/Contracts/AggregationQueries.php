<?php

declare(strict_types=1);

namespace Billium\Usage\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Usage\Data\LateClassification;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Enums\LatePolicy;

#[Api]
interface AggregationQueries
{
    /** @return array<string, mixed> */
    public function snapshot(string $snapshotRef): array;

    /** @return array<string, mixed> */
    public function windowStatus(WindowSelector $window): array;

    public function classifyLateFact(WindowSelector $window, int $observedAtMicros, string $factId, int $factRevision): LateClassification;

    /** @return array{policy: LatePolicy, reopenAllowed: bool} */
    public function latePolicy(WindowSelector $window): array;
}
