<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Feature;

use Billium\Schedule\Contracts\ScheduleQueries;
use Billium\Schedule\Data\Commands\CancelSchedule;
use Billium\Schedule\Data\Commands\MaterializeNext;
use Billium\Schedule\Data\Commands\RecordScheduleFailure;
use Billium\Schedule\Tests\OccurrenceTestCase;
use Billium\Schedule\Tests\Support\Terms;
use DateTimeImmutable;
use Illuminate\Database\QueryException;

final class OccurrenceSchedulerTest extends OccurrenceTestCase
{
    public function test_paused_schedule_can_be_cancelled_without_reviving_future_work(): void
    {
        $this->createSchedule();
        $this->occurrences()->applyScheduleRevision($this->revision(['expectedRevision' => 1, 'expectedRowVersion' => 1,
            'newRevision' => 2, 'commandKey' => 'pause', 'terms' => Terms::make(['purpose' => 'recurring_charge', 'action' => 'pause'])]));
        $this->occurrences()->cancelSchedule(new CancelSchedule(sellerRef: 'seller-a', environment: 'test', scheduleRef: 'schedule-a',
            expectedRevision: 2, expectedRowVersion: 2, expectedCancelGeneration: 0, commandKey: 'cancel-paused'));
        self::assertSame('cancelled', $this->value('SELECT state FROM billium_schedule_billing_schedule'));
        self::assertSame(1, $this->value('SELECT cancel_generation FROM billium_schedule_billing_schedule'));
    }

    private function materialize(string $key = 'materialize', int $version = 1): MaterializeNext
    {
        return new MaterializeNext(sellerRef: 'seller-a', environment: 'test', scheduleRef: 'schedule-a',
            expectedRowVersion: $version, expectedCancelGeneration: 0, asOf: new DateTimeImmutable('2024-03-01Z'), commandKey: $key);
    }

    public function test_cursor_occurrence_and_outbox_are_atomic_at_a_period_boundary(): void
    {
        $this->createSchedule();
        $events = $this->rows('billium_schedule_occurrence_outbox');
        $this->owner()->unprepared(<<<'SQL'
            CREATE FUNCTION public.billium_test_occurrence_crash() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'period boundary crash'; END $$;
            CREATE TRIGGER billium_test_occurrence_crash BEFORE UPDATE ON public.billium_schedule_billing_schedule
              FOR EACH ROW WHEN (NEW.cursor_ordinal<>OLD.cursor_ordinal)
              EXECUTE FUNCTION public.billium_test_occurrence_crash();
            SQL);
        try {
            $this->occurrences()->materializeNext($this->materialize());
            self::fail('Fault did not fire');
        } catch (QueryException $e) {
            self::assertStringContainsString('period boundary crash', $e->getMessage());
        } finally {
            $this->owner()->unprepared('DROP TRIGGER billium_test_occurrence_crash ON public.billium_schedule_billing_schedule; DROP FUNCTION public.billium_test_occurrence_crash()');
        }
        self::assertSame(0, $this->rows('billium_schedule_billing_occurrence'));
        self::assertSame(0, $this->value('SELECT cursor_ordinal FROM billium_schedule_billing_schedule'));
        self::assertSame($events, $this->rows('billium_schedule_occurrence_outbox'));
        $receipt = $this->occurrences()->materializeNext($this->materialize());
        self::assertSame(1, $receipt->body()['cursorOrdinal']);
        self::assertSame('2024-02-29T00:00:00.000000Z', $receipt->body()['nextOccurrenceAt']);
        self::assertSame($receipt->body(), $this->occurrences()->materializeNext($this->materialize())->body());
        self::assertSame(1, $this->rows('billium_schedule_billing_occurrence'));
    }

