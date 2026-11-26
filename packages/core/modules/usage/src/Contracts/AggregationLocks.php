<?php

declare(strict_types=1);

namespace Billium\Usage\Contracts;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Usage\Data\AggregateSnapshotRef;
use Packman\Attributes\Api;

/** The owning module plans and acquires the complete early rank-35 set for a composed root (D27/D60). */
#[Api]
interface AggregationLocks
{
    /** @param list<AggregateSnapshotRef> $snapshots */
    public function acquire(TransactionContext $context, array $snapshots): void;
}
