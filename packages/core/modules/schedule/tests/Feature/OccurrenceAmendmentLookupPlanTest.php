<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Feature;

use Billium\Schedule\Contracts\ScheduleQueries;
use Billium\Schedule\Tests\OccurrenceTestCase;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/** An occurrence read checks its revision confirmation by key, never by scanning every occurrence's amendments. */
#[Group('pg')]
final class OccurrenceAmendmentLookupPlanTest extends OccurrenceTestCase
{
    public function test_revision_confirmation_is_read_through_the_amendment_index(): void
    {
        $this->createSchedule();
        $ref = $this->occurrences()->ensureOccurrence($this->ensure())->body()['occurrenceRef'];
        self::assertIsString($ref);
        $lookup = null;
        $this->application()->make(Dispatcher::class)->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$lookup): void {
            if (str_contains($query->sql, '"billium_schedule_occurrence_amendment"')) {
                $lookup = $query;
            }
        });

        self::assertNotNull($this->application()->make(ScheduleQueries::class)->occurrence($ref));

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
        self::assertStringContainsString('"Index Name": "billium_schedule_occurrence_amendment_lookup"', $plan);
        self::assertStringNotContainsString('"Node Type": "Seq Scan"', $plan);
    }
}
