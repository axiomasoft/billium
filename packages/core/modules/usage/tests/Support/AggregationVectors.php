<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Support;

use Billium\Usage\Data\AggregateSnapshot;
use Billium\Usage\Data\AggregationPolicy;
use Billium\Usage\Data\FactRevision;
use Billium\Usage\Data\SourceCompleteness;
use Billium\Usage\Data\SourceFinalityContract;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Enums\AggregationSemantics;
use Billium\Usage\Enums\CompletenessStatus;
use Billium\Usage\Enums\GaugePolicy;
use Billium\Usage\Enums\LatePolicy;
use Billium\Usage\Enums\MissingInitialPolicy;
use Billium\Usage\Enums\RoundingMode;
use Billium\Usage\Internal\Canonical\CanonicalJson;
use Billium\Usage\Internal\Reducers\ReducerRegistry;
use Billium\Usage\Internal\Services\SnapshotBuilder;

/**
 * @phpstan-type FixtureMap array{distinct: array{policy: array{algorithm: string, scale: int}, window: array{int, int}, overlap: array{facts: list<array{fact: string, memberSeeds: list<string>, rev: int, t: int}>, expected: array{quantity: string, wrongSumOfPartials: int}}, supersede_keeps_shared_member: array{facts: list<array{fact: string, memberSeeds: list<string>, rev: int, t: int}>, expected: array{quantity: string, members: list<string>}}}, additive: array{policy: array{algorithm: string, allowNegative: bool, scale: int}, window: array{int, int}, revisions: list<array{fact: string, q: string, rev: int, t: int, retracted?: bool}>, expected: array{quantity: string, membership: list<list<string|int>>, digest: string}}, gauge: array{tie_latest: array{policy: array{algorithm: string, carryForward: bool, missingInitial: string, scale: int}, window: array{int, int}, samples: list<array{fact: string, q: string, src: string, seq: int, t: int}>, expected: array{quantity: string}, variant_end_150: array{window: array{int, int}, expected: array{quantity: string}}}, carry_ok: array{policy: array{algorithm: string, carryForward: bool, maxCarrySeconds: int, missingInitial: string, scale: int}, window: array{int, int}, samples: list<array{fact: string, q: string, src: string, seq: int, t: int}>, expected: array{quantity: string}}, carry_too_old: array{policy: array{algorithm: string, carryForward: bool, maxCarrySeconds: int, missingInitial: string, scale: int}, window: array{int, int}, samples: list<array{fact: string, q: string, src: string, seq: int, t: int}>, expected: array{error: string}}, max: array{policy: array{algorithm: string, carryForward: bool, missingInitial: string, scale: int}, window: array{int, int}, samples: list<array{fact: string, q: string, src: string, seq: int, t: int}>, expected: array{quantity: string}}, time_weighted: array{policy: array{algorithm: string, carryForward: bool, missingInitial: string, scale: int, unitSeconds: int, rounding: string}, window: array{int, int}, samples: list<array{fact: string, q: string, src: string, seq: int, t: int}>, expected: array{quantity: string, valueSeconds: string}}}, 'replay-permutation': array{policy: array{algorithm: string, scale: int}, window: array{int, int}, events: list<array{fact: string, q: string, rev: int, t: int, retracted?: bool}>, permutations: string, expected: array{quantity: string, digest: string}}, completeness: array{contract: array{lateGraceSeconds: int, requiredSources: list<string>}, window: array{int, int}, cases: list<array{name: string, evidence: list<array{src: string, seq: int, status: string, range: array{int, int}, watermark: int}>|list<array{src: string, seq: int, status: string, range: array{int, int}, watermark: int, repair?: string}>|list<array{src: string, seq: int, status: string, range: array{int, int}, watermark: int, repair: string}>|list<never>, expected: array{qualified: bool}|array{qualified: bool, reason: string}|array{qualified: bool, quantityPresent: bool, repairRefs: list<string>}|array{qualified: bool, repairRefs: list<string>}}>}}
 * @phpstan-type PolicyInput array{algorithm: string, scale: int, missingInitial?: string, carryForward?: bool, maxCarrySeconds?: int, unitSeconds?: int, rounding?: string, allowNegative?: bool}
 * @phpstan-type EventInput array{fact: string, t: int, q?: string, rev?: int, retracted?: bool, src?: string, seq?: int|null, memberSeeds?: list<string>}
 * @phpstan-type EvidenceInput array{src: string, seq: int, status: string, range: array{int, int}, watermark: int, repair?: string|null}
 */
final class AggregationVectors
{
    /**
     * @template T of key-of<FixtureMap>
     *
     * @param  T  $name
     * @return FixtureMap[T]
     */
    public static function load(string $name): array
    {
        /** @var array{given: array{aggregation: FixtureMap[T]}} $vector */
        $vector = json_decode((string) file_get_contents(dirname(__DIR__, 6).'/fixtures/vectors/usage/aggregation/'.$name.'.json'), true, 64, JSON_THROW_ON_ERROR);

        return $vector['given']['aggregation'];
    }

