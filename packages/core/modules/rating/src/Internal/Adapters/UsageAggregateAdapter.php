<?php

declare(strict_types=1);

namespace Billium\Rating\Internal\Adapters;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Rating\Data\Rating\FrozenAggregate;
use Billium\Rating\Data\Rating\FrozenRatingInputs;
use Billium\Rating\Data\Rating\RatingHold;
use Billium\Rating\Enums\HoldReason;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Internal\Pipeline\FixedOccurrenceInput;
use Billium\Rating\Internal\Pipeline\RatingComputation;
use Billium\Rating\Internal\Storage\RatingRows;
use Billium\Usage\Contracts\AggregationLocks;
use Billium\Usage\Contracts\AggregationParticipants;
use Billium\Usage\Contracts\AggregationQueries;
use Billium\Usage\Data\AggregateSnapshotRef;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Enums\LatePolicy;
use Packman\Attributes\Internal;

/**
 * Rating's only route to the optional usage capability; every member is absent when usage is not installed.
 *
 * @phpstan-import-type WindowStatus from AggregationQueries
 */
#[Internal]
final readonly class UsageAggregateAdapter
{
    public function __construct(private ?AggregationLocks $locks = null, private ?AggregationParticipants $participants = null,
        private ?AggregationQueries $queries = null) {}

    public function available(): bool
    {
        return $this->queries !== null;
    }

    /** @return array<string, mixed> the retained snapshot in canonical JSON form, as frozen into rating inputs */
    public function snapshot(string $snapshotRef): array
    {
        return RatingRows::map(json_decode(CanonicalJson::encode($this->queries()->snapshot($snapshotRef)), true, 128, JSON_THROW_ON_ERROR));
    }

    /** @return WindowStatus */
    public function windowStatus(WindowSelector $window): array
    {
        return $this->queries()->windowStatus($window);
    }

    /** Window of a frozen usage aggregate, rebuilt from the scope it was frozen under. */
    public function window(string $sellerRef, string $environment, FrozenAggregate $aggregate): WindowSelector
    {
        $window = RatingRows::map($aggregate->snapshot['window'] ?? null);

        return new WindowSelector(sellerRef: $sellerRef, environment: $environment,
            metricRef: $aggregate->metricRef, metricVersion: $aggregate->metricVersion, groupKey: RatingRows::map($window['group'] ?? null),
            startMicros: $aggregate->windowStartMicros, endMicros: $aggregate->windowEndMicros);
    }

    /** Applies the usage late policy to a window that received facts after it was rated; null lets the correction re-read it. */
    public function latePolicyHold(WindowSelector $window): ?RatingHold
    {
        $policy = $this->queries()->latePolicy($window);
        if ($policy['policy'] === LatePolicy::Reject) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.usage.late_rejected');
        }
        if ($policy['policy'] === LatePolicy::ManualReview) {
            return new RatingHold(reason: HoldReason::UnknownPolicy, repairOrReplayRefs: [], detail: 'billium.usage.late_manual_review');
        }
        if (! $policy['reopenAllowed']) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.usage.late_rejected');
        }

        return null;
    }

    public function lock(TransactionContext $context, FrozenRatingInputs $inputs): void
    {
        $refs = array_map(static fn (FrozenAggregate $a): AggregateSnapshotRef => new AggregateSnapshotRef(snapshotRef: $a->snapshotRef, snapshotDigest: $a->snapshotDigest),
            array_values(array_filter($inputs->aggregates, static fn (FrozenAggregate $a): bool => ! FixedOccurrenceInput::matches($a))));
        if ($refs !== []) {
            ($this->locks ?? RatingComputation::refuse('usage_capability_absent'))->acquire(context: $context, snapshots: $refs);
        }
    }

    public function assertCurrent(TransactionContext $context, FrozenRatingInputs $inputs): void
    {
        foreach ($inputs->aggregates as $aggregate) {
            if (FixedOccurrenceInput::matches($aggregate)) {
                continue;
            }
            ($this->participants ?? RatingComputation::refuse('usage_capability_absent'))->assertSnapshotCurrent(context: $context,
                ref: new AggregateSnapshotRef(snapshotRef: $aggregate->snapshotRef, snapshotDigest: $aggregate->snapshotDigest,
                    quantity: $aggregate->quantity, quantityScale: $aggregate->quantityScale,
                    window: $this->window(sellerRef: $inputs->sellerRef, environment: $inputs->environment, aggregate: $aggregate),
                    frontierDigest: $aggregate->completenessFrontierDigest));
        }
    }

    private function queries(): AggregationQueries
    {
        return $this->queries ?? RatingComputation::refuse('usage_capability_absent');
    }
}
