<?php

declare(strict_types=1);

namespace Billium\Usage\Contracts;

use Billium\Usage\Data\LateClassification;
use Billium\Usage\Data\SourceCompleteness;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Enums\LatePolicy;
use Packman\Attributes\Api;

/**
 * @phpstan-import-type WindowPayload from WindowSelector
 * @phpstan-import-type FrontierEntry from SourceCompleteness
 *
 * @phpstan-type SnapshotView array{
 *     snapshotRef: string, snapshotDigest: string, snapshotRevision: int, predecessorDigest: ?string,
 *     window: WindowPayload, algorithmId: string, algorithmVersion: int, quantity: string, quantityScale: int,
 *     effectiveRevisions: list<array{factId: string, factRevision: int, factDigest: string}>,
 *     distinctCount: ?int, memberSetDigest: ?string, reproducibility: string,
 *     completenessFrontier: list<FrontierEntry>, completenessFrontierDigest: string,
 *     reductionEvidence: array<string, mixed>,
 * }
 * @phpstan-type WindowStatus array{
 *     state: 'open'|'qualified'|'final'|'held', lagFacts: int,
 *     aggregatedThroughIngestSeq: int, ingestedThroughIngestSeq: int,
 *     heldReasons: list<string>, repairOrReplayRefs: list<string>, finalSnapshotRef: ?string,
 *     progress: 'pending'|'aggregated', quantity?: string,
 * }
 */
#[Api]
interface AggregationQueries
{
    /** @return SnapshotView */
    public function snapshot(string $snapshotRef): array;

    /** @return WindowStatus */
    public function windowStatus(WindowSelector $window): array;

    public function classifyLateFact(WindowSelector $window, int $observedAtMicros, string $factId, int $factRevision): LateClassification;

    /** @return array{policy: LatePolicy, reopenAllowed: bool} */
    public function latePolicy(WindowSelector $window): array;
}