    public function test_retry_backoff_is_deterministic_and_cancel_generation_stops_materialization(): void
    {
        $this->createSchedule();
        $result = $this->occurrences()->recordFailure(new RecordScheduleFailure(sellerRef: 'seller-a', environment: 'test',
            scheduleRef: 'schedule-a', expectedRowVersion: 1, asOf: new DateTimeImmutable('2024-03-01Z'), commandKey: 'failure'));
        self::assertSame('2024-03-01T00:02:00.000000Z', $result->body()['retryAt']);
        $queries = $this->application()->make(ScheduleQueries::class);
        self::assertSame([], $queries->dueSchedules(sellerRef: 'seller-a', asOf: new DateTimeImmutable('2024-03-01Z'))->schedules);
        $this->occurrences()->cancelSchedule(new CancelSchedule(sellerRef: 'seller-a', environment: 'test', scheduleRef: 'schedule-a',
            expectedRevision: 1, expectedRowVersion: 2, expectedCancelGeneration: 0, commandKey: 'cancel'));
        $this->refusal(fn () => $this->occurrences()->materializeNext($this->materialize()), 'schedule.not_runnable');
        self::assertSame(0, $this->rows('billium_schedule_billing_occurrence'));
        self::assertSame(1, $this->value('SELECT cancel_generation FROM billium_schedule_billing_schedule'));
    }

    public function test_due_query_is_bounded_seller_scoped_and_uses_the_due_index(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->occurrences()->applyScheduleRevision($this->revision(['scheduleRef' => 'schedule-'.$i, 'commandKey' => 'revision-'.$i,
                'terms' => Terms::make(['purpose' => 'recurring_charge', 'componentRef' => 'component-'.$i])]));
        }
        $queries = $this->application()->make(ScheduleQueries::class);
        $asOf = new DateTimeImmutable('2024-03-01Z');
        $first = $queries->dueSchedules(sellerRef: 'seller-a', asOf: $asOf, limit: 2);
        self::assertCount(2, $first->schedules);
        self::assertNotNull($first->cursor);
        $second = $queries->dueSchedules(sellerRef: 'seller-a', asOf: $asOf, limit: 2, cursor: $first->cursor);
        self::assertCount(2, $second->schedules);
        self::assertNull($second->cursor);
        self::assertSame([], array_intersect(array_map(self::integer(...), array_column($first->schedules, 'id')), array_map(self::integer(...), array_column($second->schedules, 'id'))));
        self::assertSame(4, $queries->dueStats(sellerRef: 'seller-a', asOf: $asOf)['dueCount']);
        $this->callers->as('seller-b');
        self::assertSame([], $queries->dueSchedules(sellerRef: 'seller-b', asOf: $asOf)->schedules);
        $this->refusal(fn () => $queries->dueSchedules(sellerRef: 'seller-b', asOf: $asOf, cursor: $first->cursor), 'schedule.cursor_invalid');
        $this->callers->as('seller-a');
        // Representative cold history makes the actual selective query choose its partial due index.
        $this->owner()->unprepared(<<<'SQL'
            INSERT INTO billium_schedule_billing_schedule
              (seller_ref,environment,schedule_ref,component_ref,purpose,current_revision,state,next_occurrence_at,runnable)
            SELECT 'other-seller','test','cold-'||n,'component-'||n,'recurring_charge',1,'completed',NULL,false
            FROM generate_series(1,4000) n;
            ANALYZE billium_schedule_billing_schedule;
            SQL);
        $plan = $this->value(<<<'SQL'
            EXPLAIN (FORMAT JSON) SELECT id,schedule_ref,next_occurrence_at FROM billium_schedule_billing_schedule
            WHERE seller_ref='seller-a' AND environment='test' AND state='active' AND runnable
              AND next_occurrence_at<='2024-03-01Z' AND (claim_expires_at IS NULL OR claim_expires_at<'2024-03-01Z')
              AND (retry_at IS NULL OR retry_at<='2024-03-01Z')
            ORDER BY next_occurrence_at,id LIMIT 2
            SQL);
        self::assertStringContainsString('billium_schedule_due_idx', self::text($plan));
        self::assertStringNotContainsString('Seq Scan', self::text($plan));
    }
}
