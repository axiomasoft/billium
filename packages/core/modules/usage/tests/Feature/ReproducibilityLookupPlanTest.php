<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Feature;

use Billium\Usage\Tests\AggregationTestCase;
use Billium\Usage\Tests\Support\UsageFixtures;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/** A snapshot read looks up its own reproducibility events by key, never by scanning every snapshot's events. */
#[Group('pg')]
final class ReproducibilityLookupPlanTest extends AggregationTestCase
{
    public function test_snapshot_reproducibility_is_read_through_the_snapshot_index(): void
    {
        $this->policy();
        $this->completeness();
        $this->usage()->submitUsageFact(UsageFixtures::fact());
        $ref = self::text($this->freeze()['snapshotRef']);
        $lookup = null;
        $this->application()->make(Dispatcher::class)->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$lookup): void {
            if (str_contains($query->sql, '"billium_usage_aggregate_reproducibility_event"')) {
                $lookup = $query;
            }
        });

        $this->queries()->snapshot($ref);

        self::assertInstanceOf(QueryExecuted::class, $lookup);
        $owner = $this->owner();
        $owner->beginTransaction();
        try {
            // The fixture table is tiny; forbidding the sequential scan shows whether a lookup index exists at all.
            $owner->statement('SET LOCAL enable_seqscan = off');
            $explain = $owner->selectOne('EXPLAIN (FORMAT JSON) '.$lookup->sql, $lookup->bindings);
        } finally {
            $owner->rollBack();
        }
        self::assertInstanceOf(stdClass::class, $explain);
        $plan = self::text($explain->{'QUERY PLAN'});
        self::assertStringContainsString('"Index Name": "billium_usage_aggregate_reproducibility_event_snapshot"', $plan);
        self::assertStringNotContainsString('"Node Type": "Seq Scan"', $plan);
    }
}
