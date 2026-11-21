<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests;

use Billium\Schedule\Data\Commands\MaterializeNext;
use Billium\Schedule\Data\PeriodPolicy;
use Billium\Schedule\Tests\Support\Terms;
use DateTimeImmutable;
use DateTimeZone;
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
