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

final class AggregationVectors
{
    public static function load(string $name): array
    {
        $vector = json_decode(file_get_contents(dirname(__DIR__, 6).'/fixtures/vectors/usage/aggregation/'.$name.'.json'), true, 64, JSON_THROW_ON_ERROR);

        return $vector['given']['aggregation'];
    }

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

    public static function snapshot(array $raw, ?array $events = null): AggregateSnapshot
    {
        return (new SnapshotBuilder(new ReducerRegistry))->build(
            policy: self::policy($raw['policy']), contract: self::contract(), window: self::window($raw['window']),
            revisions: self::revisions($events ?? $raw['revisions'] ?? $raw['events']),
            evidence: [self::evidence(['src' => 's1', 'seq' => 1, 'status' => 'complete', 'range' => [0, 3600], 'watermark' => 3900])],
        );
    }

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
