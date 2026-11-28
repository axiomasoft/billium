<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Storage;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\MetricVersion;
use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Usage\Data\FactRevision;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Internal\Canonical\CanonicalJson;
use Billium\Usage\Internal\Errors\UsageProblems;
use Billium\Usage\Internal\Math\Decimal;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class DatabaseEffectiveFactSource implements EffectiveFactSource
{
    // Reserved attribution dimensions keep historical payer/period scopes from being merged.
    public const array ATTRIBUTION = [
        'subjectRef' => 'subject_ref', 'customerRef' => 'customer_ref', 'billingAccountRef' => 'billing_account_ref',
        'componentRef' => 'component_ref', 'agreementRef' => 'agreement_ref', 'agreementRevision' => 'agreement_revision',
        'attributionVersion' => 'attribution_version', 'mappingRef' => 'mapping_ref', 'periodRef' => 'period_ref',
    ];

    public function __construct(private DatabaseManager $databases, private CallerContext $callers, private Codec $codec) {}

    public function metricDefinitionDigest(string $sellerRef, string $metricRef, int|string $metricVersion, string $connectionName): ?string
    {
        $caller = $this->callers->current();
        if ($sellerRef !== $caller->sellerRef) {
            throw UsageProblems::error('scope_denied');
        }
        $row = $this->databases->connection($connectionName)->table('billium_usage_metric')->where([
            'seller_ref' => $sellerRef, 'environment' => $caller->environment,
            'metric_ref' => $metricRef, 'metric_version' => (string) $metricVersion,
        ])->first(['digest']);

        return $row === null ? null : AggregationRows::text($row->digest);
    }

    public function metricUnit(string $sellerRef, string $metricRef, int|string $metricVersion, string $expectedDefinitionDigest, string $connectionName): ?string
    {
        $caller = $this->callers->current();
        if ($sellerRef !== $caller->sellerRef) {
            throw UsageProblems::error('scope_denied');
        }
        $row = $this->databases->connection($connectionName)->table('billium_usage_metric')->where([
            'seller_ref' => $sellerRef, 'environment' => $caller->environment,
            'metric_ref' => $metricRef, 'metric_version' => (string) $metricVersion,
        ])->first(['digest', 'unit']);
        if ($row === null || AggregationRows::text($row->digest) !== $expectedDefinitionDigest) {
            return null;
        }
        $unit = $row->unit ?? null;

        return is_string($unit) && $unit !== '' ? $unit : null;
    }

    /** @return list<FactRevision> */
    public function revisionsForWindow(WindowSelector $window, string $connectionName): array
    {
        $caller = $this->callers->current();
        if ($window->sellerRef !== $caller->sellerRef || $window->environment !== $caller->environment) {
            throw UsageProblems::error('scope_denied');
        }
        $conditions = [];
        $bindings = [];
        foreach (self::ATTRIBUTION as $dimension => $column) {
            if (! isset($window->groupKey[$dimension]) || $window->groupKey[$dimension] === '') {
                throw UsageProblems::error('attribution_scope_required');
            }
            $conditions[] = $dimension === 'periodRef'
                ? "(f.period_ref=? OR (p.semantics='gauge' AND p.carry_forward AND f.event_at<?))"
                : 'f.'.$column.'=?';
            $bindings[] = $window->groupKey[$dimension];
            if ($dimension === 'periodRef') {
                $bindings[] = self::instant($window->startMicros);
            }
        }
        $dimensions = array_diff_key($window->groupKey, self::ATTRIBUTION);
        $grouping = [];
        foreach ($dimensions as $name => $value) {
            $grouping[] = ['name' => $name, 'value' => $value];
        }
        $conditions[] = "f.body->'grouping' @> ?::jsonb";
        $bindings[] = CanonicalJson::encode($grouping);
        $conditions[] = 'f.event_at<?';
        $bindings[] = self::instant($window->endMicros);
        $where = implode(' AND ', $conditions);
        // A LEFT JOIN retains the metric/policy row for validation even when no facts exist.
        // Every revision is projected by this ONE READ COMMITTED statement; filtering revisions is done afterward.
        $rows = $this->databases->connection($connectionName)->select(
            query: "SELECT f.*,m.body AS metric_body,p.semantics,p.gauge_policy,p.quantity_scale,
                    p.identity_key_version,p.identity_namespace,p.cross_source_identity_policy,c.required_sources
                FROM public.billium_usage_metric m
                JOIN public.billium_usage_aggregation_policy p USING (seller_ref,environment,metric_ref,metric_version)
                JOIN public.billium_usage_source_finality_contract c ON c.seller_ref=p.seller_ref AND c.environment=p.environment
                    AND c.contract_ref=p.finality_contract_ref AND c.version=p.finality_contract_version
                LEFT JOIN public.billium_usage_fact f ON f.seller_ref=m.seller_ref AND f.environment=m.environment
                    AND f.metric_ref=m.metric_ref AND f.metric_version=m.metric_version AND {$where}
                WHERE m.seller_ref=? AND m.environment=? AND m.metric_ref=? AND m.metric_version=?",
            bindings: [...$bindings, $window->sellerRef, $window->environment, $window->metricRef, $window->metricVersion],
        );
        $revisions = [];
        $semantics = null;
        foreach ($rows as $row) {
            assert($row instanceof stdClass);
            // Every row repeats the one metric/policy/contract join; validate it once, not once per fact.
            $semantics ??= $this->semantics(row: $row, names: array_keys($dimensions));
            if ($row->fact_id === null) {
                continue;
            }
            $fact = $this->codec->decode(AggregationRows::text($row->body));
            assert($fact instanceof UsageFactV2);
            $observed = self::micros(AggregationRows::text($row->event_at));
            $members = [];
            if ($semantics === 'distinct_exact') {
                if ($row->identity_key_version === null || $row->identity_namespace === null
                    || $fact->provenance->version !== $row->identity_key_version) {
                    throw UsageProblems::error('identity_policy_mismatch');
                }
                foreach (AggregationRows::items($fact->value->members) as $member) {
                    $member = AggregationRows::text($member);
                    if (preg_match('/^[0-9a-f]{64}$/D', $member) !== 1) {
                        throw UsageProblems::error('invalid_member');
                    }
                    $members[] = $row->cross_source_identity_policy === 'shared' ? $member
                        : hash('sha256', CanonicalJson::encode([$row->identity_namespace, $fact->source, $member]));
                }
            }
            $revisions[] = new FactRevision(
                factId: self::identity(source: $fact->source, factId: $fact->factId),
                factRevision: $fact->revision, factDigest: $fact->digest, retracted: false,
                observedAtMicros: $observed, receivedAtMicros: self::micros(value: AggregationRows::text($row->received_at), exact: false),
                ingestSeq: AggregationRows::integer($row->ingest_seq), sourceRef: $fact->source, sourceSequence: null,
                quantityUnscaled: $semantics === 'distinct_exact' ? '0' : Decimal::unscaled(
                    value: AggregationRows::text($row->quantity), scale: AggregationRows::integer($row->quantity_scale),
                ),
                memberIds: $members,
            );
        }

        return $revisions;
    }

    /**
     * Checks the stored metric definition against its aggregation policy and finality contract.
     *
     * @param  list<string>  $names  grouping dimensions of the window
     */
    private function semantics(stdClass $row, array $names): string
    {
        $metric = $this->codec->decode(AggregationRows::text($row->metric_body));
        assert($metric instanceof MetricVersion);
        $model = AggregationRows::text($metric->model->id);
        $semantics = match ($model) {
            'additive_sum', 'additive_count' => 'additive',
            'gauge' => 'gauge',
            'exact_distinct' => 'distinct_exact',
            default => throw UsageProblems::error('metric_semantics_unsupported'),
        };
        $expected = array_map(AggregationRows::text(...), $metric->groupingDimensions);
        sort($names, SORT_STRING);
        sort($expected, SORT_STRING);
        $sources = array_map(AggregationRows::text(...), AggregationRows::items($row->required_sources));
        $metricSources = array_map(AggregationRows::text(...), AggregationRows::items($metric->sourceSelection->sources));
        sort($sources, SORT_STRING);
        sort($metricSources, SORT_STRING);
        if ($row->semantics !== $semantics || $names !== $expected || $sources !== $metricSources) {
            throw UsageProblems::error('metric_policy_mismatch');
        }
        if ($model === 'gauge') {
            $reducer = match ($metric->model->sampleReducer) {
                'latest', 'latest_sample', 'latest_as_of' => 'latest_as_of',
                'max_sample', 'max' => 'max',
                'time_weighted' => 'time_weighted',
                default => throw UsageProblems::error('metric_semantics_unsupported'),
            };
            if ($row->gauge_policy !== $reducer) {
                throw UsageProblems::error('metric_policy_mismatch');
            }
        }

        return $semantics;
    }

    public static function identity(string $source, string $factId): string
    {
        return CanonicalJson::encode([$source, $factId]);
    }

    private static function micros(string $value, bool $exact = true): int
    {
        // Ingestion retains nanoseconds. Aggregation never silently truncates a non-microsecond sample.
        if ($exact && strlen($value) > 26 && trim(substr($value, 26), '0') !== '') {
            throw UsageProblems::error('time_precision_unsupported');
        }
        $time = new DateTimeImmutable($value.'Z');

        return ((int) $time->format('U')) * 1_000_000 + (int) $time->format('u');
    }

    private static function instant(int $micros): string
    {
        $seconds = (int) IntegerRoundingAdapter::seconds($micros);
        $remainder = $micros - $seconds * 1_000_000;

        return new DateTimeImmutable('@'.$seconds)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s')
            .'.'.str_pad((string) $remainder, 6, '0', STR_PAD_LEFT).'000';
    }
}
