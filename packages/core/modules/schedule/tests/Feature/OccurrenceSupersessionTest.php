<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Feature;

use Billium\Schedule\Enums\OccurrenceState;
use Billium\Schedule\Tests\OccurrenceTestCase;
use Billium\Schedule\Tests\Support\Terms;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class OccurrenceSupersessionTest extends OccurrenceTestCase
{
    public function test_planned_replacement_and_return_to_original_use_a_new_incarnation(): void
    {
        $this->createSchedule();
        $first = $this->occurrences()->ensureOccurrence($this->ensure())->body()['occurrenceRef'];
        $this->occurrences()->applyScheduleRevision($this->revision(['expectedRevision' => 1, 'expectedRowVersion' => 1,
            'newRevision' => 2, 'commandKey' => 'shift', 'terms' => Terms::make(['purpose' => 'recurring_charge', 'anchorLocal' => '2024-02-07T00:00:00'])]));
        self::assertSame('superseded', $this->value('SELECT state FROM billium_schedule_billing_occurrence WHERE occurrence_ref=?', [$first]));
        self::assertSame(1, $this->rows('billium_schedule_billing_occurrence', "state='planned'"));
        $this->occurrences()->applyScheduleRevision($this->revision(['expectedRevision' => 2, 'expectedRowVersion' => 2,
            'newRevision' => 3, 'commandKey' => 'return']));
        self::assertSame(2, $this->value("SELECT incarnation FROM billium_schedule_billing_occurrence WHERE occurrence_ref=? AND state='planned'", [$first]));
        self::assertSame(3, $this->rows('billium_schedule_billing_occurrence'));
    }

    public function test_backdated_changes_only_append_amendments_for_rated_and_obligated_rows(): void
    {
        $this->createSchedule();
        $first = $this->occurrences()->ensureOccurrence($this->ensure())->body()['occurrenceRef'];
        $second = $this->occurrences()->ensureOccurrence($this->ensure(['periodStart' => new DateTimeImmutable('2024-02-29Z'),
            'periodEnd' => new DateTimeImmutable('2024-03-31Z'), 'ordinal' => 1, 'commandKey' => 'second']))->body()['occurrenceRef'];
        $this->advance(ref: self::text($first));
        $this->advance(ref: self::text($second), state: OccurrenceState::Rated);
        $before = $this->table('SELECT * FROM billium_schedule_billing_occurrence ORDER BY id');
        $this->occurrences()->applyScheduleRevision($this->revision(['expectedRevision' => 1, 'expectedRowVersion' => 1,
            'newRevision' => 2, 'commandKey' => 'backdated', 'terms' => Terms::make(['purpose' => 'recurring_charge', 'anchorLocal' => '2024-02-07T00:00:00'])]));
        self::assertSame($before, $this->table('SELECT * FROM billium_schedule_billing_occurrence ORDER BY id'));
        self::assertSame(2, $this->rows('billium_schedule_occurrence_amendment', "kind='amendment_required'"));
        self::assertSame(2, $this->rows('billium_schedule_occurrence_outbox', "event_kind='schedule.occurrence.amendment_required.v1'"));
    }
}
