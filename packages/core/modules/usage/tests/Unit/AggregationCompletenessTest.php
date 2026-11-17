<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Unit;

use Billium\Usage\Internal\Window\WindowQualifier;
use Billium\Usage\Tests\Support\AggregationVectors as V;
use PHPUnit\Framework\TestCase;

final class AggregationCompletenessTest extends TestCase
{
    public function test_aggregation_completeness_vectors_are_source_ordered_and_never_clock_based(): void
    {
        $vector = V::load('completeness');
        foreach ($vector['cases'] as $case) {
            $evidence = array_map(V::evidence(...), $case['evidence']);
            $result = (new WindowQualifier)->qualify(
                start: 0, end: 3_600_000_000, requiredSources: ['s1'], graceMicros: 300_000_000, evidence: $evidence,
            );
            self::assertSame($case['expected']['qualified'], $result->qualified, $case['name']);
            if (isset($case['expected']['reason'])) {
                self::assertContains($case['expected']['reason'], $result->reasons);
            }
            self::assertSame($case['expected']['repairRefs'] ?? [], $result->repairRefs);
            self::assertEquals($result, (new WindowQualifier)->qualify(
                start: 0, end: 3_600_000_000, requiredSources: ['s1'], graceMicros: 300_000_000, evidence: array_reverse($evidence),
            ));
        }
    }

    public function test_aggregation_newer_partial_gap_overrides_older_complete_coverage(): void
    {
        $rows = [
            ['src' => 's1', 'seq' => 1, 'status' => 'complete', 'range' => [0, 3600], 'watermark' => 3900],
            ['src' => 's1', 'seq' => 2, 'status' => 'gap', 'range' => [100, 200], 'watermark' => 4000, 'repair' => 'repair://gap'],
            ['src' => 's1', 'seq' => 3, 'status' => 'complete', 'range' => [100, 150], 'watermark' => 4000],
        ];
        $result = (new WindowQualifier)->qualify(start: 0, end: 3_600_000_000, requiredSources: ['s1'], graceMicros: 300_000_000, evidence: array_map(V::evidence(...), $rows));
        self::assertFalse($result->qualified);
        self::assertSame(['repair://gap'], $result->repairRefs);
        self::assertContains('s1:gap@150000000-200000000', $result->reasons);
    }

    public function test_aggregation_empty_sources_are_not_a_complete_zero(): void
    {
        $this->expectExceptionMessage('billium.usage.policy_unknown');
        (new WindowQualifier)->qualify(start: 0, end: 10, requiredSources: [], graceMicros: 0, evidence: []);
    }
}
