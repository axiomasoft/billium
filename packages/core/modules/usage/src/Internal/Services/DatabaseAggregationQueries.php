<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Services;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Usage\Contracts\AggregationQueries;
use Billium\Usage\Data\FactRevision;
use Billium\Usage\Data\LateClassification;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Enums\LateClass;
use Billium\Usage\Internal\Errors\UsageProblems;
use Billium\Usage\Internal\Storage\AggregateStore;
use Billium\Usage\Internal\Storage\AggregationRows;
use Billium\Usage\Internal\Storage\EffectiveFactSource;
use Billium\Usage\Internal\Window\WindowQualifier;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DatabaseAggregationQueries implements AggregationQueries
{
    public function __construct(
        private AggregateStore $store, private EffectiveFactSource $facts,
        private SnapshotBuilder $builder, private WindowQualifier $qualifier,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(string $snapshotRef): array
    {
        $db = $this->store->database();
        $row = $this->store->snapshot(db: $db, ref: $snapshotRef) ?? throw UsageProblems::error('snapshot_unknown');
        $snapshot = $this->store->hydrate(db: $db, snapshot: $row);

        return [
            'snapshotRef' => $snapshot->snapshotRef, 'snapshotDigest' => $snapshot->snapshotDigest,
            'snapshotRevision' => $snapshot->snapshotRevision, 'predecessorDigest' => $snapshot->predecessorDigest,
            'window' => $snapshot->window->payload(), 'algorithmId' => $snapshot->algorithmId, 'algorithmVersion' => $snapshot->algorithmVersion,
            'quantity' => $snapshot->quantity, 'quantityScale' => $snapshot->quantityScale,
            'effectiveRevisions' => $snapshot->effectiveRevisions, 'distinctCount' => $snapshot->distinctCount,
            'memberSetDigest' => $snapshot->memberSetDigest, 'reproducibility' => $snapshot->reproducibility->value,
            'completenessFrontier' => $snapshot->completeness->evidence, 'completenessFrontierDigest' => $snapshot->completeness->digest,
            'reductionEvidence' => AggregationRows::map($row->reduction_evidence),
        ];
    }

    /** @return array<string, mixed> */
    public function windowStatus(WindowSelector $window): array
    {
        $this->store->authorize($window);
        $db = $this->store->database();
        $policy = $this->store->policy(db: $db, window: $window);
        $contract = $this->store->contract(db: $db, window: $window, policy: $policy);
        $evidence = $this->store->evidence(db: $db, window: $window, sources: $contract->requiredSources);
        $qualification = $this->qualifier->qualify(
            start: $window->startMicros, end: $window->endMicros, requiredSources: $contract->requiredSources,
            graceMicros: $contract->lateGraceMicros, evidence: $evidence,
        );
        $head = $this->store->head(db: $db, window: $window);
        $snapshot = $head === null ? null : $this->store->headSnapshot(db: $db, head: $head);
        $reasons = $qualification->reasons;
        $revisions = [];
        $effective = [];
        try {
            $revisions = $this->facts->revisionsForWindow(window: $window, connectionName: AggregationRows::text($db->getName()));
            $effective = $this->builder->effective(policy: $policy, window: $window, revisions: $revisions);
            if ($qualification->qualified) {
                $this->builder->build(policy: $policy, contract: $contract, window: $window, revisions: $revisions, evidence: $evidence);
            }
            if ($snapshot !== null) {
                $this->store->assertReproducible(db: $db, snapshot: $snapshot);
                $this->store->assertRetained(db: $db, snapshot: $snapshot, revisions: $revisions);
            }
        } catch (ProblemException $exception) {
            $reasons[] = $exception->detail;
        }
        $previous = $snapshot === null ? [] : $this->store->membership(db: $db, snapshot: $snapshot);
        $current = array_map(static fn (FactRevision $r): array => [
            'factId' => $r->factId, 'factRevision' => $r->factRevision, 'factDigest' => $r->factDigest,
        ], $effective);
        $old = array_column($previous, null, 'factId');
        $new = array_column($current, null, 'factId');
        $lag = 0;
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $id) {
            if (($old[$id] ?? null) !== ($new[$id] ?? null)) {
                $lag++;
            }
        }
        $coverageHeld = $evidence !== [] && array_any($reasons, static fn (string $r): bool => ! str_ends_with($r, ':watermark_behind'));
        $failureHeld = count($reasons) > count($qualification->reasons);
        $state = $coverageHeld || $failureHeld ? 'held'
            : ($qualification->qualified ? ($snapshot === null ? 'qualified' : 'final') : 'open');
        $result = [
            'state' => $state, 'lagFacts' => $lag,
            'aggregatedThroughIngestSeq' => $snapshot === null ? 0 : AggregationRows::integer($snapshot->ingest_frontier_seq),
            'ingestedThroughIngestSeq' => $revisions === [] ? 0 : max(array_map(static fn (FactRevision $f): int => $f->ingestSeq, $revisions)),
            'heldReasons' => $reasons, 'repairOrReplayRefs' => $qualification->repairRefs,
            'finalSnapshotRef' => $snapshot?->snapshot_ref,
            'progress' => $snapshot === null || $lag > 0 ? 'pending' : 'aggregated',
        ];
        if ($snapshot !== null && $state === 'final' && $lag === 0) {
            $result['quantity'] = $this->store->hydrate(db: $db, snapshot: $snapshot)->quantity;
        }

        return $result;
    }

    public function classifyLateFact(WindowSelector $window, int $observedAtMicros, string $factId, int $factRevision): LateClassification
    {
        $this->store->authorize($window);
        if ($observedAtMicros < $window->startMicros || $observedAtMicros >= $window->endMicros || $factRevision < 1) {
            throw UsageProblems::error('original_window_mismatch');
        }
        $db = $this->store->database();
        $policy = $this->store->policy(db: $db, window: $window);
        $contract = $this->store->contract(db: $db, window: $window, policy: $policy);
        $head = $this->store->head(db: $db, window: $window);
        $snapshot = $head === null ? null : $this->store->headSnapshot(db: $db, head: $head);
        $classification = LateClass::OpenWindow;
        if ($snapshot === null) {
            $evidence = $this->store->evidence(db: $db, window: $window, sources: $contract->requiredSources);
            $qualified = $this->qualifier->qualify(
                start: $window->startMicros, end: $window->endMicros, requiredSources: $contract->requiredSources,
                graceMicros: $contract->lateGraceMicros, evidence: $evidence,
            );
            if (array_any($qualified->reasons, static fn (string $r): bool => str_ends_with($r, ':watermark_behind'))) {
                $classification = LateClass::InGrace;
            }
        } else {
            $members = $this->store->membership(db: $db, snapshot: $snapshot);
            if (! array_any($members, static fn (array $m): bool => $m['factId'] === $factId && $m['factRevision'] === $factRevision)) {
                $classification = LateClass::PostFinal;
            }
        }

        return new LateClassification(
            classification: $classification, policy: $contract->postFinalPolicy, reopenAllowed: $contract->reopenAllowed,
            window: $window, observedAtMicros: $observedAtMicros, factId: $factId, factRevision: $factRevision,
            finalSnapshotRef: $snapshot === null ? null : AggregationRows::text($snapshot->snapshot_ref),
        );
    }

    public function latePolicy(WindowSelector $window): array
    {
        $this->store->authorize($window);
        $db = $this->store->database();
        $policy = $this->store->policy(db: $db, window: $window);
        $contract = $this->store->contract(db: $db, window: $window, policy: $policy);

        return ['policy' => $contract->postFinalPolicy, 'reopenAllowed' => $contract->reopenAllowed];
    }
}
