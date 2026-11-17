<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Usage\Data\FreezeAggregate;
use Billium\Usage\Tests\AggregationTestCase;
use Billium\Usage\Tests\Support\UsageFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

#[Group('pg')]
final class AggregationMetricSemanticsTest extends AggregationTestCase
{
    #[DataProvider('additiveModels')]
    public function test_aggregation_distinguishes_sum_from_independent_fact_count(string $model, string $expected): void
    {
        $count = $model === 'additive_count';
        $metric = UsageFixtures::metric(static function (stdClass $raw) use ($model, $count): void {
            $raw->metricVersion = $model.'/1';
            $raw->model = (object) ['id' => $model, 'version' => 1];
            $raw->extraction = $count ? (object) ['kind' => 'each_fact'] : (object) ['kind' => 'decimal_field', 'field' => 'quantity'];
        });
        $this->usage()->registerMetric($metric);
        $this->policy(metric: $metric, scale: 0);
        $this->completeness();
        foreach (['10', '20'] as $quantity) {
            $this->usage()->submitUsageFact(UsageFixtures::fact(static function (stdClass $raw) use ($metric, $count, $quantity): void {
                $raw->factId = 'independent-'.$quantity;
                $raw->metricVersion = $metric->metricVersion;
                $raw->metricDigest = $metric->digest;
                $raw->value = (object) ['kind' => $count ? 'count' : 'decimal', 'quantity' => $count ? '1' : $quantity];
            }));
        }
        $snapshot = $this->aggregation()->freeze(new FreezeAggregate($this->window($metric)))->body();
        self::assertSame($expected, $snapshot['quantity']);
        self::assertSame(2, $this->rows('billium_usage_aggregate_member'));
        self::assertSame(0, $this->rows('billium_obligation'));
        self::assertSame(0, $this->rows('billium_money_commit_log'));
    }

    /** @return array<string, array{string, string}> */
    public static function additiveModels(): array
    {
        return ['sum values' => ['additive_sum', '30'], 'count independent facts' => ['additive_count', '2']];
    }

    #[DataProvider('unsupportedModels')]
    public function test_aggregation_duration_and_rate_refuse_before_any_evidence_or_money(string $model): void
    {
        try {
            $metric = UsageFixtures::metric(static function (stdClass $raw) use ($model): void {
                $raw->metricVersion = $model.'/1';
                $raw->model = (object) ['id' => $model, 'version' => 1];
            });
            $this->usage()->registerMetric($metric);
            self::fail('unsupported model was accepted');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
        }
        self::assertSame(1, $this->rows('billium_usage_metric'));
        self::assertSame(0, $this->rows('billium_usage_fact'));
        self::assertSame(0, $this->rows('billium_usage_aggregate_snapshot'));
        self::assertSame(0, $this->rows('billium_obligation'));
        self::assertSame(0, $this->rows('billium_money_commit_log'));
    }

    /** @return array<string, array{string}> */
    public static function unsupportedModels(): array
    {
        return ['COV-189 duration' => ['duration'], 'COV-190 rate' => ['rate']];
    }
}
