<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal\Reducers;

use Billium\Contracts\Data\Envelopes\EnsureBillingOccurrence;
use Billium\Schedule\Data\Commands\ApplyScheduleRevision;
use Billium\Schedule\Data\Commands\CancelSchedule;
use Billium\Schedule\Data\Commands\EnsureOccurrence;
use Billium\Schedule\Data\ScheduleRevisionTerms;
use Billium\Schedule\Enums\OccurrencePurpose;
use Billium\Schedule\Internal\Canonical;
use Billium\Schedule\Internal\Errors\OccurrenceProblems;
use Billium\Schedule\Internal\Identity\OccurrenceIdentity;
use Billium\Schedule\Internal\OccurrenceTime;
use Billium\Schedule\Internal\Payload;
use DateTimeImmutable;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class OccurrenceDecisionReducer
{
    public function validateEnsure(EnsureOccurrence $command): void
    {
        if ($command->periodEnd <= $command->periodStart
            || $command->scheduleRevision < 1
            || $command->ordinal < 0
            || $command->ordinal > 100000
            || (($command->purpose === OccurrencePurpose::Proration) !== ($command->parentOccurrenceRef !== null))) {
            OccurrenceProblems::refuse('occurrence.invalid_interval_or_lineage');
        }
        foreach ([$command->sellerRef, $command->environment, $command->componentRef, $command->scheduleRef, $command->inputsDigest] as $ref) {
            if ($ref === '' || strlen($ref) > 200) {
                OccurrenceProblems::refuse('occurrence.invalid_reference');
            }
        }
    }

    public function matchesPinnedWire(EnsureOccurrence $pinned, EnsureBillingOccurrence $command): bool
    {
        return $pinned->sellerRef === $command->sellerRef
            && $pinned->environment === $command->environment
            && $pinned->componentRef === $command->componentRef
            && $pinned->purpose->value === $command->purpose
            && $pinned->scheduleRevision === $command->scheduleRevision
            && OccurrenceTime::format($pinned->periodStart) === OccurrenceTime::format(new DateTimeImmutable(Payload::text($command->interval->start)))
            && OccurrenceTime::format($pinned->periodEnd) === OccurrenceTime::format(new DateTimeImmutable(Payload::text($command->interval->end)));
    }

    public function validateRevisionCommand(ApplyScheduleRevision $command): OccurrencePurpose
    {
        $terms = $command->terms;
        $purpose = OccurrencePurpose::tryFrom($terms->purpose);
        if ($purpose === null || $purpose === OccurrencePurpose::Proration) {
            OccurrenceProblems::refuse('occurrence.purpose_rejected');
        }
        if ($terms->sellerRef !== $command->sellerRef
            || $terms->environment !== $command->environment
            || $command->newRevision !== $command->expectedRevision + 1
            || $command->newRevision < 1
            || $command->inputsDigest === ''
            || strlen($command->inputsDigest) > 200) {
            OccurrenceProblems::refuse('schedule.invalid_revision');
        }

        return $purpose;
    }

    public function assertInitialRevisionCreatable(ApplyScheduleRevision $command): void
    {
        if ($command->expectedRowVersion !== 0 || $command->terms->action !== 'apply') {
            OccurrenceProblems::refuse('schedule.revision_stale');
        }
    }

    public function assertRevisionHeadMatches(stdClass $head, ApplyScheduleRevision $command, OccurrencePurpose $purpose, bool $created): void
    {
        $terms = $command->terms;
        if (! $created && ($head->current_revision !== $command->expectedRevision
            || $head->row_version !== $command->expectedRowVersion
            || $head->state === 'cancelled')) {
            OccurrenceProblems::refuse('schedule.revision_stale');
        }
        if ($head->component_ref !== $terms->componentRef
            || $head->purpose !== $purpose->value
            || $head->cancel_generation !== $terms->cancellationGeneration) {
            OccurrenceProblems::refuse('schedule.revision_stale');
        }
    }

    public function assertRunnableHead(?stdClass $head): stdClass
    {
        if ($head === null || $head->state !== 'active' || (bool) $head->runnable === false) {
            OccurrenceProblems::refuse('schedule.not_runnable');
        }

        return $head;
    }

    public function assertCancelableHead(?stdClass $head, CancelSchedule $command): stdClass
    {
        if ($head === null || ! in_array($head->state, ['active', 'paused'], true)) {
            OccurrenceProblems::refuse('schedule.not_runnable');
        }
        if ($head->current_revision !== $command->expectedRevision
            || $head->row_version !== $command->expectedRowVersion
            || $head->cancel_generation !== $command->expectedCancelGeneration) {
            OccurrenceProblems::refuse('schedule.revision_stale');
        }

        return $head;
    }

    /**
     * Pure supersession, exclusion, and revision-merge decision for a single active occurrence row.
     *
     * @return array{freeState: ?string, supersededByRef: ?string, amendmentKind: string, amendmentDetail: array<string, mixed>, ensureReplacement: bool}
     */
    public function planRevisionRow(stdClass $row, string $replacementRef, string $action, string $newInputsDigest): array
    {
        $isPlanned = $row->state === 'planned';

        if ($action === 'pause') {
            return [
                'freeState' => $isPlanned ? 'cancelled' : null,
                'supersededByRef' => null,
                'amendmentKind' => $isPlanned ? 'cancelled' : 'amendment_required',
                'amendmentDetail' => ['action' => 'pause'],
                'ensureReplacement' => false,
            ];
        }

        if ($replacementRef === $row->occurrence_ref && $row->inputs_digest === $newInputsDigest) {
            return [
                'freeState' => null,
                'supersededByRef' => null,
                'amendmentKind' => 'revision_confirmed',
                'amendmentDetail' => [],
                'ensureReplacement' => false,
            ];
        }

        if ($replacementRef === $row->occurrence_ref || ! $isPlanned) {
            return [
                'freeState' => null,
                'supersededByRef' => null,
                'amendmentKind' => 'amendment_required',
                'amendmentDetail' => ['proposedOccurrenceRef' => $replacementRef, 'proposedInputsDigest' => $newInputsDigest],
                'ensureReplacement' => false,
            ];
        }

        return [
            'freeState' => 'superseded',
            'supersededByRef' => $replacementRef,
            'amendmentKind' => 'superseded_by',
            'amendmentDetail' => ['supersededByOccurrenceRef' => $replacementRef],
            'ensureReplacement' => true,
        ];
    }

    public function isRetainedForMaterialize(?stdClass $existing, int $scheduleId, int $ordinal): bool
    {
        return $existing !== null
            && $existing->schedule_id === $scheduleId
            && $existing->ordinal === $ordinal
            && in_array($existing->state, ['rated', 'obligated'], true);
    }

    /** @return array{complete: bool, cursorOrdinal: int, nextOccurrenceAt: ?string, state: string, runnable: bool} */
    public function nextMaterializeState(int $ordinal, bool $nextPaused, ?string $nextPeriodStart): array
    {
        $complete = $ordinal === 100000;
        $cursorOrdinal = $complete ? $ordinal : $ordinal + 1;
        $nextOccurrenceAt = $complete || $nextPaused ? null : $nextPeriodStart;

        return [
            'complete' => $complete,
            'cursorOrdinal' => $cursorOrdinal,
            'nextOccurrenceAt' => $nextOccurrenceAt,
            'state' => $complete ? 'completed' : ($nextPaused ? 'paused' : 'active'),
            'runnable' => ! $complete && ! $nextPaused,
        ];
    }

    /** @return array{attempts: int, retryAt: string} */
    public function failureRetry(int $currentAttempts, DateTimeImmutable $asOf): array
    {
        $attempts = $currentAttempts + 1;
        $delay = min(2 ** min($attempts, 6) * 60, 3600);

        return [
            'attempts' => $attempts,
            'retryAt' => OccurrenceTime::format($asOf->modify('+'.$delay.' seconds')),
        ];
    }

    public function sameCalendar(ScheduleRevisionTerms $left, ScheduleRevisionTerms $right): bool
    {
        return $left->anchorLocal === $right->anchorLocal
            && $left->policy->timezone === $right->policy->timezone
            && $left->policy->frequency === $right->policy->frequency
            && $left->policy->intervalCount === $right->policy->intervalCount
            && $left->policy->endOfMonth === $right->policy->endOfMonth
            && $left->policy->dstGapPolicy === $right->policy->dstGapPolicy
            && $left->policy->dstFoldPolicy === $right->policy->dstFoldPolicy;
    }

    public function shouldSkipPausedCursor(
        string $headState,
        ScheduleRevisionTerms $applicableTerms,
        ScheduleRevisionTerms $commandTerms,
        DateTimeImmutable $effectiveFrom,
        DateTimeImmutable $nextStart,
    ): bool {
        return $headState === 'paused'
            && $applicableTerms->action === 'pause'
            && $commandTerms->action === 'resume'
            && $this->sameCalendar($applicableTerms, $commandTerms)
            && $effectiveFrom > $nextStart;
    }

    public function occurrenceRef(EnsureOccurrence $command): string
    {
        return OccurrenceIdentity::ref(
            seller: $command->sellerRef,
            env: $command->environment,
            component: $command->componentRef,
            purpose: $command->purpose->value,
            start: $command->periodStart,
            end: $command->periodEnd,
        );
    }

    /** @param list<stdClass> $rows */
    public function revisionRowFingerprint(array $rows): string
    {
        return Canonical::digest(array_map(static fn (stdClass $r): array => [$r->id, $r->state, $r->row_version], $rows));
    }
}
