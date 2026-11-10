<?php

declare(strict_types=1);

namespace Billium\Rating\Tests\Feature;

use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\Support\FixtureValues;
use Billium\Rating\Contracts\PriceVersionStore;
use Billium\Rating\Internal\Canonical\CanonicalJson;
use Billium\Rating\Tests\RatingTestCase;
use Billium\Rating\Tests\Support\RatingScenario;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Schedule\Data\Commands\ApplyScheduleRevision;
use Billium\Schedule\Data\Commands\EnsureOccurrence;
use Billium\Schedule\Enums\OccurrencePurpose;
use Billium\Schedule\Tests\Support\Terms;
use Billium\Usage\Tests\Support\UsageFixtures;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;

final class RatingBookingConcurrencyTest extends RatingTestCase
{
    /** @return iterable<string, array{string}> */
    public static function pauseCases(): iterable
    {
        yield 'same-digest' => [self::inputDigest()];
        yield 'new-digest' => ['sha256:'.str_repeat('b', 64)];
    }

    #[DataProvider('pauseCases')]
    public function test_pause_invalidates_prepared_planned_occurrences_with_the_same_interval(string $digest): void
    {
        $service = $this->application()->make(OccurrenceService::class);
        $ref = $this->occurrenceRef;
        $prepared = $this->prepared();
        $service->applyScheduleRevision(new ApplyScheduleRevision(sellerRef: 'seller-a', environment: 'test',
            scheduleRef: 'schedule-a', expectedRevision: 1, expectedRowVersion: 1, newRevision: 2,
            terms: Terms::make(['componentRef' => 'component-1', 'anchorLocal' => '2026-10-01T00:00:00',
                'purpose' => 'usage_settlement', 'action' => 'pause']),
            effectiveFrom: new DateTimeImmutable('2026-10-01Z'), inputsDigest: $digest, commandKey: 'pause'));
        self::assertSame('cancelled', $this->value('SELECT state FROM billium_schedule_billing_occurrence WHERE occurrence_ref=?', [$ref]));
        self::assertSame(1, $this->rows(table: 'billium_schedule_occurrence_amendment',
            where: 'occurrence_ref=? AND schedule_revision=2 AND kind=?', bindings: [$ref, 'cancelled']));
        try {
            $this->book($prepared);
            self::fail('Paused occurrence accepted');
        } catch (ProblemException $error) {
            self::assertSame('billium.rating.occurrence_not_bookable', $error->detail);
        }
        self::assertSame(0, array_sum($this->ratingCounts()));
    }

    public function test_a_same_interval_revision_refuses_a_previously_prepared_rating(): void
    {
        $prepared = $this->prepared();
        $this->application()->make(OccurrenceService::class)->applyScheduleRevision(new ApplyScheduleRevision(
            sellerRef: 'seller-a', environment: 'test', scheduleRef: 'schedule-a', expectedRevision: 1,
            expectedRowVersion: 1, newRevision: 2, terms: Terms::make(['componentRef' => 'component-1',
                'anchorLocal' => '2026-10-01T00:00:00', 'purpose' => 'usage_settlement', 'priceVersionRef' => 'price:2']),
            effectiveFrom: new DateTimeImmutable('2026-10-01Z'), inputsDigest: self::inputDigest(), commandKey: 'revise-same-interval'));
        self::assertSame('planned', $this->value('SELECT state FROM billium_schedule_billing_occurrence'));
        try {
            $this->book($prepared);
            self::fail('Stale schedule revision accepted');
        } catch (ProblemException $error) {
            self::assertSame('billium.rating.occurrence_not_bookable', $error->detail);
        }
        self::assertSame(0, array_sum($this->ratingCounts()));
    }

