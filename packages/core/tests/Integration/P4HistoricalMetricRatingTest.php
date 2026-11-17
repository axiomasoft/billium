<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Integration;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Rating\Tests\RatingTestCase;
use Billium\Usage\Tests\Support\UsageFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

#[Group('pg')]
final class P4HistoricalMetricRatingTest extends RatingTestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function revisions(): iterable
    {
        foreach (['model', 'unit', 'filter', 'extraction', 'attribution', 'correction'] as $field) {
            yield $field => [$field, in_array($field, ['attribution', 'correction'], true)];
        }
    }

    #[DataProvider('revisions')]
    public function test_metric_revision_preserves_original_aggregate_and_rated_money(string $field, bool $unsupported): void
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
                'attribution' => $raw->attributionPolicy->version = 2,
                'correction' => $raw->correctionPolicy->version = 2,
                default => throw new \LogicException('Unknown metric revision case'),
            };
        };
        if ($unsupported) {
            try {
                $this->usage()->registerMetric(UsageFixtures::metric($change));
                self::fail('Unregistered policy version accepted');
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
                self::assertSame($metricCount, $this->rows('billium_usage_metric'));
            }
        } else {
            $this->usage()->registerMetric(UsageFixtures::metric($change));
            self::assertSame($metricCount + 1, $this->rows('billium_usage_metric'));
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
