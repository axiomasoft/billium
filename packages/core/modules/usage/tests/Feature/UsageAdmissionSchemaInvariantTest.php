<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Feature;

use Billium\Usage\Tests\AggregationTestCase;
use Billium\Usage\Tests\Support\UsageFixtures;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

#[Group('pg')]
final class UsageAdmissionSchemaInvariantTest extends AggregationTestCase
{
    public function test_usage_admission_metric_semantics_constraints_validate_historical_rows(): void
    {
        $constraints = $this->owner()->select("SELECT conname, convalidated FROM pg_constraint WHERE conname IN (
            'billium_usage_metric_model_ck','billium_usage_metric_model_body_ck',
            'billium_usage_policy_metric_semantics_fk','billium_usage_snapshot_policy_algorithm_fk') ORDER BY conname");
        self::assertCount(4, $constraints);
        foreach ($constraints as $constraint) {
            self::assertTrue($constraint->convalidated);
        }
        self::assertSame('additive', $this->value('SELECT aggregation_semantics FROM billium_usage_metric'));
        self::assertSame('additive_sum', $this->value('SELECT aggregation_algorithm FROM billium_usage_metric'));
    }

    public function test_usage_admission_raw_sql_policy_cannot_disagree_with_metric_semantics(): void
    {
        $metric = UsageFixtures::metric(static function (stdClass $raw): void {
            $raw->metricVersion = 'gauge/1';
            $raw->model = (object) ['id' => 'gauge', 'version' => 1, 'revisionPolicy' => 'highest_fact_revision', 'sampleReducer' => 'latest', 'tieBreak' => 'eventAt_source_factId'];
        });
        $this->usage()->registerMetric($metric);
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        $this->policy(metric: $metric, algorithm: 'additive_sum');
    }

    public function test_usage_admission_raw_sql_policy_cannot_bind_another_metric_digest(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        $this->policy(definitionDigest: 'sha256:'.str_repeat('0', 64));
    }

    public function test_usage_admission_raw_sql_policy_cannot_replace_the_registered_gauge_reducer(): void
    {
        $metric = UsageFixtures::metric(static function (stdClass $raw): void {
            $raw->metricVersion = 'gauge/1';
            $raw->model = (object) ['id' => 'gauge', 'version' => 1, 'revisionPolicy' => 'highest_fact_revision', 'sampleReducer' => 'latest', 'tieBreak' => 'eventAt_source_factId'];
        });
        $this->usage()->registerMetric($metric);
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        $this->policy(metric: $metric, algorithm: 'gauge_max');
    }

    #[DataProvider('invalidModels')]
    public function test_usage_admission_raw_sql_cannot_change_metric_semantics(string $model, string $sqlState): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionCode($sqlState);
        $this->owner()->statement(
            'INSERT INTO billium_usage_metric
                (seller_ref,environment,metric_ref,metric_version,digest,unit,model,commercial_basis_ref,mode,body)
                SELECT seller_ref,environment,metric_ref,?,digest,unit,?,commercial_basis_ref,mode,body
                FROM billium_usage_metric LIMIT 1',
            ['raw-sql/2', $model],
        );
    }

    /** @return array<string, array{string, string}> */
    public static function invalidModels(): array
    {
        return ['unregistered model' => ['rate', '23502'], 'body disagrees with column' => ['additive_count', '23514']];
    }

    #[DataProvider('invalidSnapshotAlgorithms')]
    public function test_usage_admission_raw_sql_snapshot_must_use_its_registered_algorithm(string $algorithm, int $version): void
    {
        $this->policy();
        $this->completeness();
        $this->usage()->submitUsageFact(UsageFixtures::fact());
        $this->freeze();
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23503');
        $this->owner()->statement(
            'INSERT INTO billium_usage_aggregate_snapshot OVERRIDING USER VALUE
                SELECT (jsonb_populate_record(NULL::billium_usage_aggregate_snapshot,
                    to_jsonb(s) || ?::jsonb)).* FROM billium_usage_aggregate_snapshot s LIMIT 1',
            [json_encode([
                'snapshot_ref' => 'raw-sql-snapshot', 'snapshot_digest' => 'sha256:'.str_repeat('a', 64),
                'snapshot_revision' => 2, 'predecessor_digest' => 'sha256:'.str_repeat('b', 64),
                'algorithm_id' => $algorithm, 'algorithm_version' => $version,
            ], JSON_THROW_ON_ERROR)],
        );
    }

    /** @return array<string, array{string, int}> */
    public static function invalidSnapshotAlgorithms(): array
    {
        return ['algorithm id' => ['gauge_max', 1], 'algorithm version' => ['additive_sum', 2]];
    }
}
