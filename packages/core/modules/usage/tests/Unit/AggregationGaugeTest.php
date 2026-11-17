<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Unit;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Usage\Internal\Math\IntegerRounding;
use Billium\Usage\Internal\Reducers\EffectiveRevisionSelector;
use Billium\Usage\Internal\Reducers\GaugeReducer;
use Billium\Usage\Tests\Support\AggregationVectors as V;
use PHPUnit\Framework\TestCase;

final class AggregationGaugeTest extends TestCase
{
    public function test_aggregation_gauge_vectors_and_sequence_tie(): void
    {
        foreach (V::load('gauge') as $case) {
            try {
                $quantity = $this->reduce($case);
                self::assertArrayNotHasKey('error', $case['expected']);
                if (! isset($case['expected']['quantity'])) {
                    self::fail('Successful gauge fixture has no expected quantity');
                }
                self::assertSame($case['expected']['quantity'], $quantity);
                if (isset($case['variant_end_150'])) {
                    $case['window'] = $case['variant_end_150']['window'];
                    self::assertSame('7', $this->reduce($case));
                }
            } catch (ProblemException $exception) {
                if (! isset($case['expected']['error'])) {
                    throw $exception;
                }
                self::assertSame($case['expected']['error'], $exception->detail);
            }
        }
    }

    public function test_aggregation_gauge_null_sequence_uses_identity_and_zero_is_declared(): void
    {
        $case = V::load('gauge')['tie_latest'];
        $case['samples'] = [
            ['fact' => 'a', 'q' => '5', 't' => 10], ['fact' => 'b', 'q' => '7', 't' => 10],
        ];
        self::assertSame('7', $this->reduce($case));
        $case['samples'] = [];
        $case['policy']['missingInitial'] = 'declared_zero';
        self::assertSame('0', $this->reduce($case));
        $policy = V::policy($case['policy']);
        $selection = (new GaugeReducer)->samples(policy: $policy, start: 0, end: 3_600_000_000, allEffective: []);
        self::assertTrue($selection['declaredZero']);
        self::assertFalse($selection['carried']);
    }

    public function test_aggregation_time_weighted_refuses_a_missing_initial_segment_and_rounds_once(): void
    {
        foreach ([
            ['1', '2', 'half_even', '0'], ['3', '2', 'half_even', '2'],
            ['-3', '2', 'down', '-1'], ['-3', '2', 'up', '-2'], ['-1', '2', 'half_up', '-1'],
        ] as [$num, $den, $mode, $expected]) {
            self::assertSame($expected, IntegerRounding::divide(num: $num, den: $den, mode: $mode));
        }
        $case = V::load('gauge')['time_weighted'];
        $case['samples'] = [['fact' => 'after', 'q' => '6', 't' => 1800]];
        $this->expectExceptionMessage('billium.usage.gauge_missing_initial');
        $this->reduce($case);
    }

    public function test_aggregation_fact_revision_replacement_precedes_gauge_sample_reduction(): void
    {
        $case = V::load('gauge')['tie_latest'];
        $case['samples'] = [
            ['fact' => 'a', 'q' => '99', 'rev' => 1, 't' => 10],
            ['fact' => 'a', 'q' => '2', 'rev' => 2, 't' => 10],
            ['fact' => 'b', 'q' => '7', 't' => 20],
        ];
        self::assertSame('7', $this->reduce($case));
    }

    /** @param array{policy: array{algorithm: string, scale: int, missingInitial?: string, carryForward?: bool, maxCarrySeconds?: int, unitSeconds?: int, rounding?: string, allowNegative?: bool}, window: array{int, int}, samples: list<array{fact: string, t: int, q?: string, rev?: int, retracted?: bool, src?: string, seq?: int|null, memberSeeds?: list<string>}>} $case */
    private function reduce(array $case): string
    {
        $effective = EffectiveRevisionSelector::select(
            revisions: V::revisions($case['samples']), startMicros: PHP_INT_MIN, endMicros: $case['window'][1] * 1_000_000,
        );

        return (new GaugeReducer)->reduce(
            policy: V::policy($case['policy']), start: $case['window'][0] * 1_000_000,
            end: $case['window'][1] * 1_000_000, allEffective: $effective,
        );
    }
}
