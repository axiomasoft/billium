<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit\Modules;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Enums\StrategyId;
use Billium\Rating\Internal\Strategies\FixedStrategy;
use Billium\Rating\Internal\Strategies\StrategyCatalog;
use Billium\Usage\Contracts\MetricReducer;
use Billium\Usage\Data\AggregationPolicy;
use Billium\Usage\Data\FactRevision;
use Billium\Usage\Data\MetricReduction;
use Billium\Usage\Data\WindowSelector;
use Billium\Usage\Enums\AggregationSemantics;
use Billium\Usage\Internal\Reducers\ReducerRegistry;
use Billium\Usage\Internal\Services\SnapshotBuilder;
use Billium\Usage\Tests\Support\AggregationVectors;
use PHPUnit\Framework\TestCase;

final class RoleRegistrationTest extends TestCase
{
    public function test_duplicate_strategy_role_is_refused_before_resolution(): void
    {
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessage('strategy_duplicate');
        new StrategyCatalog(strategies: [new FixedStrategy, new FixedStrategy]);
    }

    public function test_missing_historical_strategy_cannot_fall_back_to_a_current_version(): void
    {
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessage('strategy_unknown');
        new StrategyCatalog(strategies: [])->resolve(id: StrategyId::Fixed, version: 1);
    }

    public function test_duplicate_metric_role_is_refused(): void
    {
        $reducer = (new ReducerRegistry)->resolve(algorithmId: 'additive_sum', version: 1);
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessage('metric_role_duplicate');
        new ReducerRegistry(reducers: [$reducer, $reducer]);
    }

    public function test_missing_metric_version_cannot_fall_back(): void
    {
        $this->expectException(ProblemException::class);
        (new ReducerRegistry)->resolve(algorithmId: 'additive_sum', version: 2);
    }

    public function test_metric_extension_cannot_replace_distinct_union_with_summed_counts(): void
    {
        $bad = new class implements MetricReducer
        {
            public function algorithmId(): string
            {
                return 'distinct_exact_union';
            }

            public function version(): int
            {
                return 2;
            }

            public function semantics(): AggregationSemantics
            {
                return AggregationSemantics::DistinctExact;
            }

            public function reduce(AggregationPolicy $policy, WindowSelector $window, array $effective): MetricReduction
            {
                return new MetricReduction(unscaled: '2', memberSetDigest: 'sha256:'.hash('sha256', hash('sha256', 'shared')));
            }
        };
        $builder = new SnapshotBuilder(new ReducerRegistry(reducers: [$bad]));
        /** @var list<FactRevision> $revisions */
        $revisions = AggregationVectors::revisions([
            ['fact' => 'a', 't' => 1, 'memberSeeds' => ['shared']], ['fact' => 'b', 't' => 2, 'memberSeeds' => ['shared']],
        ]);
        $this->expectException(ProblemException::class);
        $this->expectExceptionMessage('metric_semantics_unsupported');
        $builder->build(policy: AggregationVectors::policy(['algorithm' => 'distinct_exact_union@2', 'scale' => 0]),
            contract: AggregationVectors::contract(), window: AggregationVectors::window(),
            revisions: $revisions, evidence: []);
    }
}
