<?php

declare(strict_types=1);

namespace Billium\Schedule\Tests\Unit;

use Billium\Contracts\Data\Envelopes\EnsureBillingOccurrence;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\Support\FixtureArguments;
use Billium\Schedule\Data\Commands\ApplyScheduleRevision;
use Billium\Schedule\Data\Commands\CancelSchedule;
use Billium\Schedule\Data\Commands\EnsureOccurrence;
use Billium\Schedule\Data\PeriodPolicy;
use Billium\Schedule\Enums\OccurrencePurpose;
use Billium\Schedule\Internal\Reducers\OccurrenceDecisionReducer;
use Billium\Schedule\Tests\Support\Terms;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use stdClass;

final class OccurrenceDecisionReducerTest extends TestCase
{
    private OccurrenceDecisionReducer $reducer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reducer = new OccurrenceDecisionReducer;
    }

    public function test_validate_ensure_accepts_valid_and_rejects_boundary_violations(): void
    {
        $valid = $this->makeEnsure();
        $this->reducer->validateEnsure($valid);
        self::assertSame(
            $this->reducer->occurrenceRef($valid),
            $this->reducer->occurrenceRef($this->makeEnsure(['periodStart' => new DateTimeImmutable('2024-01-31T00:00:00+00:00')])),
        );

        // ordinal bounds: 0 and 100000 allowed, -1 and 100001 rejected
        $this->reducer->validateEnsure($this->makeEnsure(['ordinal' => 0]));
        $this->reducer->validateEnsure($this->makeEnsure(['ordinal' => 100000]));

        $this->assertRefused(fn () => $this->reducer->validateEnsure($this->makeEnsure(['ordinal' => -1])), 'occurrence.invalid_interval_or_lineage');
        $this->assertRefused(fn () => $this->reducer->validateEnsure($this->makeEnsure(['ordinal' => 100001])), 'occurrence.invalid_interval_or_lineage');
        $this->assertRefused(fn () => $this->reducer->validateEnsure($this->makeEnsure(['scheduleRevision' => 0])), 'occurrence.invalid_interval_or_lineage');
        $this->assertRefused(fn () => $this->reducer->validateEnsure($this->makeEnsure([
            'periodEnd' => new DateTimeImmutable('2024-01-31Z'),
        ])), 'occurrence.invalid_interval_or_lineage');
        $this->assertRefused(fn () => $this->reducer->validateEnsure($this->makeEnsure([
            'purpose' => OccurrencePurpose::Proration,
            'parentOccurrenceRef' => null,
        ])), 'occurrence.invalid_interval_or_lineage');
        $this->assertRefused(fn () => $this->reducer->validateEnsure($this->makeEnsure([
            'purpose' => OccurrencePurpose::RecurringCharge,
            'parentOccurrenceRef' => 'parent-1',
        ])), 'occurrence.invalid_interval_or_lineage');

        // valid proration with parent
        $this->reducer->validateEnsure($this->makeEnsure([
            'purpose' => OccurrencePurpose::Proration,
            'parentOccurrenceRef' => 'parent-1',
        ]));

        // reference length bounds: 200 ok, 0 or 201 rejected
        $this->reducer->validateEnsure($this->makeEnsure(['inputsDigest' => str_repeat('x', 200)]));
        $this->assertRefused(fn () => $this->reducer->validateEnsure($this->makeEnsure(['sellerRef' => ''])), 'occurrence.invalid_reference');
        $this->assertRefused(fn () => $this->reducer->validateEnsure($this->makeEnsure(['inputsDigest' => str_repeat('x', 201)])), 'occurrence.invalid_reference');
    }

    public function test_matches_pinned_wire_checks_all_fields_and_normalized_instants(): void
    {
        $pinned = $this->makeEnsure();
        $wire = new EnsureBillingOccurrence(
            installationRef: 'inst-1',
            sellerRef: 'seller-a',
            environment: 'test',
            componentRef: 'component-a',
            purpose: 'recurring_charge',
            scheduleRevision: 1,
            interval: (object) ['start' => '2024-01-31T00:00:00Z', 'end' => '2024-02-29T00:00:00+00:00'],
            commandKey: 'cmd-1',
            raw: new stdClass,
        );
        self::assertTrue($this->reducer->matchesPinnedWire($pinned, $wire));

        $wrongSeller = new EnsureBillingOccurrence(
            installationRef: 'inst-1',
            sellerRef: 'seller-b',
            environment: 'test',
            componentRef: 'component-a',
            purpose: 'recurring_charge',
            scheduleRevision: 1,
            interval: (object) ['start' => '2024-01-31T00:00:00Z', 'end' => '2024-02-29T00:00:00Z'],
            commandKey: 'cmd-1',
            raw: new stdClass,
        );
        self::assertFalse($this->reducer->matchesPinnedWire($pinned, $wrongSeller));

        $wrongEnd = new EnsureBillingOccurrence(
            installationRef: 'inst-1',
            sellerRef: 'seller-a',
            environment: 'test',
            componentRef: 'component-a',
            purpose: 'recurring_charge',
            scheduleRevision: 1,
            interval: (object) ['start' => '2024-01-31T00:00:00Z', 'end' => '2024-03-01T00:00:00Z'],
            commandKey: 'cmd-1',
            raw: new stdClass,
        );
        self::assertFalse($this->reducer->matchesPinnedWire($pinned, $wrongEnd));
    }

    public function test_validate_revision_command_and_head_assertions(): void
    {
        $cmd = $this->makeRevision();
        self::assertSame(OccurrencePurpose::RecurringCharge, $this->reducer->validateRevisionCommand($cmd));

        $this->assertRefused(fn () => $this->reducer->validateRevisionCommand($this->makeRevision([
            'terms' => Terms::make(['purpose' => 'proration']),
        ])), 'occurrence.purpose_rejected');
        $this->assertRefused(fn () => $this->reducer->validateRevisionCommand($this->makeRevision([
            'terms' => Terms::make(['purpose' => 'unknown_purpose']),
        ])), 'occurrence.purpose_rejected');
        $this->assertRefused(fn () => $this->reducer->validateRevisionCommand($this->makeRevision([
            'newRevision' => 2,
        ])), 'schedule.invalid_revision');
        $this->assertRefused(fn () => $this->reducer->validateRevisionCommand($this->makeRevision([
            'expectedRevision' => -1,
            'newRevision' => 0,
        ])), 'schedule.invalid_revision');
        $this->assertRefused(fn () => $this->reducer->validateRevisionCommand($this->makeRevision([
            'inputsDigest' => '',
        ])), 'schedule.invalid_revision');
        $this->assertRefused(fn () => $this->reducer->validateRevisionCommand($this->makeRevision([
            'inputsDigest' => str_repeat('a', 201),
        ])), 'schedule.invalid_revision');

        // Initial revision creatable
        $this->reducer->assertInitialRevisionCreatable($cmd);
        $this->assertRefused(fn () => $this->reducer->assertInitialRevisionCreatable($this->makeRevision([
            'expectedRowVersion' => 1,
        ])), 'schedule.revision_stale');
        $this->assertRefused(fn () => $this->reducer->assertInitialRevisionCreatable($this->makeRevision([
            'terms' => Terms::make(['purpose' => 'recurring_charge', 'action' => 'pause']),
        ])), 'schedule.revision_stale');

        // Head matches
        $head = (object) [
            'current_revision' => 1,
            'row_version' => 2,
            'state' => 'active',
            'runnable' => true,
            'component_ref' => 'component-a',
            'purpose' => 'recurring_charge',
            'cancel_generation' => 0,
        ];
        $rev2 = $this->makeRevision(['expectedRevision' => 1, 'expectedRowVersion' => 2, 'newRevision' => 2]);
        $this->reducer->assertRevisionHeadMatches($head, $rev2, OccurrencePurpose::RecurringCharge, false);
        $this->assertRefused(fn () => $this->reducer->assertRevisionHeadMatches((object) [...(array) $head, 'state' => 'cancelled'], $rev2, OccurrencePurpose::RecurringCharge, false), 'schedule.revision_stale');
        $this->assertRefused(fn () => $this->reducer->assertRevisionHeadMatches((object) [...(array) $head, 'component_ref' => 'other'], $rev2, OccurrencePurpose::RecurringCharge, false), 'schedule.revision_stale');

        // Runnable & Cancelable head
        self::assertSame($head, $this->reducer->assertRunnableHead($head));
        $this->assertRefused(fn () => $this->reducer->assertRunnableHead(null), 'schedule.not_runnable');
        $this->assertRefused(fn () => $this->reducer->assertRunnableHead((object) [...(array) $head, 'state' => 'paused']), 'schedule.not_runnable');
        $this->assertRefused(fn () => $this->reducer->assertRunnableHead((object) [...(array) $head, 'runnable' => false]), 'schedule.not_runnable');

        $cancel = new CancelSchedule('seller-a', 'test', 'schedule-a', 1, 2, 0, 'cancel-1');
        self::assertSame($head, $this->reducer->assertCancelableHead($head, $cancel));
        self::assertSame($paused = (object) [...(array) $head, 'state' => 'paused'], $this->reducer->assertCancelableHead($paused, $cancel));
        $this->assertRefused(fn () => $this->reducer->assertCancelableHead((object) [...(array) $head, 'state' => 'cancelled'], $cancel), 'schedule.not_runnable');
        $this->assertRefused(fn () => $this->reducer->assertCancelableHead($head, new CancelSchedule('seller-a', 'test', 'schedule-a', 1, 2, 1, 'cancel-1')), 'schedule.revision_stale');
    }

    public function test_plan_revision_row_covers_all_supersession_exclusion_and_merge_branches(): void
    {
        $planned = (object) ['occurrence_ref' => 'occ-1', 'state' => 'planned', 'inputs_digest' => 'sha256:old'];
        $rated = (object) ['occurrence_ref' => 'occ-1', 'state' => 'rated', 'inputs_digest' => 'sha256:old'];

        // 1. Pause planned -> cancelled
        self::assertSame([
            'freeState' => 'cancelled',
            'supersededByRef' => null,
            'amendmentKind' => 'cancelled',
            'amendmentDetail' => ['action' => 'pause'],
            'ensureReplacement' => false,
        ], $this->reducer->planRevisionRow($planned, 'occ-2', 'pause', 'sha256:new'));

        // 2. Pause non-planned -> amendment_required, no state change
        self::assertSame([
            'freeState' => null,
            'supersededByRef' => null,
            'amendmentKind' => 'amendment_required',
            'amendmentDetail' => ['action' => 'pause'],
            'ensureReplacement' => false,
        ], $this->reducer->planRevisionRow($rated, 'occ-2', 'pause', 'sha256:new'));

        // 3. Same ref + same digest -> revision_confirmed
        self::assertSame([
            'freeState' => null,
            'supersededByRef' => null,
            'amendmentKind' => 'revision_confirmed',
            'amendmentDetail' => [],
            'ensureReplacement' => false,
        ], $this->reducer->planRevisionRow($planned, 'occ-1', 'apply', 'sha256:old'));

        // 4. Same ref + changed digest -> amendment_required
        self::assertSame([
            'freeState' => null,
            'supersededByRef' => null,
            'amendmentKind' => 'amendment_required',
            'amendmentDetail' => ['proposedOccurrenceRef' => 'occ-1', 'proposedInputsDigest' => 'sha256:new'],
            'ensureReplacement' => false,
        ], $this->reducer->planRevisionRow($planned, 'occ-1', 'apply', 'sha256:new'));

        // 5. Different ref + non-planned (rated/obligated) -> amendment_required (no supersession!)
        self::assertSame([
            'freeState' => null,
            'supersededByRef' => null,
            'amendmentKind' => 'amendment_required',
            'amendmentDetail' => ['proposedOccurrenceRef' => 'occ-2', 'proposedInputsDigest' => 'sha256:new'],
            'ensureReplacement' => false,
        ], $this->reducer->planRevisionRow($rated, 'occ-2', 'apply', 'sha256:new'));

        // 6. Different ref + planned -> superseded + ensure replacement
        self::assertSame([
            'freeState' => 'superseded',
            'supersededByRef' => 'occ-2',
            'amendmentKind' => 'superseded_by',
            'amendmentDetail' => ['supersededByOccurrenceRef' => 'occ-2'],
            'ensureReplacement' => true,
        ], $this->reducer->planRevisionRow($planned, 'occ-2', 'apply', 'sha256:new'));
    }

    public function test_materialize_retention_completion_failure_backoff_and_pause_skip(): void
    {
        $rated = (object) ['schedule_id' => 10, 'ordinal' => 2, 'state' => 'rated'];
        $obligated = (object) ['schedule_id' => 10, 'ordinal' => 2, 'state' => 'obligated'];
        $planned = (object) ['schedule_id' => 10, 'ordinal' => 2, 'state' => 'planned'];

        self::assertTrue($this->reducer->isRetainedForMaterialize($rated, 10, 2));
        self::assertTrue($this->reducer->isRetainedForMaterialize($obligated, 10, 2));
        self::assertFalse($this->reducer->isRetainedForMaterialize($planned, 10, 2));
        self::assertFalse($this->reducer->isRetainedForMaterialize($rated, 11, 2));
        self::assertFalse($this->reducer->isRetainedForMaterialize($rated, 10, 3));
        self::assertFalse($this->reducer->isRetainedForMaterialize(null, 10, 2));

        self::assertSame([
            'complete' => false,
            'cursorOrdinal' => 1,
            'nextOccurrenceAt' => '2024-02-29T00:00:00Z',
            'state' => 'active',
            'runnable' => true,
        ], $this->reducer->nextMaterializeState(0, false, '2024-02-29T00:00:00Z'));

        self::assertSame([
            'complete' => false,
            'cursorOrdinal' => 1,
            'nextOccurrenceAt' => null,
            'state' => 'paused',
            'runnable' => false,
        ], $this->reducer->nextMaterializeState(0, true, '2024-02-29T00:00:00Z'));

        self::assertSame([
            'complete' => true,
            'cursorOrdinal' => 100000,
            'nextOccurrenceAt' => null,
            'state' => 'completed',
            'runnable' => false,
        ], $this->reducer->nextMaterializeState(100000, false, '2024-02-29T00:00:00Z'));

        $asOf = new DateTimeImmutable('2024-01-31T00:00:00Z');
        self::assertSame(['attempts' => 1, 'retryAt' => '2024-01-31T00:02:00.000000Z'], $this->reducer->failureRetry(0, $asOf));
        self::assertSame(['attempts' => 7, 'retryAt' => '2024-01-31T01:00:00.000000Z'], $this->reducer->failureRetry(6, $asOf));

        $pauseTerms = Terms::make(['purpose' => 'recurring_charge', 'action' => 'pause']);
        $resumeTerms = Terms::make(['purpose' => 'recurring_charge', 'action' => 'resume']);
        $otherCal = Terms::make(['purpose' => 'recurring_charge', 'action' => 'resume', 'policy' => new PeriodPolicy(timezone: 'America/New_York', frequency: 'month')]);

        self::assertTrue($this->reducer->sameCalendar($pauseTerms, $resumeTerms));
        self::assertFalse($this->reducer->sameCalendar($pauseTerms, $otherCal));

        self::assertTrue($this->reducer->shouldSkipPausedCursor(
            'paused',
            $pauseTerms,
            $resumeTerms,
            new DateTimeImmutable('2024-02-01Z'),
            new DateTimeImmutable('2024-01-31Z'),
        ));
        self::assertFalse($this->reducer->shouldSkipPausedCursor(
            'paused',
            $pauseTerms,
            $resumeTerms,
            new DateTimeImmutable('2024-01-31Z'),
            new DateTimeImmutable('2024-01-31Z'),
        ));
        self::assertFalse($this->reducer->shouldSkipPausedCursor(
            'active',
            $pauseTerms,
            $resumeTerms,
            new DateTimeImmutable('2024-02-01Z'),
            new DateTimeImmutable('2024-01-31Z'),
        ));
        self::assertFalse($this->reducer->shouldSkipPausedCursor(
            'paused',
            $pauseTerms,
            $otherCal,
            new DateTimeImmutable('2024-02-01Z'),
            new DateTimeImmutable('2024-01-31Z'),
        ));

        self::assertSame(
            $this->reducer->revisionRowFingerprint([(object) ['id' => 1, 'state' => 'planned', 'row_version' => 1]]),
            $this->reducer->revisionRowFingerprint([(object) ['id' => 1, 'state' => 'planned', 'row_version' => 1]]),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function makeEnsure(array $overrides = []): EnsureOccurrence
    {
        return FixtureArguments::construct(EnsureOccurrence::class, [
            'sellerRef' => 'seller-a',
            'environment' => 'test',
            'componentRef' => 'component-a',
            'purpose' => OccurrencePurpose::RecurringCharge,
            'periodStart' => new DateTimeImmutable('2024-01-31Z'),
            'periodEnd' => new DateTimeImmutable('2024-02-29Z'),
            'scheduleRef' => 'schedule-a',
            'scheduleRevision' => 1,
            'inputsDigest' => 'sha256:'.str_repeat('a', 64),
            'commandKey' => 'ensure-1',
            'ordinal' => 0,
            'parentOccurrenceRef' => null,
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeRevision(array $overrides = []): ApplyScheduleRevision
    {
        return FixtureArguments::construct(ApplyScheduleRevision::class, [
            'sellerRef' => 'seller-a',
            'environment' => 'test',
            'scheduleRef' => 'schedule-a',
            'expectedRevision' => 0,
            'expectedRowVersion' => 0,
            'newRevision' => 1,
            'terms' => Terms::make(['purpose' => 'recurring_charge']),
            'effectiveFrom' => new DateTimeImmutable('2024-01-01Z'),
            'inputsDigest' => 'sha256:'.str_repeat('a', 64),
            'commandKey' => 'revision-1',
            ...$overrides,
        ]);
    }

    /** @param callable(): mixed $fn */
    private function assertRefused(callable $fn, string $detail): void
    {
        try {
            $fn();
            self::fail('Expected '.$detail);
        } catch (ProblemException $e) {
            self::assertSame('billium.'.$detail, $e->detail);
        }
    }
}