    public function test_five_workers_book_one_basis_and_one_obligation(): void
    {
        $prepared = $this->prepared();
        $results = ConcurrencyStand::run(scenario: RatingScenario::class, workers: 5, args: ['inputs' => CanonicalJson::encode($prepared->inputs)]);
        $bodies = array_map(self::ok(...), $results);
        self::assertCount(5, array_unique(array_map(self::integer(...), array_column($bodies, 'pid'))));
        $outcomes = array_count_values(array_map(self::text(...), array_column(array_column($bodies, 'body'), 'outcome')));
        self::assertSame(1, $outcomes['booked'] ?? 0);
        self::assertSame(4, $outcomes['existing'] ?? 0);
        self::assertSame(1, $this->rows('billium_rating_rated_basis'));
        self::assertSame(1, $this->rows('billium_obligation'));
    }

    public function test_competing_quotes_share_a_frontier_and_only_one_semantic_basis_wins(): void
    {
        $results = ConcurrencyStand::run(scenario: RatingScenario::class, workers: 2, args: ['inputs' => CanonicalJson::encode($this->prepared()->inputs), 'stale' => true]);
        $bodies = array_map(self::ok(...), $results);
        self::assertSame(1, count(array_filter($bodies, static fn (array $r): bool => $r['accepted'] === true)));
        self::assertSame(['billium.rating.frozen_input_stale'], array_column(array_filter($bodies, static fn (array $r): bool => ! $r['accepted']), 'detail'));
        self::assertSame(1, $this->rows('billium_obligation'));
    }

    public function test_duplicate_rating_jobs_racing_schedule_revision_never_supersede_booked_work(): void
    {
        $prepared = $this->prepared();
        $results = ConcurrencyStand::run(scenario: RatingScenario::class, workers: 5, args: ['inputs' => CanonicalJson::encode($prepared->inputs), 'revise' => true]);
        foreach ($results as $result) {
            self::ok($result);
        }
        $obligations = $this->rows('billium_obligation');
        self::assertLessThanOrEqual(1, $obligations);
        $state = $this->value('SELECT state FROM billium_schedule_billing_occurrence WHERE occurrence_ref=? ORDER BY incarnation DESC LIMIT 1', [$this->occurrenceRef]);
        self::assertSame($obligations === 1 ? 'obligated' : 'superseded', $state);
        self::assertSame($obligations, $this->rows('billium_rating_booking'));
    }

    public function test_superseded_aggregate_refuses_booking_and_leaves_no_rating_rows(): void
    {
        $prepared = $this->prepared();
        $fact = UsageFixtures::fact(static function ($fact): void {
            $fact->value->quantity = '100';
        });
        $this->usage()->submitUsageFact(UsageFixtures::correction(previous: $fact, quantity: '110'));
        try {
            $this->book($prepared);
            self::fail('superseded aggregate accepted');
        } catch (ProblemException $error) {
            self::assertSame('billium.usage.snapshot_superseded', $error->detail);
        }
        self::assertSame(0, array_sum($this->ratingCounts()));
    }

    public function test_commitment_floor_cannot_be_booked_twice_across_different_occurrences(): void
    {
        $service = $this->application()->make(OccurrenceService::class);
        $service->applyScheduleRevision(new ApplyScheduleRevision(sellerRef: 'seller-a', environment: 'test', scheduleRef: 'time-schedule', expectedRevision: 0, expectedRowVersion: 0, newRevision: 1, terms: Terms::make(['componentRef' => 'component-1', 'anchorLocal' => '2026-10-01T00:00:00', 'purpose' => 'recurring_charge']), effectiveFrom: new DateTimeImmutable('2026-01-01Z'), inputsDigest: self::inputDigest(), commandKey: 'time-schedule-revision'));
        $second = FixtureValues::text($service->ensureOccurrence(new EnsureOccurrence(sellerRef: 'seller-a', environment: 'test', componentRef: 'component-1', purpose: OccurrencePurpose::RecurringCharge, periodStart: new DateTimeImmutable('2026-10-01Z'), periodEnd: new DateTimeImmutable('2026-11-01Z'), scheduleRef: 'time-schedule', scheduleRevision: 1, inputsDigest: self::inputDigest(), commandKey: 'time-ensure'))->body()['occurrenceRef']);
        $this->application()->make(PriceVersionStore::class)->publish(price: $this->price(version: 2, fixed: '140', commitment: true), commandKey: 'commitment-price');
        $a = $this->prepared(priceVersion: 2);
        $b = $this->prepared(priceVersion: 2, ref: $second);
        self::assertSame('20000', $a->draft->netMinor);
        self::assertSame('20000', $b->draft->netMinor);
        $this->book($a);
        try {
            $this->book(prepared: $b, key: 'second-stale');
            self::fail('minimum applied twice');
        } catch (ProblemException $error) {
            self::assertSame('billium.rating.frozen_input_stale', $error->detail);
        }
        $fresh = $this->prepared(priceVersion: 2, ref: $second);
        self::assertSame('20000', $fresh->inputs->prices[0]->alreadyBilledNetMinor);
        self::assertSame('14000', $fresh->draft->netMinor);
        $this->book(prepared: $fresh, key: 'second-fresh');
        self::assertSame(34000, $this->value('SELECT sum(amount_due_minor)::bigint FROM billium_obligation'));
    }

