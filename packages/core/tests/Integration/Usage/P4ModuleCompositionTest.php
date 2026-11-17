<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Integration\Usage;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\UsageFactV2;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\Extensions\SumMetricV2;
use Billium\Usage\Contracts\MetricReducerRegistry;
use Billium\Usage\Contracts\UsageAttribution;
use Billium\Usage\Data\FreezeAggregate;
use Billium\Usage\Internal\Reducers\ReducerRegistry;
use Billium\Usage\Tests\AggregationTestCase;
use Billium\Usage\Tests\Support\UsageFixtures;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class P4ModuleCompositionTest extends AggregationTestCase
{
    public function test_two_source_adapters_produce_the_same_normalized_fact_and_snapshot(): void
    {
        $this->policy();
        $this->completeness();
        // External adapters can consume different transports, but emit exactly Billium's immutable contract.
        $codec = new Codec;
        $adapterA = static fn (): UsageFactV2 => UsageFixtures::fact();
        $adapterB = static function () use ($codec): UsageFactV2 {
            $body = json_decode($codec->encode(UsageFixtures::fact()), true, 64, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            $body = array_reverse($body, preserve_keys: true);
            $fact = $codec->decode(json_encode($body, JSON_THROW_ON_ERROR));
            self::assertInstanceOf(UsageFactV2::class, $fact);

            return $fact;
        };
        $before = $this->owner()->table('migrations')->pluck('migration')->all();
        $firstFact = $adapterA();
        $firstReceipt = $this->usage()->submitUsageFact($firstFact);
        $firstSnapshot = $this->queries()->snapshot(self::text($this->freeze()['snapshotRef']));
        $this->application()->instance(UsageAttribution::class, new UsageFixtures);
        $secondFact = $adapterB();
        self::assertSame($codec->encode($firstFact), $codec->encode($secondFact));
        self::assertEquals($firstReceipt, $this->usage()->submitUsageFact($secondFact));
        self::assertEquals($firstSnapshot, $this->queries()->snapshot(self::text($this->freeze()['snapshotRef'])));
        self::assertSame(1, $this->rows('billium_usage_fact'));
        self::assertSame($before, $this->owner()->table('migrations')->pluck('migration')->all());
        self::assertSame(0, $this->rows('billium_obligation'));
    }

    public function test_metric_extension_negotiates_immutable_input_and_algorithm_versions(): void
    {
        $this->application()->singleton(SumMetricV2::class);
        $this->application()->tag(SumMetricV2::class, 'billium.metric_reducers');
        $metric = UsageFixtures::metric(static function ($metric): void {
            $metric->metricVersion = 'host-calls/2';
        });
        $this->usage()->registerMetric($metric);
        $this->policy(metric: $metric, algorithmVersion: 2);
        $this->completeness();
        $fact = UsageFixtures::fact(static function ($fact) use ($metric): void {
            $fact->metricVersion = $metric->metricVersion;
            $fact->metricDigest = $metric->digest;
        });
        $this->usage()->submitUsageFact($fact);
        $before = $this->owner()->table('migrations')->pluck('migration')->all();
        $receipt = $this->aggregation()->freeze(new FreezeAggregate(window: $this->window($metric)));
        $snapshot = $this->queries()->snapshot(self::text($receipt->body()['snapshotRef']));
        self::assertSame(2, $snapshot['algorithmVersion']);
        self::assertIsArray($snapshot['window']);
        self::assertSame('host-calls/2', $snapshot['window']['metricVersion']);
        self::assertSame('12.000000001', $snapshot['quantity']);
        self::assertSame(1, $this->application()->make(MetricReducerRegistry::class)->resolve(algorithmId: 'additive_sum', version: 1)->version());
        self::assertSame($before, $this->owner()->table('migrations')->pluck('migration')->all());
        self::assertSame(0, $this->rows('billium_obligation'));
    }

    public function test_missing_metric_implementation_rolls_back_before_snapshot_or_money(): void
    {
        $this->policy();
        $this->completeness();
        $this->usage()->submitUsageFact(UsageFixtures::fact());
        $this->application()->instance(MetricReducerRegistry::class, new ReducerRegistry(reducers: []));
        try {
            $this->freeze();
            self::fail('missing metric role accepted');
        } catch (ProblemException $error) {
            self::assertStringContainsString('metric_semantics_unsupported', $error->detail);
        }
        self::assertSame(0, $this->rows('billium_usage_aggregate_snapshot'));
        self::assertSame(0, $this->rows('billium_usage_aggregate_series_head'));
        self::assertSame(0, $this->rows('billium_obligation'));
    }
}
