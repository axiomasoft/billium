<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Adapters;

use Billium\Core\Contracts\TransactionContext;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Internal\Storage\RatingRows;
use Billium\Usage\Contracts\AggregationLocks;
use Billium\Usage\Contracts\AggregationParticipants;
use Billium\Usage\Data\AggregateSnapshotRef;
use Billium\Usage\Data\WindowSelector;

final readonly class UsageAggregateAdapter
{
    public function __construct(private AggregationLocks $locks, private AggregationParticipants $participants) {}

    public function lock(TransactionContext $context, FrozenRatingInputs $inputs): void
    {
        $refs = array_map(static fn ($a): AggregateSnapshotRef => new AggregateSnapshotRef(snapshotRef: $a->snapshotRef, snapshotDigest: $a->snapshotDigest), $inputs->aggregates);
        $this->locks->acquire(context: $context, snapshots: $refs);
    }

    public function assertCurrent(TransactionContext $context, FrozenRatingInputs $inputs): void
    {
        foreach ($inputs->aggregates as $aggregate) {
            $window = RatingRows::map($aggregate->snapshot['window'] ?? null);
            $this->participants->assertSnapshotCurrent(context: $context,
                ref: new AggregateSnapshotRef(snapshotRef: $aggregate->snapshotRef, snapshotDigest: $aggregate->snapshotDigest,
                    quantity: $aggregate->quantity, quantityScale: $aggregate->quantityScale,
                    window: new WindowSelector(sellerRef: $inputs->sellerRef, environment: $inputs->environment,
                        metricRef: $aggregate->metricRef, metricVersion: $aggregate->metricVersion, groupKey: RatingRows::map($window['group'] ?? null),
                        startMicros: $aggregate->windowStartMicros, endMicros: $aggregate->windowEndMicros),
                    frontierDigest: $aggregate->completenessFrontierDigest));
        }
    }
}
