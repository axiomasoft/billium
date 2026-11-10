<?php

declare(strict_types=1);

namespace Billium\Usage\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Usage\Internal\UsageQueries;
use Billium\Usage\Tests\Support\UsageFixtures;
use Billium\Usage\Tests\UsageTestCase;
use Illuminate\Database\DatabaseManager;
use stdClass;

final class UsageAdmissionQueryTest extends UsageTestCase
{
    public function test_keyset_pages_order_by_event_time_with_nanoseconds_and_frozen_mapping(): void
    {
        foreach ([9, 1, 5] as $n) {
            $this->usage()->submitUsageFact(UsageFixtures::fact(function (stdClass $r) use ($n): void {
                $r->factId = 'nano-'.$n;
                $r->eventAt = '2026-10-17T10:00:00.00000000'.$n.'Z';
                $r->interval->start = '2026-10-17T10:00:00.000000000Z';
                $r->interval->end = '2026-10-17T10:00:00.000000010Z';
            }));
        }
        $query = $this->application()->make(UsageQueries::class);
        $page = $query->period(subject: 'subject-1', metricVersion: 'api_calls/1', mappingRef: 'mapping-1', start: '2026-10-17T10:00:00Z', end: '2026-10-17T10:00:01Z', limit: 2);
        self::assertSame(['nano-1', 'nano-5'], array_column($page, 'fact_id'));
        $last = $page[1];
        self::assertInstanceOf(stdClass::class, $last);
        $next = $query->period(subject: 'subject-1', metricVersion: 'api_calls/1', mappingRef: 'mapping-1', start: '2026-10-17T10:00:00Z', end: '2026-10-17T10:00:01Z', limit: 2, after: ['event_at' => self::text($last->event_at), 'fact_id' => self::text($last->fact_id), 'revision' => self::integer($last->revision), 'source' => self::text($last->source)]);
        self::assertSame(['nano-9'], array_column($next, 'fact_id'));
        self::assertSame([], $query->period(subject: 'subject-1', metricVersion: 'api_calls/1', mappingRef: 'other-payer-mapping', start: '2026-10-17T10:00:00Z', end: '2026-10-17T10:00:01Z'));
        try {
            $query->period(subject: 'subject-1', metricVersion: 'api_calls/1', mappingRef: 'mapping-1', start: '2026-10-17T10:00:00Z', end: '2026-10-17T10:00:01Z', limit: 501);
            self::fail('unbounded query accepted');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }
    }

    public function test_representative_subject_window_explain_uses_the_owned_index(): void
    {
        $this->usage()->submitUsageFact(UsageFixtures::fact());
        $seedSequence = self::integer($this->value('SELECT ingest_seq FROM billium_usage_fact'));
        // Fixture-owner expansion, not an ingestion/load benchmark: 2,000 rows distributed over 100 subjects.
        $this->owner()->unprepared(<<<'SQL'
        INSERT INTO billium_usage_fact OVERRIDING USER VALUE
        SELECT (jsonb_populate_record(NULL::billium_usage_fact,
            to_jsonb(seed) || jsonb_build_object('fact_id','fixture-'||n,'subject_ref','subject-'||(n%100)))).*
        FROM billium_usage_fact seed CROSS JOIN generate_series(1,2000) n;
        ANALYZE billium_usage_fact;
        SQL);
        self::assertSame(2001, $this->rows('billium_usage_fact'));
        self::assertSame(2000, $this->rows('billium_usage_fact', "fact_id LIKE 'fixture-%' AND ingest_seq > ?", [$seedSequence]));
        self::assertSame($seedSequence, $this->value('SELECT ingest_seq FROM billium_usage_fact WHERE fact_id = ?', [UsageFixtures::fact()->factId]));
        self::assertSame(2001, $this->value('SELECT count(DISTINCT ingest_seq) FROM billium_usage_fact'));
        $db = $this->application()->make(DatabaseManager::class)->connection('pgsql');
        $db->enableQueryLog();
        $rows = $this->application()->make(UsageQueries::class)->period(subject: 'subject-1', metricVersion: 'api_calls/1', mappingRef: 'mapping-1', start: '2026-10-01T00:00:00Z', end: '2026-11-01T00:00:00Z', limit: 5);
        $log = $db->getQueryLog();
        $db->disableQueryLog();
        $query = $log[count($log) - 1];
        $plan = $db->select(query: 'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query['query'], bindings: $query['bindings']);
        $encoded = json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        self::assertStringContainsString('billium_usage_subject_window', $encoded);
        self::assertCount(5, $rows);
        file_put_contents(dirname(__DIR__, 6).'/.phpunit.cache/p4.2/usage-explain.json', $encoded);
    }
}