    public function test_sigkill_at_each_financial_boundary_then_retry_leaves_one_obligation(): void
    {
        $prepared = $this->prepared();
        $root = dirname(__DIR__, 6);
        foreach (['after_basis_insert', 'after_obligation', 'before_commit', 'after_commit_before_reply'] as $point) {
            $afterCommit = $point === 'after_commit_before_reply';
            $table = match ($point) {
                'after_basis_insert' => 'billium_rating_rated_basis',
                'after_obligation' => 'billium_obligation',
                default => 'billium_rating_event',
            };
            if (! $afterCommit) {
                $this->owner()->unprepared('CREATE FUNCTION public.billium_test_rating_sleep() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN PERFORM pg_sleep(30); RETURN NEW; END $$');
                $definition = $point === 'before_commit' ? 'CREATE CONSTRAINT TRIGGER billium_test_rating_sleep AFTER INSERT ON '.$table.' DEFERRABLE INITIALLY DEFERRED' : 'CREATE TRIGGER billium_test_rating_sleep AFTER INSERT ON '.$table;
                $this->owner()->unprepared($definition.' FOR EACH ROW EXECUTE FUNCTION public.billium_test_rating_sleep()');
            }
            $process = proc_open([PHP_BINARY, $root.'/packages/core/modules/rating/tests/Support/rating-crash-worker.php', base64_encode(CanonicalJson::encode($prepared->inputs)), $point], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
            self::assertIsResource($process);
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $output = '';
            $pid = null;
            $reached = false;
            try {
                $deadline = microtime(true) + 15;
                do {
                    $output .= stream_get_contents($pipes[1]);
                    if (preg_match('/pid:([0-9]+)/', $output, $m)) {
                        $pid = (int) $m[1];
                    }
                    if ($pid !== null) {
                        $reached = $afterCommit ? str_contains($output, 'committed') : $this->value("SELECT count(*) FROM pg_stat_activity WHERE pid=? AND wait_event='PgSleep'", [$pid]) === 1;
                    }
                    if ($reached || ! proc_get_status($process)['running']) {
                        break;
                    }
                    usleep(20000);
                } while (microtime(true) < $deadline);
                self::assertTrue($reached, $point.': '.$output.stream_get_contents($pipes[2]));
                proc_terminate($process, 9);
                if (! $afterCommit) {
                    $this->owner()->select('SELECT pg_terminate_backend(?)', [$pid]);
                }
            } finally {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                if (! $afterCommit) {
                    $this->owner()->unprepared('DROP TRIGGER billium_test_rating_sleep ON '.$table.'; DROP FUNCTION public.billium_test_rating_sleep()');
                }
            }
            if (! $afterCommit) {
                self::assertSame(0, array_sum($this->ratingCounts()), $point);
            }
            // The early boundaries rolled back. Leave booking to the final lost-reply worker;
            // all four attempts use the SAME operation key and semantic basis.
        }
        $first = $this->book(prepared: $prepared, key: 'crash');
        self::assertSame('booked', $first->outcome->value);
        self::assertSame($first->body(), $this->book(prepared: $prepared, key: 'crash')->body());
        self::assertSame(1, $this->rows('billium_obligation'));
        self::assertSame(1, $this->rows('billium_rating_rated_basis'));
    }
}
