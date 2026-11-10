<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Participants;

use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Usage\Contracts\AggregationParticipants;
use Billium\Usage\Data\AggregateSnapshotRef;
use Billium\Usage\Data\FactRevision;
use Billium\Usage\Internal\Errors\UsageProblems;
use Billium\Usage\Internal\Services\SnapshotBuilder;
use Billium\Usage\Internal\Storage\AggregateStore;
use Billium\Usage\Internal\Storage\EffectiveFactSource;
use Billium\Usage\Internal\Window\WindowQualifier;

final readonly class DatabaseAggregationParticipants implements AggregationParticipants
{
    public function __construct(
        private OperationRunner $runner,
        private AggregateStore $store,
        private WindowQualifier $qualifier,
        private EffectiveFactSource $facts,
        private SnapshotBuilder $builder,
    ) {}

    public function assertSnapshotCurrent(TransactionContext $context, AggregateSnapshotRef $ref): void
    {
        $this->runner->assertCurrent(context: $context, moduleId: 'usage');
        $db = $this->store->database($context->connectionName());
        $snapshot = $this->store->snapshot(db: $db, ref: $ref->snapshotRef) ?? throw UsageProblems::error('snapshot_superseded');
        $window = $this->store->window($snapshot);
        if ($ref->quantity !== null || $ref->window !== null || $ref->frontierDigest !== null || $ref->quantityScale !== null) {
            $retained = $this->store->hydrate(db: $db, snapshot: $snapshot);
            if ($ref->quantity !== $retained->quantity || $ref->quantityScale !== $retained->quantityScale
                || $ref->window === null || $ref->window->seriesKey() !== $retained->window->seriesKey()
                || $ref->frontierDigest !== $retained->completeness->digest) {
                throw UsageProblems::error('snapshot_superseded');
            }
        }
        // The current context API reasserts a planned lock. After rank 35 this is reentrant only;
        // omission from an outer rating plan fails the global lock order instead of racing a freeze.
        $context->lock(AggregateStore::lockRequest($window));
        $head = $this->store->head(db: $db, window: $window);
        if ($snapshot->snapshot_digest !== $ref->snapshotDigest || $head?->head_digest !== $ref->snapshotDigest) {
            throw UsageProblems::error('snapshot_superseded');
        }
        $this->store->assertReproducible(db: $db, snapshot: $snapshot);
        $policy = $this->store->policy(db: $db, window: $window);
        $revisions = $this->facts->revisionsForWindow(window: $window, connectionName: $context->connectionName());
        $this->store->assertRetained(db: $db, snapshot: $snapshot, revisions: $revisions);
        $current = array_map(static fn (FactRevision $fact): array => [
            'factId' => $fact->factId, 'factRevision' => $fact->factRevision, 'factDigest' => $fact->factDigest,
        ], $this->builder->effective(policy: $policy, window: $window, revisions: $revisions));
        if ($current !== $this->store->membership(db: $db, snapshot: $snapshot)) {
            throw UsageProblems::error('snapshot_superseded');
        }
        $contract = $this->store->contract(db: $db, window: $window, policy: $policy);
        $qualification = $this->qualifier->qualify(
            start: $window->startMicros, end: $window->endMicros, requiredSources: $contract->requiredSources,
            graceMicros: $contract->lateGraceMicros,
            evidence: $this->store->evidence(db: $db, window: $window, sources: $contract->requiredSources),
        );
        if (! $qualification->qualified) {
            throw UsageProblems::windowHeld(reasons: $qualification->reasons, repairRefs: $qualification->repairRefs);
        }
    }
}
