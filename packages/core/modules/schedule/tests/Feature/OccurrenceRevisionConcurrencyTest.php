<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Feature;

use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Schedule\Data\Commands\MaterializeNext;
use Billium\Schedule\Tests\OccurrenceTestCase;
use Billium\Schedule\Tests\Support\OccurrenceScenario;
use DateTimeImmutable;

final class OccurrenceRevisionConcurrencyTest extends OccurrenceTestCase
{
    public function test_five_postgresql_workers_materialize_one_business_occurrence(): void
    {
        $this->createSchedule();
        $results = ConcurrencyStand::run(scenario: OccurrenceScenario::class, workers: 5, args: ['kind' => 'ensure']);
        $bodies = array_map(self::ok(...), $results);
        self::assertCount(5, array_unique(array_map(self::integer(...), array_column($bodies, 'pid'))));
        self::assertSame(1, count(array_filter($bodies, static fn (array $b): bool => (self::arr($b['body'])['created'] ?? false) === true)));
        self::assertSame(4, count(array_filter($bodies, static fn (array $b): bool => (self::arr($b['body'])['created'] ?? null) === false)));
        self::assertSame(1, $this->rows('billium_schedule_billing_occurrence'));
    }

    public function test_two_revisions_have_one_cas_winner(): void
    {
        $this->createSchedule();
        $this->occurrences()->ensureOccurrence($this->ensure());
        $results = ConcurrencyStand::run(scenario: OccurrenceScenario::class, workers: 5, args: ['kind' => 'revision']);
        $bodies = array_map(self::ok(...), $results);
        self::assertSame(1, count(array_filter($bodies, static fn (array $b): bool => $b['accepted'] === true)));
        self::assertSame(4, count(array_filter($bodies, static fn (array $b): bool => ($b['detail'] ?? null) === 'billium.schedule.revision_stale')));
        self::assertSame(1, $this->rows('billium_schedule_billing_occurrence', "state='planned'"));
        self::assertSame(1, $this->rows('billium_schedule_billing_occurrence', "state='superseded'"));
    }

    public function test_cancel_revision_and_materialize_share_the_same_cas_boundary(): void
    {
        $this->createSchedule();
        $results = ConcurrencyStand::run(scenario: OccurrenceScenario::class, workers: 5, args: ['kind' => 'race']);
        $bodies = array_map(self::ok(...), $results);
        self::assertSame(1, count(array_filter($bodies, static fn (array $b): bool => $b['accepted'] === true)));
        foreach ($bodies as $body) {
            if ($body['accepted'] === false) {
                self::assertContains($body['detail'], ['billium.schedule.revision_stale', 'billium.schedule.not_runnable']);
            }
        }
        self::assertSame(2, $this->value('SELECT row_version FROM billium_schedule_billing_schedule'));
        self::assertSame(0, $this->value(<<<'SQL'
            SELECT count(*) FROM billium_schedule_billing_occurrence a JOIN billium_schedule_billing_occurrence b
              ON a.id<b.id AND a.seller_ref=b.seller_ref AND a.environment=b.environment AND a.component_ref=b.component_ref
              AND a.coverage_class=b.coverage_class AND a.service_period && b.service_period
              WHERE a.state IN ('planned','rated','obligated') AND b.state IN ('planned','rated','obligated')
            SQL));
    }

    public function test_five_jobs_advance_the_scheduler_cursor_once(): void
    {
        $this->createSchedule();
        $results = ConcurrencyStand::run(scenario: OccurrenceScenario::class, workers: 5, args: ['kind' => 'materialize']);
        $bodies = array_map(self::ok(...), $results);
        self::assertSame(1, count(array_filter($bodies, static fn (array $b): bool => $b['accepted'] === true)));
        self::assertSame(1, $this->value('SELECT cursor_ordinal FROM billium_schedule_billing_schedule'));
        self::assertSame(1, $this->rows('billium_schedule_billing_occurrence'));
    }

    public function test_sigkill_at_period_boundary_rolls_back_and_restart_materializes_once(): void
    {
        $this->createSchedule();
        $this->owner()->unprepared(<<<'SQL'
            CREATE FUNCTION public.billium_test_occurrence_sleep() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN PERFORM pg_sleep(30); RETURN NEW; END $$;
            CREATE TRIGGER billium_test_occurrence_sleep AFTER INSERT ON public.billium_schedule_billing_occurrence
              FOR EACH ROW EXECUTE FUNCTION public.billium_test_occurrence_sleep();
            SQL);
        $root = dirname(__DIR__, 6);
        $process = proc_open([PHP_BINARY, $root.'/packages/core/modules/schedule/tests/Support/occurrence-crash-worker.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($process);
        $pid = null;
        try {
            $deadline = microtime(true) + 15;
            do {
                $pid = $this->value(<<<'SQL'
                    SELECT pid FROM pg_stat_activity WHERE usename='billium_runtime' AND wait_event='PgSleep'
                      AND query LIKE '%INSERT INTO public.billium_schedule_billing_occurrence%'
                    SQL);
                if ($pid !== null) {
                    break;
                }
                if (! proc_get_status($process)['running'] || microtime(true) >= $deadline) {
                    self::fail('Crash worker did not reach period boundary: '.stream_get_contents($pipes[2]));
                }
                usleep(20_000);
            } while (true);
            proc_terminate($process, 9);
            $this->owner()->selectOne('SELECT pg_terminate_backend(?)', [$pid]);
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            $this->owner()->unprepared('DROP TRIGGER billium_test_occurrence_sleep ON public.billium_schedule_billing_occurrence; DROP FUNCTION public.billium_test_occurrence_sleep()');
        }
        self::assertSame(0, $this->rows('billium_schedule_billing_occurrence'));
        self::assertSame(0, $this->value('SELECT cursor_ordinal FROM billium_schedule_billing_schedule'));
        $command = new MaterializeNext(sellerRef: 'seller-a', environment: 'test', scheduleRef: 'schedule-a',
            expectedRowVersion: 1, expectedCancelGeneration: 0, asOf: new DateTimeImmutable('2024-03-01Z'), commandKey: 'crash-restart');
        $this->occurrences()->materializeNext($command);
        $this->occurrences()->materializeNext($command);
        self::assertSame(1, $this->rows('billium_schedule_billing_occurrence'));
        self::assertSame(1, $this->value('SELECT cursor_ordinal FROM billium_schedule_billing_schedule'));
    }
}
