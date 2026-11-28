<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Feature;

use Billium\Schedule\Data\Commands\CancelSchedule;
use Billium\Schedule\Data\Commands\MaterializeNext;
use Billium\Schedule\Data\Commands\RecordScheduleFailure;
use Billium\Schedule\Enums\OccurrencePurpose;
use Billium\Schedule\Enums\OccurrenceState;
use Billium\Schedule\Tests\OccurrenceTestCase;
use Billium\Schedule\Tests\Support\Terms;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class OccurrenceCharacterizationTest extends OccurrenceTestCase
{
    public function test_revision_merge_confirms_unchanged_planned_and_flags_changed_inputs_without_superseding(): void
    {
        $this->createSchedule();
        $janRef = self::text($this->occurrences()->ensureOccurrence($this->ensure())->body()['occurrenceRef']);

        // Same period + same inputsDigest -> revision_confirmed, row untouched
        $this->occurrences()->applyScheduleRevision($this->revision([
            'expectedRevision' => 1,
            'expectedRowVersion' => 1,
            'newRevision' => 2,
            'commandKey' => 'rev-confirm',
        ]));

        self::assertSame('planned', $this->value('SELECT state FROM billium_schedule_billing_occurrence WHERE occurrence_ref=?', [$janRef]));
        self::assertSame(1, $this->rows('billium_schedule_occurrence_amendment', "kind='revision_confirmed'"));

        // Same period + changed inputsDigest -> amendment_required, row still planned
        $newDigest = 'sha256:'.str_repeat('b', 64);
        $this->occurrences()->applyScheduleRevision($this->revision([
            'expectedRevision' => 2,
            'expectedRowVersion' => 2,
            'newRevision' => 3,
            'inputsDigest' => $newDigest,
            'commandKey' => 'rev-price-change',
            'terms' => Terms::make(['purpose' => 'recurring_charge', 'priceVersionRef' => 'price:2', 'monetaryTermsDigest' => $newDigest]),
        ]));

        self::assertSame('planned', $this->value('SELECT state FROM billium_schedule_billing_occurrence WHERE occurrence_ref=?', [$janRef]));
        self::assertSame(1, $this->rows('billium_schedule_occurrence_amendment', "kind='amendment_required'"));
        /** @var array<string, mixed> $detail */
        $detail = json_decode(self::text($this->value("SELECT detail FROM billium_schedule_occurrence_amendment WHERE kind='amendment_required'")), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($janRef, $detail['proposedOccurrenceRef']);
        self::assertSame($newDigest, $detail['proposedInputsDigest']);
    }

    public function test_adjacent_planned_occurrences_shift_without_self_overlap_via_two_pass_supersession(): void
    {
        $this->createSchedule();
        $janRef = self::text($this->occurrences()->ensureOccurrence($this->ensure())->body()['occurrenceRef']);
        $febRef = self::text($this->occurrences()->ensureOccurrence($this->ensure([
            'periodStart' => new DateTimeImmutable('2024-02-29Z'),
            'periodEnd' => new DateTimeImmutable('2024-03-31Z'),
            'ordinal' => 1,
            'commandKey' => 'ensure-feb',
        ]))->body()['occurrenceRef']);

        // Shift anchor by +1 day so new Jan [2024-02-01..2024-03-01) overlaps old Jan,
        // and new Feb [2024-03-01..2024-04-01) overlaps old Feb.
        $this->occurrences()->applyScheduleRevision($this->revision([
            'expectedRevision' => 1,
            'expectedRowVersion' => 1,
            'newRevision' => 2,
            'commandKey' => 'shift-adjacent',
            'terms' => Terms::make(['purpose' => 'recurring_charge', 'anchorLocal' => '2024-02-01T00:00:00']),
        ]));

        self::assertSame('superseded', $this->value('SELECT state FROM billium_schedule_billing_occurrence WHERE occurrence_ref=?', [$janRef]));
        self::assertSame('superseded', $this->value('SELECT state FROM billium_schedule_billing_occurrence WHERE occurrence_ref=?', [$febRef]));
        self::assertSame(2, $this->rows('billium_schedule_billing_occurrence', "state='planned'"));
        self::assertSame(2, $this->rows('billium_schedule_occurrence_amendment', "kind='superseded_by'"));
    }

    public function test_pause_revision_cancels_planned_and_amends_obligated_from_effective_from(): void
    {
        $this->createSchedule();
        $janRef = self::text($this->occurrences()->ensureOccurrence($this->ensure())->body()['occurrenceRef']);
        $febRef = self::text($this->occurrences()->ensureOccurrence($this->ensure([
            'periodStart' => new DateTimeImmutable('2024-02-29Z'),
            'periodEnd' => new DateTimeImmutable('2024-03-31Z'),
            'ordinal' => 1,
            'commandKey' => 'ensure-feb',
        ]))->body()['occurrenceRef']);
        $marRef = self::text($this->occurrences()->ensureOccurrence($this->ensure([
            'periodStart' => new DateTimeImmutable('2024-03-31Z'),
            'periodEnd' => new DateTimeImmutable('2024-04-30Z'),
            'ordinal' => 2,
            'commandKey' => 'ensure-mar',
        ]))->body()['occurrenceRef']);

        $this->advance(ref: $febRef, state: OccurrenceState::Obligated);

        $this->occurrences()->applyScheduleRevision($this->revision([
            'expectedRevision' => 1,
            'expectedRowVersion' => 1,
            'newRevision' => 2,
            'effectiveFrom' => new DateTimeImmutable('2024-02-29Z'),
            'commandKey' => 'pause-from-feb',
            'terms' => Terms::make(['purpose' => 'recurring_charge', 'action' => 'pause']),
        ]));

        // Jan is before effectiveFrom -> untouched
        self::assertSame('planned', $this->value('SELECT state FROM billium_schedule_billing_occurrence WHERE occurrence_ref=?', [$janRef]));
        // Feb is obligated -> remains obligated, gets amendment_required with action=pause
        self::assertSame('obligated', $this->value('SELECT state FROM billium_schedule_billing_occurrence WHERE occurrence_ref=?', [$febRef]));
        // Mar is planned -> cancelled, gets cancelled amendment with action=pause
        self::assertSame('cancelled', $this->value('SELECT state FROM billium_schedule_billing_occurrence WHERE occurrence_ref=?', [$marRef]));
        self::assertSame(1, $this->rows('billium_schedule_occurrence_amendment', "occurrence_ref='{$marRef}' AND kind='cancelled'"));
        self::assertSame(1, $this->rows('billium_schedule_occurrence_amendment', "occurrence_ref='{$febRef}' AND kind='amendment_required'"));
    }

    public function test_materialize_retains_rated_occurrence_and_exclusion_blocks_overlapping_ensure(): void
    {
        $this->createSchedule();
        $janRef = self::text($this->occurrences()->ensureOccurrence($this->ensure())->body()['occurrenceRef']);
        $this->advance(ref: $janRef, state: OccurrenceState::Rated);

        // Materialize cursor at ordinal 0 retains the already rated occurrence without creating a duplicate
        $receipt = $this->occurrences()->materializeNext(new MaterializeNext(
            sellerRef: 'seller-a',
            environment: 'test',
            scheduleRef: 'schedule-a',
            expectedRowVersion: 1,
            expectedCancelGeneration: 0,
            asOf: new DateTimeImmutable('2024-02-01Z'),
            commandKey: 'materialize-retained',
        ))->body();

        self::assertFalse($receipt['created']);
        self::assertSame('rated', $receipt['state']);
        self::assertSame(1, $receipt['cursorOrdinal']);

        // Overlapping recurring_charge is refused by exclusion, while usage_settlement coexists
        $this->refusal(fn () => $this->occurrences()->ensureOccurrence($this->ensure([
            'periodStart' => new DateTimeImmutable('2024-02-10Z'),
            'periodEnd' => new DateTimeImmutable('2024-03-10Z'),
            'commandKey' => 'overlap-refused',
        ])), 'occurrence.overlap');

        $this->occurrences()->applyScheduleRevision($this->revision([
            'scheduleRef' => 'usage-sched',
            'commandKey' => 'usage-rev',
            'terms' => Terms::make(['purpose' => 'usage_settlement']),
        ]));
        $usage = $this->occurrences()->ensureOccurrence($this->ensure([
            'scheduleRef' => 'usage-sched',
            'purpose' => OccurrencePurpose::UsageSettlement,
            'commandKey' => 'usage-ensure',
        ]))->body();
        self::assertTrue($usage['created']);
    }

    public function test_validation_failure_backoff_and_cancel_preserve_exact_receipts(): void
    {
        $this->refusal(fn () => $this->occurrences()->applyScheduleRevision($this->revision([
            'terms' => Terms::make(['purpose' => 'proration']),
            'commandKey' => 'bad-purpose',
        ])), 'occurrence.purpose_rejected');

        $this->refusal(fn () => $this->occurrences()->applyScheduleRevision($this->revision([
            'newRevision' => 2,
            'commandKey' => 'bad-rev-seq',
        ])), 'schedule.invalid_revision');

        $this->createSchedule();

        $fail1 = $this->occurrences()->recordFailure(new RecordScheduleFailure(
            sellerRef: 'seller-a',
            environment: 'test',
            scheduleRef: 'schedule-a',
            expectedRowVersion: 1,
            asOf: new DateTimeImmutable('2024-01-31T00:00:00Z'),
            commandKey: 'fail-1',
        ))->body();
        self::assertSame(1, $fail1['attempts']);
        self::assertSame('2024-01-31T00:02:00.000000Z', $fail1['retryAt']);

        $janRef = self::text($this->occurrences()->ensureOccurrence($this->ensure())->body()['occurrenceRef']);
        $cancel = $this->occurrences()->cancelSchedule(new CancelSchedule(
            sellerRef: 'seller-a',
            environment: 'test',
            scheduleRef: 'schedule-a',
            expectedRevision: 1,
            expectedRowVersion: 2,
            expectedCancelGeneration: 0,
            commandKey: 'cancel-1',
        ))->body();

        self::assertSame(1, $cancel['cancelGeneration']);
        self::assertSame('cancelled', $this->value('SELECT state FROM billium_schedule_billing_occurrence WHERE occurrence_ref=?', [$janRef]));
        self::assertSame('cancelled', $this->value('SELECT state FROM billium_schedule_billing_schedule'));
    }
}
