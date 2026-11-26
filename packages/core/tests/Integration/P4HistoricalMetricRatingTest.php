<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Integration;

use Billium\Rating\Tests\RatingTestCase;
use Billium\Usage\Tests\Support\UsageFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

#[Group('pg')]
final class P4HistoricalMetricRatingTest extends RatingTestCase
{
    /** @return iterable<string, array{string}> */
    public static function revisions(): iterable
    {
        foreach (['model', 'unit', 'filter', 'extraction', 'attribution', 'correction'] as $field) {
            yield $field => [$field];
        }
    }

    #[DataProvider('revisions')]
    public function test_metric_revision_preserves_original_aggregate_and_rated_money(string $field): void
    {
        $original = $this->prepared();
        self::assertSame('10000', $original->draft->grossMinor);
        $snapshot = $this->freeze();
        $metricCount = $this->rows('billium_usage_metric');
        $change = static function (stdClass $raw) use ($field): void {
            /** @var stdClass&object{sourceSelection: stdClass&object{filters: list<stdClass>}, extraction: stdClass, attributionPolicy: stdClass, correctionPolicy: stdClass} $raw */
            $raw->metricVersion = 'api_calls/2-'.$field;
            match ($field) {
                'model' => $raw->model = (object) ['id' => 'gauge', 'version' => 1,
                    'revisionPolicy' => 'highest_fact_revision', 'sampleReducer' => 'latest', 'tieBreak' => 'eventAt_source_factId'],
                'unit' => $raw->unit = 'byte',
                'filter' => $raw->sourceSelection->filters[0]->value = 'false',
                'extraction' => $raw->extraction->field = 'adjusted_quantity',
                'attribution' => $raw->attributionPolicy = (object) ['id' => 'immutable_host_snapshot', 'version' => 2,
                    'mappingRef' => 'mapping-1', 'attribution' => UsageFixtures::fact()->attribution],
                'correction' => $raw->correctionPolicy = (object) ['id' => 'replace_same_fact_revision', 'version' => 2, 'maxRevision' => 2],
                default => throw new \LogicException('Unknown metric revision case'),
            };
        };
        $metric = UsageFixtures::metric($change);
        $this->usage()->registerMetric($metric);
        self::assertSame($metricCount + 1, $this->rows('billium_usage_metric'));
        if (in_array($field, ['attribution', 'correction'], true)) {
            $fact = UsageFixtures::fact(static function ($raw) use ($metric): void {
                $raw->factId = 'new-policy-fact';
                $raw->metricVersion = $metric->metricVersion;
                $raw->metricDigest = $metric->digest;
                $raw->value->quantity = '7';
            });
            $accepted = $this->usage()->submitUsageFact($fact);
            self::assertSame('accepted', $accepted->result);
            self::assertEquals($accepted, $this->usage()->submitUsageFact($fact));
            self::assertSame(2, $this->rows('billium_usage_fact'));
        }
        self::assertSame($snapshot, $this->freeze());
        $reproduced = $this->prepared();
        self::assertSame($original->draft->ratedDigest, $reproduced->draft->ratedDigest);
        self::assertSame('10000', $reproduced->draft->grossMinor);
        $receipt = $this->book($original);
        $before = $this->ratingCounts();
        self::assertSame($receipt->body(), $this->book($reproduced)->body());
        self::assertSame($before, $this->ratingCounts());
    }
}
