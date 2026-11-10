<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Unit;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Usage\Internal\Reducers\AdditiveReducer;
use Billium\Usage\Internal\Reducers\EffectiveRevisionSelector;
use Billium\Usage\Tests\Support\AggregationVectors as V;
use PHPUnit\Framework\TestCase;

final class AggregationAdditiveTest extends TestCase
{
    public function test_aggregation_effective_revisions_retractions_and_half_open_window(): void
    {
        $vector = V::load('additive');
        $snapshot = V::snapshot($vector);
        self::assertSame('17', $snapshot->quantity);
        self::assertSame($vector['expected']['membership'], array_map(static fn (array $m): array => [$m['factId'], $m['factRevision']], $snapshot->effectiveRevisions));
        $effective = EffectiveRevisionSelector::select(revisions: V::revisions([
            ['fact' => 'moves', 'rev' => 1, 'q' => '10', 't' => 0],
            ['fact' => 'moves', 'rev' => 2, 'q' => '12', 't' => 3600],
            ['fact' => 'start', 'q' => '3', 't' => 0],
        ]), startMicros: 0, endMicros: 3_600_000_000);
        self::assertSame('3', (new AdditiveReducer)->reduce(effective: $effective, allowNegative: false));
    }

    public function test_aggregation_conflicts_even_when_a_conflicting_old_revision_follows_a_new_one(): void
    {
        $this->expectExceptionMessage('billium.usage.fact_revision_conflict');
        EffectiveRevisionSelector::select(revisions: V::revisions([
            ['fact' => 'f', 'rev' => 1, 'q' => '1', 't' => 0],
            ['fact' => 'f', 'rev' => 2, 'q' => '2', 't' => 0],
            ['fact' => 'f', 'rev' => 1, 'q' => '99', 't' => 0],
        ]), startMicros: 0, endMicros: 100);
    }

    public function test_aggregation_negative_quantity_requires_explicit_permission(): void
    {
        $facts = V::revisions([['fact' => 'negative', 'q' => '-123456789012345678901234', 't' => 0]]);
        self::assertSame('-123456789012345678901234', (new AdditiveReducer)->reduce(effective: $facts, allowNegative: true));
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessage('billium.usage.negative_quantity');
        (new AdditiveReducer)->reduce(effective: $facts, allowNegative: false);
    }
}