    /** @param PolicyInput $raw */
    public static function policy(array $raw): AggregationPolicy
    {
        [$algorithm, $version] = explode('@', $raw['algorithm']);
        $gauge = str_starts_with($algorithm, 'gauge_') ? GaugePolicy::from(substr($algorithm, 6)) : null;

        return new AggregationPolicy(
            metricRef: 'metric', metricVersion: 1, metricDefinitionDigest: CanonicalJson::digest('fixture-metric'),
            semantics: $gauge !== null ? AggregationSemantics::Gauge : ($algorithm === 'distinct_exact_union' ? AggregationSemantics::DistinctExact : AggregationSemantics::Additive),
            algorithmId: $algorithm, algorithmVersion: (int) $version, gaugePolicy: $gauge,
            missingInitial: MissingInitialPolicy::from($raw['missingInitial'] ?? 'refuse'),
            carryForward: $raw['carryForward'] ?? false, maxCarrySeconds: $raw['maxCarrySeconds'] ?? null,
            timeWeightUnitSeconds: $raw['unitSeconds'] ?? null, quantityScale: $raw['scale'],
            rounding: RoundingMode::from($raw['rounding'] ?? 'half_up'), allowNegative: $raw['allowNegative'] ?? false,
            finalityContractRef: 'finality', finalityContractVersion: 1, identityKeyVersion: '1', identityNamespace: 'fixture',
        );
    }

    /** @param list<EventInput> $raw
     * @return list<FactRevision>
     */
    public static function revisions(array $raw): array
    {
        return array_map(static fn (array $r, int $i): FactRevision => new FactRevision(
            factId: $r['fact'], factRevision: $r['rev'] ?? 1, factDigest: CanonicalJson::digest($r),
            retracted: $r['retracted'] ?? false, observedAtMicros: $r['t'] * 1_000_000,
            receivedAtMicros: ($i + 1) * 1_000_000, ingestSeq: $i + 1,
            sourceRef: $r['src'] ?? 's1', sourceSequence: $r['seq'] ?? null, quantityUnscaled: $r['q'] ?? '0',
            memberIds: array_map(static fn (string $seed): string => hash('sha256', $seed), $r['memberSeeds'] ?? []),
        ), $raw, array_keys($raw));
    }

    /** @param EvidenceInput $raw */
    public static function evidence(array $raw): SourceCompleteness
    {
        $payload = [
            'sourceRef' => $raw['src'], 'evidenceSeq' => $raw['seq'], 'status' => $raw['status'],
            'rangeStartMicros' => $raw['range'][0] * 1_000_000, 'rangeEndMicros' => $raw['range'][1] * 1_000_000,
            'watermarkMicros' => $raw['watermark'] * 1_000_000, 'repairOrReplayRef' => $raw['repair'] ?? null,
        ];

        return new SourceCompleteness(
            sourceRef: $payload['sourceRef'], evidenceSeq: $payload['evidenceSeq'], status: CompletenessStatus::from($payload['status']),
            rangeStartMicros: $payload['rangeStartMicros'], rangeEndMicros: $payload['rangeEndMicros'],
            watermarkMicros: $payload['watermarkMicros'], repairOrReplayRef: $payload['repairOrReplayRef'],
            evidenceDigest: CanonicalJson::digest($payload),
        );
    }

    /** @param array{int, int} $range
     * @param  array<string, string>  $group
     */
    public static function window(array $range = [0, 3600], string $seller = 'seller-a', array $group = []): WindowSelector
    {
        return new WindowSelector(
            sellerRef: $seller, environment: 'test', metricRef: 'metric', metricVersion: '1', groupKey: $group,
            startMicros: $range[0] * 1_000_000, endMicros: $range[1] * 1_000_000,
        );
    }

    public static function contract(): SourceFinalityContract
    {
        return new SourceFinalityContract(
            contractRef: 'finality', version: 1, requiredSources: ['s1'], lateGraceMicros: 300_000_000,
            postFinalPolicy: LatePolicy::AcceptAdjust, reopenAllowed: true,
        );
    }

    /** @param array{policy: PolicyInput, window: array{int, int}, revisions?: list<EventInput>, events?: list<EventInput>} $raw
     * @param  list<EventInput>|null  $events
     */
    public static function snapshot(array $raw, ?array $events = null): AggregateSnapshot
    {
        return new SnapshotBuilder(new ReducerRegistry)->build(
            policy: self::policy($raw['policy']), contract: self::contract(), window: self::window($raw['window']),
            revisions: self::revisions($events ?? $raw['revisions'] ?? $raw['events'] ?? throw new \LogicException('Missing fixture events')),
            evidence: [self::evidence(['src' => 's1', 'seq' => 1, 'status' => 'complete', 'range' => [0, 3600], 'watermark' => 3900])],
        );
    }

    /** @template T
     * @param  list<T>  $items
     * @return \Generator<int, list<T>>
     */
    public static function permutations(array $items): \Generator
    {
        if ($items === []) {
            yield [];

            return;
        }
        foreach ($items as $i => $item) {
            $remaining = $items;
            array_splice($remaining, $i, 1);
            foreach (self::permutations($remaining) as $tail) {
                yield [$item, ...$tail];
            }
        }
    }

    /** @template T
     * @param  list<T>  $items
     * @return \Generator<int, list<T>>
     */
    public static function deliveries(array $items): \Generator
    {
        if (count($items) <= 6) {
            yield from self::permutations($items);

            return;
        }
        mt_srand(4303);
        for ($i = 0; $i < 200; $i++) {
            $permutation = $items;
            shuffle($permutation);
            yield $permutation;
        }
    }
}
