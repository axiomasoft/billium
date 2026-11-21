<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests;

use Billium\Schedule\Data\Commands\MaterializeNext;
use Billium\Schedule\Data\PeriodPolicy;
use Billium\Schedule\Tests\Support\Terms;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class HardeningOccurrenceServiceTest extends OccurrenceTestCase
{
    public function test_resume_after_pause_bills_the_already_due_period_on_the_calendar_boundary(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Europe/Berlin');
        try {
            $policy = new PeriodPolicy(timezone: 'Europe/Berlin', frequency: 'month');
            $terms = static fn (string $action): array => ['purpose' => 'recurring_charge', 'action' => $action,
                'anchorLocal' => '2024-01-31T02:30:00', 'policy' => $policy];
            $this->occurrences()->applyScheduleRevision($this->revision(['terms' => Terms::make($terms('apply'))]));
            $this->occurrences()->applyScheduleRevision($this->revision([
                'expectedRevision' => 1, 'expectedRowVersion' => 1, 'newRevision' => 2, 'commandKey' => 'pause-at-gap',
                'effectiveFrom' => new DateTimeImmutable('2024-03-31T01:30:00Z'), 'inputsDigest' => 'sha256:'.str_repeat('b', 64),
                'terms' => Terms::make($terms('pause'))]));
            $january = $this->occurrences()->materializeNext($this->materialize(key: 'january', version: 2))->body();
            self::assertSame('2024-01-31T01:30:00.000000Z', $january['periodStart']);
            self::assertSame('2024-02-29T01:30:00.000000Z', $january['periodEnd']);
            $february = $this->occurrences()->materializeNext($this->materialize(key: 'february', version: 3))->body();
            self::assertSame('2024-02-29T01:30:00.000000Z', $february['periodStart']);
            self::assertSame('paused', $this->value('SELECT state FROM billium_schedule_billing_schedule'));
            self::assertSame(2, $this->value('SELECT cursor_ordinal FROM billium_schedule_billing_schedule'));
            // April 30 is already due. Floating +1 month from the March gap lands on May 1 instead.
            $this->occurrences()->applyScheduleRevision($this->revision([
                'expectedRevision' => 2, 'expectedRowVersion' => 4, 'newRevision' => 3, 'commandKey' => 'resume-april',
                'effectiveFrom' => new DateTimeImmutable('2024-04-30T00:30:00Z'), 'inputsDigest' => 'sha256:'.str_repeat('c', 64),
                'terms' => Terms::make($terms('resume'))]));
            self::assertSame('active', $this->value('SELECT state FROM billium_schedule_billing_schedule'));
            self::assertTrue((bool) $this->value('SELECT runnable FROM billium_schedule_billing_schedule'));
            self::assertSame(3, $this->value('SELECT cursor_ordinal FROM billium_schedule_billing_schedule'));
            self::assertSame('2024-04-30T00:30:00Z', $this->utc($this->value('SELECT next_occurrence_at FROM billium_schedule_billing_schedule')));
            $april = $this->occurrences()->materializeNext($this->materialize(key: 'april', version: 5))->body();
            self::assertTrue($april['created']);
            self::assertSame('2024-04-30T00:30:00.000000Z', $april['periodStart']);
            self::assertSame('2024-05-31T00:30:00.000000Z', $april['periodEnd']);
            self::assertSame(0, $this->rows('billium_schedule_billing_occurrence', "state IN ('planned','rated','obligated') AND period_start=?", ['2024-03-31T01:30:00Z']));
            self::assertSame(1, $this->rows('billium_schedule_billing_occurrence', "state IN ('planned','rated','obligated') AND period_start=?", ['2024-04-30T00:30:00Z']));
            self::assertSame(3, $this->rows('billium_schedule_billing_occurrence', "state IN ('planned','rated','obligated')"));
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_resume_materializes_an_already_obligated_period_once_and_advances_the_cursor(): void
    {
        $this->createSchedule();
        $april = $this->occurrences()->ensureOccurrence($this->ensure([
            'periodStart' => new DateTimeImmutable('2024-04-30Z'), 'periodEnd' => new DateTimeImmutable('2024-05-31Z'),
            'ordinal' => 3, 'commandKey' => 'issued-april',
        ]))->body();
        $this->advance(self::text($april['occurrenceRef']));
        $this->occurrences()->applyScheduleRevision($this->revision([
            'expectedRevision' => 1, 'expectedRowVersion' => 1, 'newRevision' => 2, 'commandKey' => 'pause-march',
            'effectiveFrom' => new DateTimeImmutable('2024-03-31Z'), 'inputsDigest' => 'sha256:'.str_repeat('b', 64),
            'terms' => Terms::make(['purpose' => 'recurring_charge', 'action' => 'pause']),
        ]));
        $this->occurrences()->materializeNext($this->materialize(key: 'january', version: 2));
        $this->occurrences()->materializeNext($this->materialize(key: 'february', version: 3));

        $this->occurrences()->applyScheduleRevision($this->revision([
            'expectedRevision' => 2, 'expectedRowVersion' => 4, 'newRevision' => 3, 'commandKey' => 'resume-april',
            'effectiveFrom' => new DateTimeImmutable('2024-04-30Z'), 'inputsDigest' => 'sha256:'.str_repeat('c', 64),
            'terms' => Terms::make(['purpose' => 'recurring_charge', 'action' => 'resume']),
        ]));
        $materialized = $this->occurrences()->materializeNext($this->materialize(key: 'april', version: 5))->body();
        self::assertFalse($materialized['created']);
        self::assertSame($april['occurrenceRef'], $materialized['occurrenceRef']);
        self::assertSame($april['inputsDigest'], $materialized['inputsDigest']);
        self::assertSame('2024-05-31T00:00:00.000000Z', $materialized['nextOccurrenceAt']);
        self::assertSame(4, $this->value('SELECT cursor_ordinal FROM billium_schedule_billing_schedule'));
        self::assertSame(1, $this->rows('billium_schedule_billing_occurrence', 'occurrence_ref = ?', [$april['occurrenceRef']]));
        self::assertSame(1, $this->rows('billium_schedule_billing_occurrence_amendment',
            "occurrence_ref = ? AND schedule_revision = 3 AND kind = 'amendment_required'", [$april['occurrenceRef']]));
    }

    public function test_resume_keeps_the_cursor_parked_across_an_intervening_calendar_change(): void
    {
        $this->createSchedule();
        $this->occurrences()->applyScheduleRevision($this->revision([
            'expectedRevision' => 1, 'expectedRowVersion' => 1, 'newRevision' => 2, 'commandKey' => 'pause-march',
            'effectiveFrom' => new DateTimeImmutable('2024-03-31Z'), 'inputsDigest' => 'sha256:'.str_repeat('b', 64),
            'terms' => Terms::make(['purpose' => 'recurring_charge', 'action' => 'pause']),
        ]));
        $this->occurrences()->materializeNext($this->materialize(key: 'january', version: 2));
        $this->occurrences()->materializeNext($this->materialize(key: 'february', version: 3));
        $this->occurrences()->applyScheduleRevision($this->revision([
            'expectedRevision' => 2, 'expectedRowVersion' => 4, 'newRevision' => 3, 'commandKey' => 'change-calendar',
            'effectiveFrom' => new DateTimeImmutable('2024-04-30Z'), 'inputsDigest' => 'sha256:'.str_repeat('c', 64),
            'terms' => Terms::make(['purpose' => 'recurring_charge', 'anchorLocal' => '2024-02-15T00:00:00']),
        ]));

        $this->occurrences()->applyScheduleRevision($this->revision([
            'expectedRevision' => 3, 'expectedRowVersion' => 5, 'newRevision' => 4, 'commandKey' => 'resume-original-calendar',
            'effectiveFrom' => new DateTimeImmutable('2024-06-30Z'), 'inputsDigest' => 'sha256:'.str_repeat('d', 64),
            'terms' => Terms::make(['purpose' => 'recurring_charge', 'action' => 'resume']),
        ]));
        self::assertSame('paused', $this->value('SELECT state FROM billium_schedule_billing_schedule'));
        self::assertSame(2, $this->value('SELECT cursor_ordinal FROM billium_schedule_billing_schedule'));
        self::assertNull($this->value('SELECT next_occurrence_at FROM billium_schedule_billing_schedule'));
    }

    #[DataProvider('resumeCalendars')]
    public function test_resume_uses_the_configured_calendar(
        PeriodPolicy $policy, string $anchor, string $pauseAt, string $resumeAt, string $nextAt, int $ordinal,
    ): void {
        $terms = static fn (string $action): array => ['purpose' => 'recurring_charge', 'action' => $action,
            'anchorLocal' => $anchor, 'policy' => $policy];
        $this->occurrences()->applyScheduleRevision($this->revision([
            'effectiveFrom' => new DateTimeImmutable($pauseAt), 'terms' => Terms::make($terms('apply')),
        ]));
        $this->occurrences()->applyScheduleRevision($this->revision([
            'expectedRevision' => 1, 'expectedRowVersion' => 1, 'newRevision' => 2, 'commandKey' => 'pause-calendar',
            'effectiveFrom' => new DateTimeImmutable($pauseAt), 'inputsDigest' => 'sha256:'.str_repeat('b', 64),
            'terms' => Terms::make($terms('pause')),
        ]));
        $this->occurrences()->applyScheduleRevision($this->revision([
            'expectedRevision' => 2, 'expectedRowVersion' => 2, 'newRevision' => 3, 'commandKey' => 'resume-calendar',
            'effectiveFrom' => new DateTimeImmutable($resumeAt), 'inputsDigest' => 'sha256:'.str_repeat('c', 64),
            'terms' => Terms::make($terms('resume')),
        ]));
        self::assertSame($ordinal, $this->value('SELECT cursor_ordinal FROM billium_schedule_billing_schedule'));
        $command = new MaterializeNext(sellerRef: 'seller-a', environment: 'test', scheduleRef: 'schedule-a',
            expectedRowVersion: 3, expectedCancelGeneration: 0, asOf: new DateTimeImmutable($resumeAt), commandKey: 'first-resumed');
        $first = $this->occurrences()->materializeNext($command)->body();
        self::assertSame($first, $this->occurrences()->materializeNext($command)->body());
        self::assertSame($resumeAt, $this->utc($first['periodStart']));
        self::assertSame($nextAt, $this->utc($first['periodEnd']));
        self::assertSame($ordinal + 1, $this->value('SELECT cursor_ordinal FROM billium_schedule_billing_schedule'));
        self::assertSame(1, $this->rows('billium_schedule_billing_occurrence'));
    }

    /** @return array<string, array{PeriodPolicy, string, string, string, string, int}> */
    public static function resumeCalendars(): array
    {
        return [
            'annual leap-day anchor in New York' => [
                new PeriodPolicy(timezone: 'America/New_York', frequency: 'year'),
                '2020-02-29T00:00:00', '2020-02-29T05:00:00Z', '2022-02-28T05:00:00Z', '2023-02-28T05:00:00Z', 2,
            ],
            'two-month interval on the later offset in Lord Howe' => [
                new PeriodPolicy(timezone: 'Australia/Lord_Howe', frequency: 'month', intervalCount: 2, dstFoldPolicy: 'later_offset'),
                '2024-02-07T01:45:00', '2024-02-06T14:45:00Z', '2024-04-06T15:15:00Z', '2024-06-06T15:15:00Z', 1,
            ],
        ];
    }

    private function materialize(string $key, int $version): MaterializeNext
    {
        return new MaterializeNext(sellerRef: 'seller-a', environment: 'test', scheduleRef: 'schedule-a',
            expectedRowVersion: $version, expectedCancelGeneration: 0, asOf: new DateTimeImmutable('2024-05-01T00:00:00Z'), commandKey: $key);
    }

    private function utc(mixed $value): string
    {
        return new DateTimeImmutable(self::text($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
