<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Unit;

use Billium\Usage\Data\FactRevision;
use Billium\Usage\Internal\Reducers\DistinctReducer;
use Billium\Usage\Internal\Reducers\EffectiveRevisionSelector;
use Billium\Usage\Tests\Support\AggregationVectors as V;
use PHPUnit\Framework\TestCase;

final class AggregationDistinctTest extends TestCase
{
    public function test_aggregation_distinct_union_replacement_and_shared_contributions(): void
    {
        $vector = V::load('distinct');
        foreach (['overlap', 'supersede_keeps_shared_member'] as $name) {
            $facts = EffectiveRevisionSelector::select(revisions: V::revisions($vector[$name]['facts']), startMicros: 0, endMicros: 3_600_000_000);
            $result = (new DistinctReducer)->reduce($facts);
            self::assertSame('3', $result['count']);
            if (isset($vector[$name]['expected']['members'])) {
                $expected = array_map(static fn (string $m): string => hash('sha256', $m), $vector[$name]['expected']['members']);
                sort($expected, SORT_STRING);
                self::assertSame($expected, $result['members']);
            }
        }
    }

    public function test_aggregation_distinct_retraction_removes_only_the_retracted_contribution(): void
    {
        $facts = V::load('distinct')['overlap']['facts'];
        $facts[] = ['fact' => 'A', 'rev' => 2, 'q' => '0', 't' => 10, 'retracted' => true];
        $result = (new DistinctReducer)->reduce(EffectiveRevisionSelector::select(revisions: V::revisions($facts), startMicros: 0, endMicros: 3_600_000_000));
        self::assertSame('2', $result['count']);
        self::assertContains(hash('sha256', 'm2'), $result['members']);
    }

    public function test_aggregation_distinct_rejects_raw_identity_without_echoing_it(): void
    {
        $this->expectExceptionMessage('billium.usage.invalid_member');
        (new DistinctReducer)->reduce([new FactRevision(
            factId: 'a', factRevision: 1, factDigest: 'digest', retracted: false, observedAtMicros: 0,
            receivedAtMicros: 0, ingestSeq: 1, sourceRef: 's', sourceSequence: null, quantityUnscaled: '0',
            memberIds: ['raw@example.invalid'],
        )]);
    }

    public function test_aggregation_distinct_never_accepts_a_partial_count(): void
    {
        $this->expectExceptionMessage('billium.usage.metric_semantics_unsupported');
        (new DistinctReducer)->reduce(V::revisions([['fact' => 'partial', 'q' => '42', 't' => 0]]));
    }
}
