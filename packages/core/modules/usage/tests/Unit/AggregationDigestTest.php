<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Unit;

use Billium\Usage\Internal\Canonical\CanonicalJson;
use Billium\Usage\Internal\Math\Decimal;
use Billium\Usage\Internal\Reducers\ReducerRegistry;
use Billium\Usage\Internal\Services\SnapshotBuilder;
use Billium\Usage\Tests\Support\AggregationVectors as V;
use LogicException;
use PHPUnit\Framework\TestCase;

final class AggregationDigestTest extends TestCase
{
    public function test_aggregation_fixture_digest_is_pinned_after_manual_quantity_verification(): void
    {
        $vector = V::load('additive');
        self::assertNotSame('PIN_AT_FIRST_GREEN_RUN', $vector['expected']['digest'], 'fixture not pinned');
        self::assertSame($vector['expected']['digest'], V::snapshot($vector)->snapshotDigest);
    }

    public function test_aggregation_digest_ignores_arrival_and_history_and_includes_empty_scope(): void
    {
        $vector = V::load('additive');
        $one = V::snapshot($vector);
        $two = new SnapshotBuilder(new ReducerRegistry)->build(
            policy: V::policy($vector['policy']), contract: V::contract(), window: V::window(),
            revisions: V::revisions(array_reverse($vector['revisions'])),
            evidence: [V::evidence(['src' => 's1', 'seq' => 1, 'status' => 'complete', 'range' => [0, 3600], 'watermark' => 3900])],
            headRevision: 99, headDigest: 'a different predecessor',
        );
        self::assertSame($one->snapshotDigest, $two->snapshotDigest);
        $builder = new SnapshotBuilder(new ReducerRegistry);
        $evidence = [V::evidence(['src' => 's1', 'seq' => 1, 'status' => 'complete', 'range' => [0, 3600], 'watermark' => 3900])];
        $a = $builder->build(policy: V::policy($vector['policy']), contract: V::contract(), window: V::window(seller: 'a'), revisions: [], evidence: $evidence);
        $b = $builder->build(policy: V::policy($vector['policy']), contract: V::contract(), window: V::window(seller: 'b'), revisions: [], evidence: $evidence);
        self::assertNotSame($a->snapshotDigest, $b->snapshotDigest);
    }

    public function test_aggregation_decimal_and_canonical_object_shape(): void
    {
        self::assertSame('-1200', Decimal::unscaled(value: '-12.000000000', scale: 2));
        self::assertSame('-12.00', Decimal::format(unscaled: '-1200', scale: 2));
        self::assertSame('0.00', Decimal::format(unscaled: '-0', scale: 2));
        self::assertSame('{"a":{"a":1,"z":2},"z":{}}', CanonicalJson::encode(['z' => new \stdClass, 'a' => (object) ['z' => 2, 'a' => 1]]));
        $this->expectException(LogicException::class);
        CanonicalJson::encode(json_decode('{"nested":{"value":0.5}}'));
    }

    public function test_aggregation_registry_rejects_unknown_and_approximate_algorithms(): void
    {
        $this->expectExceptionMessage('billium.usage.metric_semantics_unsupported');
        (new ReducerRegistry)->get('distinct_hll@1');
    }

    public function test_aggregation_larger_replays_use_bounded_reproducible_sampling(): void
    {
        $first = iterator_to_array(V::deliveries(range(1, 7)));
        $second = iterator_to_array(V::deliveries(range(1, 7)));
        self::assertCount(200, $first);
        self::assertSame($first, $second);
        foreach ($first as $permutation) {
            sort($permutation);
            self::assertSame(range(1, 7), $permutation);
        }
    }

    public function test_aggregation_series_lock_keys_stay_bounded_and_preserve_group_identity(): void
    {
        $a = V::window(group: ['dimension' => str_repeat('a', 10000)]);
        $b = V::window(group: ['dimension' => str_repeat('b', 10000)]);
        self::assertLessThan(1000, strlen($a->seriesKey()));
        self::assertNotSame($a->seriesKey(), $b->seriesKey());
        self::assertSame($a->seriesKey(), V::window(group: $a->groupKey)->seriesKey());
    }
}
