<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Storage;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Usage\Data\AggregateSnapshot;
use Billium\Usage\Data\AggregationPolicy;
use Billium\Usage\Data\CompletenessFrontier;
use Billium\Usage\Data\FactRevision;
use Billium\Usage\Data\SourceCompleteness;
use Billium\Usage\Data\SourceFinalityContract;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Enums\AggregationSemantics;
use Billium\Usage\Enums\CompletenessStatus;
use Billium\Usage\Enums\GaugePolicy;
use Billium\Usage\Enums\LatePolicy;
use Billium\Usage\Enums\MissingInitialPolicy;
use Billium\Usage\Enums\Reproducibility;
use Billium\Usage\Enums\RoundingMode;
use Billium\Usage\Internal\Canonical\CanonicalJson;
use Billium\Usage\Internal\Errors\UsageProblems;
use Billium\Usage\Internal\Math\Decimal;
use Billium\Usage\Internal\Reducers\GaugeReducer;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class AggregateStore
{
    /** Rows per multi-row INSERT; keeps the widest membership row well below the PostgreSQL bind parameter limit. */
    public const int INSERT_CHUNK = 1000;

    public function __construct(private DatabaseManager $databases, private CallerContext $callers, private BilliumOptions $options) {}

    public function database(?string $connectionName = null): Connection
    {
        return $this->databases->connection($connectionName ?? $this->options->connection);
    }

    public function authorize(WindowSelector $window): void
    {
        $caller = $this->callers->current();
        if ($caller->sellerRef !== $window->sellerRef || $caller->environment !== $window->environment) {
            throw UsageProblems::error('scope_denied');
        }
    }

    /** @return array{seller_ref:string, environment:string} */
    public function scope(): array
    {
        $caller = $this->callers->current();

        return ['seller_ref' => $caller->sellerRef, 'environment' => $caller->environment];
    }

    public function policy(Connection $db, WindowSelector $window): AggregationPolicy
    {
        $this->authorize($window);
        $row = $db->table('billium_usage_aggregation_policy')->where([
            'seller_ref' => $window->sellerRef, 'environment' => $window->environment,
            'metric_ref' => $window->metricRef, 'metric_version' => $window->metricVersion,
        ])->first() ?? throw UsageProblems::policyUnknown('metric');

        return new AggregationPolicy(
            metricRef: AggregationRows::text($row->metric_ref), metricVersion: AggregationRows::text($row->metric_version),
            metricDefinitionDigest: AggregationRows::text($row->metric_definition_digest),
            semantics: AggregationSemantics::from(AggregationRows::text($row->semantics)),
            algorithmId: AggregationRows::text($row->algorithm_id), algorithmVersion: AggregationRows::integer($row->algorithm_version),
            gaugePolicy: $row->gauge_policy === null ? null : GaugePolicy::from(AggregationRows::text($row->gauge_policy)),
            missingInitial: MissingInitialPolicy::from(AggregationRows::text($row->missing_initial)),
            carryForward: $row->carry_forward === true,
            maxCarrySeconds: $row->max_carry_seconds === null ? null : AggregationRows::integer($row->max_carry_seconds),
            timeWeightUnitSeconds: $row->time_weight_unit_seconds === null ? null : AggregationRows::integer($row->time_weight_unit_seconds),
            quantityScale: AggregationRows::integer($row->quantity_scale), rounding: RoundingMode::from(AggregationRows::text($row->rounding_mode)),
            allowNegative: $row->allow_negative === true, finalityContractRef: AggregationRows::text($row->finality_contract_ref),
            finalityContractVersion: AggregationRows::integer($row->finality_contract_version),
            identityKeyVersion: $row->identity_key_version === null ? null : AggregationRows::text($row->identity_key_version),
            identityNamespace: $row->identity_namespace === null ? null : AggregationRows::text($row->identity_namespace),
            crossSourceIdentityPolicy: AggregationRows::text($row->cross_source_identity_policy),
        );
    }

    public function contract(Connection $db, WindowSelector $window, AggregationPolicy $policy): SourceFinalityContract
    {
        $row = $db->table('billium_usage_source_finality_contract')->where([
            'seller_ref' => $window->sellerRef, 'environment' => $window->environment,
            'contract_ref' => $policy->finalityContractRef, 'version' => $policy->finalityContractVersion,
        ])->first() ?? throw UsageProblems::policyUnknown('finality contract');
        $contract = new SourceFinalityContract(
            contractRef: AggregationRows::text($row->contract_ref), version: AggregationRows::integer($row->version),
            requiredSources: array_map(AggregationRows::text(...), AggregationRows::items($row->required_sources)),
            lateGraceMicros: AggregationRows::integer($row->late_grace_micros),
            postFinalPolicy: LatePolicy::from(AggregationRows::text($row->post_final_policy)), reopenAllowed: $row->reopen_allowed === true,
        );
        if (CanonicalJson::digest($contract->payload()) !== $row->contract_digest) {
            throw UsageProblems::error('snapshot_digest_mismatch');
        }

        return $contract;
    }

    /**
     * @param  list<string>  $sources
     * @return list<SourceCompleteness>
     */
    public function evidence(Connection $db, WindowSelector $window, array $sources): array
    {
        return array_values(array_map(static fn (stdClass $row): SourceCompleteness => new SourceCompleteness(
            sourceRef: AggregationRows::text($row->source_ref), evidenceSeq: AggregationRows::integer($row->evidence_seq),
            status: CompletenessStatus::from(AggregationRows::text($row->status)),
            rangeStartMicros: AggregationRows::integer($row->range_start_micros), rangeEndMicros: AggregationRows::integer($row->range_end_micros),
            watermarkMicros: AggregationRows::integer($row->watermark_micros),
            repairOrReplayRef: $row->repair_or_replay_ref === null ? null : AggregationRows::text($row->repair_or_replay_ref),
            evidenceDigest: AggregationRows::text($row->evidence_digest),
        ), $db->table('billium_usage_source_completeness')->where([
            'seller_ref' => $window->sellerRef, 'environment' => $window->environment,
        ])->whereIn('source_ref', $sources)->where('range_start_micros', '<', $window->endMicros)
            ->where('range_end_micros', '>', $window->startMicros)->get()->all()));
    }

    public static function lockRequest(WindowSelector $window): LockRequest
    {
        return new LockRequest(
            rank: LockRank::UsageSeries, table: 'billium_usage_aggregate_series_head',
            keys: [$window->seriesKey()], keyColumn: 'series_key',
        );
    }

    public function lockHead(TransactionContext $context, WindowSelector $window): stdClass
    {
        $db = $this->database($context->connectionName());
        $db->statement(
            query: 'INSERT INTO public.billium_usage_aggregate_series_head
                (seller_ref,environment,metric_ref,metric_version,group_hash,series_key,window_start_micros,window_end_micros)
                VALUES (?,?,?,?,?,?,?,?) ON CONFLICT DO NOTHING',
            bindings: [$window->sellerRef, $window->environment, $window->metricRef, $window->metricVersion,
                CanonicalJson::digest((object) $window->groupKey), $window->seriesKey(), $window->startMicros, $window->endMicros],
        );
        $context->lock(self::lockRequest($window));

        return $this->head(db: $db, window: $window) ?? throw UsageProblems::error('snapshot_superseded');
    }

    public function head(Connection $db, WindowSelector $window): ?stdClass
    {
        return $db->table('billium_usage_aggregate_series_head')->where([
            'seller_ref' => $window->sellerRef, 'environment' => $window->environment, 'series_key' => $window->seriesKey(),
        ])->first();
    }

    public function snapshot(Connection $db, string $ref): ?stdClass
    {
        return $db->table('billium_usage_aggregate_snapshot')->where($this->scope())->where('snapshot_ref', $ref)->first();
    }

    public function headSnapshot(Connection $db, stdClass $head): ?stdClass
    {
        if ($head->head_digest === null) {
            return null;
        }

        return $db->table('billium_usage_aggregate_snapshot')->where($this->scope())->where('snapshot_digest', $head->head_digest)->first();
    }

    public function reproducibility(Connection $db, stdClass $snapshot): Reproducibility
    {
        $rows = $db->table('billium_usage_aggregate_reproducibility_event')->where('snapshot_id', $snapshot->id)->pluck('reproducibility')->all();
        if (in_array('erased', $rows, true)) {
            return Reproducibility::Erased;
        }
        if (in_array('retention_expired', $rows, true)) {
            return Reproducibility::RetentionExpired;
        }

        return Reproducibility::Reproducible;
    }

    public function assertReproducible(Connection $db, stdClass $snapshot): void
    {
        if ($this->reproducibility(db: $db, snapshot: $snapshot) !== Reproducibility::Reproducible) {
            throw UsageProblems::error('not_reproducible');
        }
    }

    /** @return list<array{factId:string, factRevision:int, factDigest:string}> */
    public function membership(Connection $db, stdClass $snapshot): array
    {
        $members = array_map(static fn (stdClass $row): array => [
            'factId' => AggregationRows::text($row->fact_id), 'factRevision' => AggregationRows::integer($row->fact_revision),
            'factDigest' => AggregationRows::text($row->fact_digest),
        ], $db->table('billium_usage_aggregate_member')->where('snapshot_id', $snapshot->id)->get()->all());
        usort($members, static fn (array $a, array $b): int => strcmp($a['factId'], $b['factId']));

        return $members;
    }

    public function hasMember(Connection $db, stdClass $snapshot, string $factId, int $factRevision): bool
    {
        return $db->table('billium_usage_aggregate_member')->where([
            'snapshot_id' => $snapshot->id, 'fact_id' => $factId, 'fact_revision' => $factRevision,
        ])->exists();
    }

    public function window(stdClass $snapshot): WindowSelector
    {
        return new WindowSelector(
            sellerRef: AggregationRows::text($snapshot->seller_ref), environment: AggregationRows::text($snapshot->environment),
            metricRef: AggregationRows::text($snapshot->metric_ref), metricVersion: AggregationRows::text($snapshot->metric_version),
            groupKey: AggregationRows::group($snapshot->group_key),
            startMicros: AggregationRows::integer($snapshot->window_start_micros), endMicros: AggregationRows::integer($snapshot->window_end_micros),
        );
    }

    public function hydrate(Connection $db, stdClass $snapshot): AggregateSnapshot
    {
        $frontier = array_map(AggregationRows::completeness(...), AggregationRows::items($snapshot->completeness_frontier));
        $scale = AggregationRows::integer($snapshot->quantity_scale);

        return new AggregateSnapshot(
            snapshotRef: AggregationRows::text($snapshot->snapshot_ref), snapshotDigest: AggregationRows::text($snapshot->snapshot_digest),
            snapshotRevision: AggregationRows::integer($snapshot->snapshot_revision),
            predecessorDigest: $snapshot->predecessor_digest === null ? null : AggregationRows::text($snapshot->predecessor_digest),
            window: $this->window($snapshot), algorithmId: AggregationRows::text($snapshot->algorithm_id),
            algorithmVersion: AggregationRows::integer($snapshot->algorithm_version),
            quantity: Decimal::format(unscaled: Decimal::unscaled(value: AggregationRows::text($snapshot->quantity), scale: $scale), scale: $scale),
            quantityScale: $scale, effectiveRevisions: $this->membership(db: $db, snapshot: $snapshot),
            distinctCount: $snapshot->distinct_count === null ? null : AggregationRows::integer($snapshot->distinct_count),
            memberSetDigest: $snapshot->member_set_digest === null ? null : AggregationRows::text($snapshot->member_set_digest),
            reproducibility: $this->reproducibility(db: $db, snapshot: $snapshot),
            completeness: new CompletenessFrontier(
                contractRef: AggregationRows::text($snapshot->finality_contract_ref),
                contractVersion: AggregationRows::integer($snapshot->finality_contract_version), evidence: $frontier,
            ),
            unit: $this->resolveUnitForRow(db: $db, row: $snapshot),
        );
    }

    private function resolveUnitForRow(Connection $db, stdClass $row): ?string
    {
        $stored = $row->metric_unit ?? null;
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }
        $verified = $db->selectOne(<<<'SQL'
            SELECT m.unit
            FROM public.billium_usage_aggregation_policy p
            JOIN public.billium_usage_metric m
              ON m.seller_ref     = p.seller_ref
             AND m.environment    = p.environment
             AND m.metric_ref     = p.metric_ref
             AND m.metric_version = p.metric_version
            WHERE p.seller_ref     = ?
              AND p.environment    = ?
              AND p.metric_ref     = ?
              AND p.metric_version = ?
              AND p.metric_definition_digest = m.digest
            SQL, [$row->seller_ref, $row->environment, $row->metric_ref, $row->metric_version]);

        return is_object($verified) && is_string($verified->unit ?? null) && $verified->unit !== ''
            ? $verified->unit
            : null;
    }

    /** @param list<FactRevision> $revisions */
    public function assertRetained(Connection $db, stdClass $snapshot, array $revisions): void
    {
        $retained = [];
        foreach ($revisions as $revision) {
            $retained[$revision->factId] = max($retained[$revision->factId] ?? 0, $revision->factRevision);
        }
        foreach ($this->membership(db: $db, snapshot: $snapshot) as $member) {
            if (($retained[$member['factId']] ?? 0) < $member['factRevision']) {
                throw UsageProblems::error('not_reproducible');
            }
        }
    }

    /** @param list<FactRevision> $effective */
    public function insert(TransactionContext $context, AggregateSnapshot $snapshot, AggregationPolicy $policy, array $effective, stdClass $head, ?string $unit = null): void
    {
        $db = $this->database($context->connectionName());
        $window = $snapshot->window;
        $reduction = [];
        if ($policy->semantics === AggregationSemantics::Gauge) {
            $selection = (new GaugeReducer)->samples(
                policy: $policy, start: $window->startMicros, end: $window->endMicros, allEffective: $effective,
            );
            $reduction = ['carried' => $selection['carried'], 'declaredZero' => $selection['declaredZero']];
        }
        $id = $db->table('billium_usage_aggregate_snapshot')->insertGetId([
            'snapshot_ref' => $snapshot->snapshotRef, 'snapshot_digest' => $snapshot->snapshotDigest,
            'seller_ref' => $window->sellerRef, 'environment' => $window->environment, 'metric_ref' => $window->metricRef,
            'metric_version' => $window->metricVersion, 'metric_unit' => $unit ?? $snapshot->unit,
            'group_hash' => CanonicalJson::digest((object) $window->groupKey),
            'group_key' => CanonicalJson::encode((object) $window->groupKey), 'series_key' => $window->seriesKey(),
            'window_start_micros' => $window->startMicros, 'window_end_micros' => $window->endMicros,
            'snapshot_revision' => $snapshot->snapshotRevision, 'predecessor_digest' => $snapshot->predecessorDigest,
            'algorithm_id' => $snapshot->algorithmId, 'algorithm_version' => $snapshot->algorithmVersion,
            'quantity' => $snapshot->quantity, 'quantity_scale' => $snapshot->quantityScale,
            'distinct_count' => $snapshot->distinctCount, 'member_set_digest' => $snapshot->memberSetDigest,
            'finality_contract_ref' => $snapshot->completeness->contractRef, 'finality_contract_version' => $snapshot->completeness->contractVersion,
            'completeness_frontier' => CanonicalJson::encode($snapshot->completeness->evidence),
            'completeness_frontier_digest' => $snapshot->completeness->digest, 'reduction_evidence' => CanonicalJson::encode((object) $reduction),
            'ingest_frontier_seq' => $effective === [] ? 0 : max(array_map(static fn (FactRevision $f): int => $f->ingestSeq, $effective)),
        ]);
        $members = [];
        $distinct = [];
        foreach ($effective as $fact) {
            $members[] = [
                'snapshot_id' => $id, 'fact_id' => $fact->factId, 'fact_revision' => $fact->factRevision,
                'fact_digest' => $fact->factDigest, 'source_ref' => $fact->sourceRef, 'source_sequence' => $fact->sourceSequence,
                'observed_at_micros' => $fact->observedAtMicros, 'quantity_unscaled' => $fact->quantityUnscaled,
            ];
            foreach (array_unique($fact->memberIds) as $member) {
                $distinct[] = [
                    'snapshot_id' => $id, 'member_id' => $member, 'identity_key_version' => $policy->identityKeyVersion,
                    'identity_namespace' => $policy->identityNamespace, 'contributing_fact_id' => $fact->factId,
                ];
            }
        }
        // Membership grows with the window; one multi-row statement per chunk replaces a round trip per fact and member.
        // Every fact row precedes the distinct rows that reference it.
        foreach (array_chunk($members, self::INSERT_CHUNK) as $chunk) {
            $db->table('billium_usage_aggregate_member')->insert($chunk);
        }
        foreach (array_chunk($distinct, self::INSERT_CHUNK) as $chunk) {
            $db->table('billium_usage_aggregate_distinct_member')->insert($chunk);
        }
        $changed = $db->table('billium_usage_aggregate_series_head')->where([
            'seller_ref' => $window->sellerRef, 'environment' => $window->environment, 'series_key' => $window->seriesKey(),
            'row_version' => $head->row_version,
        ])->update(['head_digest' => $snapshot->snapshotDigest, 'head_revision' => $snapshot->snapshotRevision, 'row_version' => AggregationRows::integer($head->row_version) + 1]);
        if ($changed !== 1) {
            throw UsageProblems::error('snapshot_superseded');
        }
        // D63: internal module-owned notification, never inserted into the frozen public event feed.
        $db->table('billium_usage_aggregate_event')->insert([
            'snapshot_id' => $id, 'seller_ref' => $window->sellerRef, 'environment' => $window->environment,
            'event_type' => 'usage.aggregate.frozen.v1',
            'payload' => CanonicalJson::encode([
                'ref' => $snapshot->snapshotRef, 'digest' => $snapshot->snapshotDigest, 'quantity' => $snapshot->quantity,
                'window' => $window->payload(), 'metric' => ['ref' => $window->metricRef, 'version' => $window->metricVersion],
            ]),
        ]);
    }
}
