<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Usage\Tests\AggregationTestCase;
use Billium\Usage\Tests\Support\UsageFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

#[Group('pg')]
final class AggregationMetricHistoryTest extends AggregationTestCase
{
    #[DataProvider('supportedChanges')]
    public function test_aggregation_v1_reproduces_after_a_new_metric_definition(string $field): void
    {
        $this->policy();
        $this->completeness();
        $fact = UsageFixtures::fact();
        $receipt = $this->usage()->submitUsageFact($fact);
        $first = $this->freeze();
        $storedMetric = $this->value("SELECT body::text FROM billium_usage_metric WHERE metric_version='api_calls/1'");
        $metric = UsageFixtures::metric(static function (stdClass $raw) use ($field): void {
            $raw->metricVersion = 'api_calls/2-'.$field;
            if ($field === 'kind') {
                $raw->model = (object) ['id' => 'gauge', 'version' => 1, 'revisionPolicy' => 'highest_fact_revision', 'sampleReducer' => 'latest', 'tieBreak' => 'eventAt_source_factId'];
            } elseif ($field === 'unit') {
                $raw->unit = 'byte';
            } elseif ($field === 'filter') {
                $raw->sourceSelection->filters[0]->value = 'false';
            } else {
                $raw->extraction->field = 'adjusted_quantity';
            }
        });
        self::assertNotSame($fact->metricDigest, $metric->digest);
        $this->usage()->registerMetric($metric);
        self::assertSame(2, $this->rows('billium_usage_metric'));
        self::assertSame($storedMetric, $this->value("SELECT body::text FROM billium_usage_metric WHERE metric_version='api_calls/1'"));
        self::assertEquals($receipt, $this->usage()->submitUsageFact($fact));
        $replay = $this->freeze();
        self::assertFalse($replay['created']);
        self::assertSame('12.000000001', $replay['quantity']);
        self::assertSame($first['snapshotDigest'], $replay['snapshotDigest']);
        self::assertSame(1, $this->rows('billium_usage_aggregate_snapshot'));
        self::assertSame(1, $this->rows('billium_usage_aggregate_member'));
        self::assertSame(0, $this->rows('billium_obligation'));
    }

    /** @return array<string, array{string}> */
    public static function supportedChanges(): array
    {
        return ['COV-193 aggregation kind' => ['kind'], 'COV-194 unit' => ['unit'], 'COV-195 source filter' => ['filter'], 'COV-196 extraction' => ['extraction']];
    }

    #[DataProvider('unsupportedPolicies')]
    public function test_aggregation_unknown_metric_policy_refuses_and_preserves_v1(string $policy): void
    {
        $this->policy();
        $this->completeness();
        $fact = UsageFixtures::fact();
        $this->usage()->submitUsageFact($fact);
        $first = $this->freeze();
        try {
            $metric = UsageFixtures::metric(static function (stdClass $raw) use ($policy): void {
                $raw->metricVersion = 'api_calls/2-'.$policy;
                $raw->{$policy}->version = 3;
            });
            $this->usage()->registerMetric($metric);
            self::fail('unregistered policy was accepted');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
        }
        self::assertSame(1, $this->rows('billium_usage_metric'));
        $replay = $this->freeze();
        self::assertFalse($replay['created']);
        self::assertSame('12.000000001', $replay['quantity']);
        self::assertSame($first['snapshotDigest'], $replay['snapshotDigest']);
        self::assertSame(1, $this->rows('billium_usage_aggregate_snapshot'));
        self::assertSame(0, $this->rows('billium_obligation'));
    }

    /** @return array<string, array{string}> */
    public static function unsupportedPolicies(): array
    {
        return ['COV-197 attribution' => ['attributionPolicy'], 'COV-198 correction' => ['correctionPolicy']];
    }
}
