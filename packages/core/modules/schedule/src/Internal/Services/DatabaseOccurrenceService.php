<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal\Services;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\CommandReceipt;
use Billium\Contracts\Data\Envelopes\EnsureBillingOccurrence;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use Billium\Platform\Data\Idempotency\PinnedCommand;
use Billium\Schedule\Contracts\IntervalResolver;
use Billium\Schedule\Contracts\OccurrenceService;
use Billium\Schedule\Contracts\PinnedOccurrenceInputs;
use Billium\Schedule\Data\Commands\ApplyScheduleRevision;
use Billium\Schedule\Data\Commands\CancelSchedule;
use Billium\Schedule\Data\Commands\EnsureOccurrence;
use Billium\Schedule\Data\Commands\MaterializeNext;
use Billium\Schedule\Data\Commands\RecordScheduleFailure;
use Billium\Schedule\Data\OccurrenceReceipt;
use Billium\Schedule\Data\ResolvedInterval;
use Billium\Schedule\Data\ScheduleRevisionTerms;
use Billium\Schedule\Enums\OccurrencePurpose;
use Billium\Schedule\Internal\Canonical;
use Billium\Schedule\Internal\Errors\OccurrenceProblems;
use Billium\Schedule\Internal\Identity\OccurrenceIdentity;
use Billium\Schedule\Internal\Locks\ScheduleLockRequests;
use Billium\Schedule\Internal\OccurrenceTime;
use Billium\Schedule\Internal\Operations\ScheduleOperations;
use Billium\Schedule\Internal\Payload;
use Billium\Schedule\Internal\Storage\OccurrenceStore;
use DateTimeImmutable;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class DatabaseOccurrenceService implements OccurrenceService
{
    public function __construct(
        private OperationRunner $runner, private ScheduleOperations $operations,
        private ScheduleLockRequests $locks, private OccurrenceStore $store, private IntervalResolver $resolver,
        private CallerContext $callers, private IdempotencyReceiptQueries $receipts,
    ) {}

    public function ensureOccurrence(EnsureOccurrence $command): OccurrenceReceipt
    {
        return new OccurrenceReceipt($this->ensure($command)->orThrow()->body);
    }

    public function ensureBillingOccurrence(EnsureBillingOccurrence $command, PinnedOccurrenceInputs $inputs): CommandReceipt
    {
        $codec = new Codec;
        $command = $codec->decode($codec->encode($command));
        assert($command instanceof EnsureBillingOccurrence);
        $wire = new PinnedCommand($this->callers->current(), $command, $command->installationRef, $command->sellerRef, $command->environment, $command->commandKey);
        $stored = $this->receipts->replayCommand($wire->scope, $wire->kind(), $wire->key, $wire->inputDigest);
        if ($stored !== null) {
            return $wire->result($stored);
        }
        $pinned = $inputs->resolve($wire->scope, $command) ?? throw new ProblemException(ProblemCode::ReferenceUnknown);
        if ($pinned->sellerRef !== $command->sellerRef || $pinned->environment !== $command->environment
            || $pinned->componentRef !== $command->componentRef || $pinned->purpose->value !== $command->purpose
            || $pinned->scheduleRevision !== $command->scheduleRevision
            || OccurrenceTime::format($pinned->periodStart) !== OccurrenceTime::format(new DateTimeImmutable(Payload::text($command->interval->start)))
            || OccurrenceTime::format($pinned->periodEnd) !== OccurrenceTime::format(new DateTimeImmutable(Payload::text($command->interval->end)))) {
            throw new ProblemException(ProblemCode::AuthDenied);
        }

        return $wire->result($this->ensure($pinned, $wire));
    }

    private function ensure(EnsureOccurrence $command, ?PinnedCommand $wire = null): StoredOutcome
    {
        $this->validate($command);
        $ref = $this->ref($command);

        return $this->runner->run(
            spec: $this->operations->spec(kind: 'occurrence.ensure.v1', seller: $command->sellerRef,
                environment: $command->environment, key: $wire->key ?? $command->commandKey, payload: $command, wire: $wire),
            body: PinnedCommand::retaining($wire, function (TransactionContext $context) use ($command, $ref): OccurrenceReceipt {
                $this->runner->assertCurrent(context: $context, moduleId: 'schedule');
                $context->lock(ScheduleLockRequests::schedule($command->scheduleRef));
                $head = $this->runnable(context: $context, ref: $command->scheduleRef);
                if ($head->component_ref !== $command->componentRef || $command->scheduleRevision > $head->current_revision) {
                    OccurrenceProblems::refuse('schedule.not_runnable');
                }
                $this->locks->acquire(context: $context, occurrenceRefs: [$ref]);
                $old = $this->store->occurrence(ref: $ref, context: $context);
                if ($old !== null) {
                    return $this->store->existing(command: $command, row: $old);
                }
                $applicable = $this->applicableRevision(context: $context, head: $head, periodStart: $command->periodStart);
                if ($command->scheduleRevision !== Payload::integer($applicable->revision) || $command->inputsDigest !== $applicable->inputs_digest) {
                    OccurrenceProblems::refuse('schedule.revision_stale');
                }
                $terms = ScheduleRevisionTerms::fromArray(Payload::decode($applicable->terms));
                if ($terms->action === 'pause') {
                    OccurrenceProblems::refuse('schedule.not_runnable');
                }

                return $this->store->ensure(context: $context, command: $command, ref: $ref, head: $head);
            }),
        );
    }

    public function applyScheduleRevision(ApplyScheduleRevision $command): OccurrenceReceipt
    {
        $terms = $command->terms;
        $purpose = OccurrencePurpose::tryFrom($terms->purpose);
        if ($purpose === null || $purpose === OccurrencePurpose::Proration) {
            OccurrenceProblems::refuse('occurrence.purpose_rejected');
        }
        if ($terms->sellerRef !== $command->sellerRef || $terms->environment !== $command->environment
            || $command->newRevision !== $command->expectedRevision + 1 || $command->newRevision < 1
            || $command->inputsDigest === '' || strlen($command->inputsDigest) > 200) {
            OccurrenceProblems::refuse('schedule.invalid_revision');
        }
        $outcome = $this->runner->run(
            spec: $this->operations->spec(kind: 'schedule.apply_revision.v1', seller: $command->sellerRef,
                environment: $command->environment, key: $command->commandKey, payload: $command),
            body: function (TransactionContext $context) use ($command, $terms, $purpose): OccurrenceReceipt {
                $this->runner->assertCurrent(context: $context, moduleId: 'schedule');
                $rows = $this->store->active(ref: $command->scheduleRef, context: $context);
                $plan = $this->fingerprint($rows);
                $next = $this->resolver->resolve(terms: $terms, ordinal: 0);
                $created = false;
                if ($command->expectedRevision === 0) {
                    if ($command->expectedRowVersion !== 0 || $terms->action !== 'apply') {
                        OccurrenceProblems::refuse('schedule.revision_stale');
                    }
                    $created = $this->store->database($context)->table('billium_schedule_billing_schedule')->insertOrIgnore([
                        ...$this->store->scope($context), 'schedule_ref' => $command->scheduleRef, 'component_ref' => $terms->componentRef,
                        'purpose' => $purpose->value, 'current_revision' => 1, 'state' => 'active',
                        'cancel_generation' => $terms->cancellationGeneration, 'next_occurrence_at' => $next->start, 'runnable' => true]) === 1;
                }
                $context->lock(ScheduleLockRequests::schedule($command->scheduleRef));
                $head = $this->store->head(ref: $command->scheduleRef, context: $context)
                    ?? OccurrenceProblems::refuse('schedule.revision_stale');
                if (! $created && ($head->current_revision !== $command->expectedRevision
                    || $head->row_version !== $command->expectedRowVersion || $head->state === 'cancelled')) {
                    OccurrenceProblems::refuse('schedule.revision_stale');
                }
                if ($head->component_ref !== $terms->componentRef || $head->purpose !== $purpose->value
                    || $head->cancel_generation !== $terms->cancellationGeneration) {
                    OccurrenceProblems::refuse('schedule.revision_stale');
                }
                if ($this->fingerprint($this->store->active(ref: $command->scheduleRef, context: $context)) !== $plan) {
                    throw new LockPlanChanged;
                }
                $changes = [];
                $refs = [];
                foreach ($rows as $row) {
                    if (new DateTimeImmutable(Payload::text($row->period_start)) < $command->effectiveFrom) {
                        continue;
                    }
                    $replacement = $this->resolver->resolve(terms: $terms, ordinal: Payload::integer($row->ordinal));
                    $ensure = $this->ensureCommand(head: $head, terms: $terms, period: $replacement, revision: $command->newRevision,
                        digest: $command->inputsDigest, ordinal: Payload::integer($row->ordinal));
                    $replacementRef = $this->ref($ensure);
                    $changes[] = [$row, $ensure, $replacement, $replacementRef];
                    $refs[] = Payload::text($row->occurrence_ref);
                    $refs[] = $replacementRef;
                }
                $this->locks->acquire(context: $context, occurrenceRefs: $refs);
                // Posting may have won rank 50 while revision was planning. Re-plan this root, never supersede it.
                if ($this->fingerprint($this->store->active(ref: $command->scheduleRef, context: $context)) !== $plan) {
                    throw new LockPlanChanged;
                }
                $this->store->database($context)->table('billium_schedule_billing_schedule_revision')->insert([
                    'schedule_id' => $head->id, 'revision' => $command->newRevision, 'terms_digest' => $terms->digest(),
                    'inputs_digest' => $command->inputsDigest, 'terms' => Canonical::encode($terms->toArray()),
                    'effective_from' => OccurrenceTime::format($command->effectiveFrom)]);
                $ordinal = Payload::integer($head->cursor_ordinal);
                [, $applicableTerms, $next] = $this->applicableInterval(context: $context, head: $head, ordinal: $ordinal,
                    start: $this->cursorHint(context: $context, head: $head, ordinal: $ordinal, fallback: $next->start));
                // A resume effective after the parked period must bill that later due interval, not stay paused on it.
                [$ordinal, $applicableTerms, $next] = $this->skipPausedCursor(context: $context, head: $head, command: $command,
                    ordinal: $ordinal, applicableTerms: $applicableTerms, next: $next);
                $this->store->query(table: 'billing_schedule', context: $context)->where('id', $head->id)
                    ->update(['current_revision' => $command->newRevision, 'state' => 'active', 'runnable' => true,
                        'next_occurrence_at' => $next->start]);
                $head->current_revision = $command->newRevision;
                // Free all changed planned intervals first, so shifted adjacent replacements do not self-overlap.
                foreach ($changes as [$row,$ensure,$replacement,$replacementRef]) {
                    if ($terms->action === 'pause' && $row->state === 'planned') {
                        $this->store->query(table: 'billing_occurrence', context: $context)->where('id', $row->id)
                            ->update(['state' => 'cancelled', 'row_version' => $this->store->database($context)->raw('row_version+1')]);

                        continue;
                    }
                    if ($replacementRef !== $row->occurrence_ref && $row->state === 'planned') {
                        $this->store->query(table: 'billing_occurrence', context: $context)->where('id', $row->id)
                            ->update(['state' => 'superseded', 'superseded_by_occurrence_ref' => $replacementRef,
                                'row_version' => $this->store->database($context)->raw('row_version+1')]);
                    }
                }
                foreach ($changes as [$row,$ensure,$replacement,$replacementRef]) {
                    if ($terms->action === 'pause') {
                        $this->store->amendment(context: $context, row: $row, revision: $command->newRevision,
                            kind: $row->state === 'planned' ? 'cancelled' : 'amendment_required', detail: ['action' => 'pause']);

                        continue;
                    }
                    if ($replacementRef === $row->occurrence_ref && $row->inputs_digest === $command->inputsDigest) {
                        $this->store->amendment(context: $context, row: $row, revision: $command->newRevision, kind: 'revision_confirmed');

                        continue;
                    }
                    if ($replacementRef === $row->occurrence_ref || $row->state !== 'planned') {
                        $this->store->amendment(context: $context, row: $row, revision: $command->newRevision, kind: 'amendment_required',
                            detail: ['proposedOccurrenceRef' => $replacementRef, 'proposedInputsDigest' => $command->inputsDigest]);

                        continue;
                    }
                    $this->store->ensure(context: $context, command: $ensure, ref: $replacementRef, head: $head, evidence: $replacement->toArray());
                    $this->store->amendment(context: $context, row: $row, revision: $command->newRevision, kind: 'superseded_by',
                        detail: ['supersededByOccurrenceRef' => $replacementRef]);
                }
                $paused = $applicableTerms->action === 'pause';
                $version = $created ? 1 : Payload::integer($head->row_version) + 1;
                $this->store->query(table: 'billing_schedule', context: $context)->where('id', $head->id)->update([
                    'cursor_ordinal' => $ordinal, 'next_occurrence_at' => $paused ? null : $next->start, 'state' => $paused ? 'paused' : 'active',
                    'runnable' => ! $paused, 'row_version' => $version, 'attempts' => 0, 'retry_at' => null,
                    'claim_token' => null, 'claim_expires_at' => null]);
                $receipt = new OccurrenceReceipt(['scheduleRef' => $command->scheduleRef, 'scheduleRevision' => $command->newRevision,
                    'rowVersion' => $version, 'created' => $created]);
                $this->store->event(context: $context, kind: 'schedule.revision.applied.v1', body: $receipt->body());

                return $receipt;
            },
        )->orThrow();

        return new OccurrenceReceipt($outcome->body);
    }

    public function cancelSchedule(CancelSchedule $command): OccurrenceReceipt
    {
        $outcome = $this->runner->run(
            spec: $this->operations->spec(kind: 'schedule.cancel.v1', seller: $command->sellerRef,
                environment: $command->environment, key: $command->commandKey, payload: $command),
            body: function (TransactionContext $context) use ($command): OccurrenceReceipt {
                $this->runner->assertCurrent(context: $context, moduleId: 'schedule');
                $rows = $this->store->active(ref: $command->scheduleRef, context: $context);
                $context->lock(ScheduleLockRequests::schedule($command->scheduleRef));
                $head = $this->store->head(ref: $command->scheduleRef, context: $context);
                if ($head === null || ! in_array($head->state, ['active', 'paused'], true)) {
                    OccurrenceProblems::refuse('schedule.not_runnable');
                }
                if ($head->current_revision !== $command->expectedRevision || $head->row_version !== $command->expectedRowVersion
                    || $head->cancel_generation !== $command->expectedCancelGeneration) {
                    OccurrenceProblems::refuse('schedule.revision_stale');
                }
                $this->locks->acquire(context: $context, occurrenceRefs: array_map(static fn (stdClass $r): string => Payload::text($r->occurrence_ref), $rows));
                if ($this->fingerprint($rows) !== $this->fingerprint($this->store->active(ref: $command->scheduleRef, context: $context))) {
                    throw new LockPlanChanged;
                }
                foreach ($rows as $row) {
                    if ($row->state !== 'planned') {
                        continue;
                    }
                    $this->store->query(table: 'billing_occurrence', context: $context)->where('id', $row->id)->update([
                        'state' => 'cancelled', 'row_version' => $this->store->database($context)->raw('row_version+1')]);
                    $this->store->amendment(context: $context, row: $row, revision: Payload::integer($head->current_revision), kind: 'cancelled');
                }
                $this->store->query(table: 'billing_schedule', context: $context)->where('id', $head->id)->update([
                    'state' => 'cancelled', 'next_occurrence_at' => null, 'runnable' => false, 'cancel_generation' => $command->expectedCancelGeneration + 1,
                    'row_version' => $command->expectedRowVersion + 1, 'claim_token' => null, 'claim_expires_at' => null]);
                $receipt = new OccurrenceReceipt(['scheduleRef' => $command->scheduleRef, 'cancelGeneration' => $command->expectedCancelGeneration + 1]);
                $this->store->event(context: $context, kind: 'schedule.cancelled.v1', body: $receipt->body());

                return $receipt;
            },
        )->orThrow();

        return new OccurrenceReceipt($outcome->body);
    }

    public function materializeNext(MaterializeNext $command): OccurrenceReceipt
    {
        $outcome = $this->runner->run(
            spec: $this->operations->spec(kind: 'schedule.materialize.v1', seller: $command->sellerRef,
                environment: $command->environment, key: $command->commandKey, payload: $command),
            body: function (TransactionContext $context) use ($command): OccurrenceReceipt {
                $this->runner->assertCurrent(context: $context, moduleId: 'schedule');
                $context->lock(ScheduleLockRequests::schedule($command->scheduleRef));
                $head = $this->runnable(context: $context, ref: $command->scheduleRef);
                if ($head->row_version !== $command->expectedRowVersion || $head->cancel_generation !== $command->expectedCancelGeneration) {
                    OccurrenceProblems::refuse('schedule.revision_stale');
                }
                if (new DateTimeImmutable(Payload::text($head->next_occurrence_at)) > $command->asOf
                    || ($head->retry_at !== null && new DateTimeImmutable(Payload::text($head->retry_at)) > $command->asOf)) {
                    OccurrenceProblems::refuse('schedule.not_due');
                }
                $ordinal = Payload::integer($head->cursor_ordinal);
                [$revision, $terms, $period] = $this->applicableInterval(context: $context, head: $head, ordinal: $ordinal,
                    start: new DateTimeImmutable(Payload::text($head->next_occurrence_at)));
                if ($terms->action === 'pause') {
                    OccurrenceProblems::refuse('schedule.not_runnable');
                }
                $ensure = $this->ensureCommand(head: $head, terms: $terms, period: $period, revision: Payload::integer($revision->revision),
                    digest: Payload::text($revision->inputs_digest), ordinal: $ordinal);
                $ref = $this->ref($ensure);
                $this->locks->acquire(context: $context, occurrenceRefs: [$ref]);
                $existing = $this->store->occurrence(ref: $ref, context: $context);
                // Revision processing retains rated and obligated intervals for amendment.
                $retained = $existing !== null && $existing->schedule_id === $head->id && $existing->ordinal === $ordinal
                    && in_array($existing->state, ['rated', 'obligated'], true);
                $receipt = $retained
                    ? $this->store->receipt(row: $existing, created: false)
                    : $this->store->ensure(context: $context, command: $ensure, ref: $ref, head: $head, evidence: $period->toArray());
                $complete = $ordinal === 100000;
                $paused = false;
                $next = null;
                if (! $complete) {
                    $candidate = $this->resolver->resolve(terms: $terms, ordinal: $ordinal + 1);
                    [, $nextTerms, $nextPeriod] = $this->applicableInterval(context: $context, head: $head, ordinal: $ordinal + 1,
                        start: new DateTimeImmutable($candidate->start));
                    $paused = $nextTerms->action === 'pause';
                    $next = $paused ? null : $nextPeriod->start;
                }
                $this->store->query(table: 'billing_schedule', context: $context)->where('id', $head->id)->update([
                    'cursor_ordinal' => $complete ? $ordinal : $ordinal + 1, 'next_occurrence_at' => $next,
                    'state' => $complete ? 'completed' : ($paused ? 'paused' : 'active'), 'runnable' => ! $complete && ! $paused, 'attempts' => 0, 'retry_at' => null,
                    'claim_token' => null, 'claim_expires_at' => null, 'row_version' => $command->expectedRowVersion + 1]);
                $result = new OccurrenceReceipt([...$receipt->body(), 'cursorOrdinal' => $complete ? $ordinal : $ordinal + 1,
                    'nextOccurrenceAt' => $next === null ? null : OccurrenceTime::format(new DateTimeImmutable($next)), 'rowVersion' => $command->expectedRowVersion + 1]);
                $this->store->event(context: $context, kind: 'schedule.materialized.v1', body: $result->body());

                return $result;
            },
        )->orThrow();

        return new OccurrenceReceipt($outcome->body);
    }

    public function recordFailure(RecordScheduleFailure $command): OccurrenceReceipt
    {
        $outcome = $this->runner->run(
            spec: $this->operations->spec(kind: 'schedule.record_failure.v1', seller: $command->sellerRef,
                environment: $command->environment, key: $command->commandKey, payload: $command),
            body: function (TransactionContext $context) use ($command): OccurrenceReceipt {
                $this->runner->assertCurrent(context: $context, moduleId: 'schedule');
                $context->lock(ScheduleLockRequests::schedule($command->scheduleRef));
                $head = $this->runnable(context: $context, ref: $command->scheduleRef);
                if ($head->row_version !== $command->expectedRowVersion) {
                    OccurrenceProblems::refuse('schedule.revision_stale');
                }
                $attempts = Payload::integer($head->attempts) + 1;
                $delay = min(2 ** min($attempts, 6) * 60, 3600);
                $retry = OccurrenceTime::format($command->asOf->modify('+'.$delay.' seconds'));
                $this->store->query(table: 'billing_schedule', context: $context)->where('id', $head->id)->update([
                    'attempts' => $attempts, 'retry_at' => $retry, 'claim_token' => null, 'claim_expires_at' => null,
                    'row_version' => $command->expectedRowVersion + 1]);
                $receipt = new OccurrenceReceipt(['scheduleRef' => $command->scheduleRef, 'attempts' => $attempts, 'retryAt' => $retry]);
                $this->store->event(context: $context, kind: 'schedule.failure_recorded.v1', body: $receipt->body());

                return $receipt;
            },
        )->orThrow();

        return new OccurrenceReceipt($outcome->body);
    }

    private function cursorHint(TransactionContext $context, stdClass $head, int $ordinal, string $fallback): DateTimeImmutable
    {
        if ($head->next_occurrence_at !== null) {
            return new DateTimeImmutable(Payload::text($head->next_occurrence_at));
        }
        $row = $this->store->database($context)->table('billium_schedule_billing_schedule_revision')
            ->where('schedule_id', $head->id)->where('revision', Payload::integer($head->current_revision))->first();
        if ($row === null) {
            return new DateTimeImmutable($fallback);
        }
        $previous = ScheduleRevisionTerms::fromArray(Payload::decode($row->terms));

        return new DateTimeImmutable($this->resolver->resolve(terms: $previous, ordinal: $ordinal)->start);
    }

    /** @return array{int, ScheduleRevisionTerms, ResolvedInterval} */
    private function skipPausedCursor(TransactionContext $context, stdClass $head, ApplyScheduleRevision $command, int $ordinal, ScheduleRevisionTerms $applicableTerms, ResolvedInterval $next): array
    {
        if ($head->state !== 'paused' || $applicableTerms->action !== 'pause' || $command->terms->action !== 'resume'
            || ! $this->sameCalendar($applicableTerms, $command->terms)
            || $command->effectiveFrom <= new DateTimeImmutable($next->start)) {
            return [$ordinal, $applicableTerms, $next];
        }
        $hint = $next;
        $previousStart = new DateTimeImmutable($next->start);
        for ($candidate = $ordinal + 1; $candidate <= 100000; $candidate++) {
            [, $laterTerms, $later] = $this->applicableInterval(context: $context, head: $head, ordinal: $candidate,
                start: new DateTimeImmutable($hint->end));
            $laterStart = new DateTimeImmutable($later->start);
            if ($laterStart <= $previousStart || ! $this->sameCalendar($applicableTerms, $laterTerms)) {
                break;
            }
            if ($laterTerms->action !== 'pause') {
                return [$candidate, $laterTerms, $later];
            }
            $hint = $later;
            $previousStart = $laterStart;
        }

        return [$ordinal, $applicableTerms, $next];
    }

    private function sameCalendar(ScheduleRevisionTerms $left, ScheduleRevisionTerms $right): bool
    {
        return $left->anchorLocal === $right->anchorLocal && $left->policy->timezone === $right->policy->timezone
            && $left->policy->frequency === $right->policy->frequency && $left->policy->intervalCount === $right->policy->intervalCount
            && $left->policy->endOfMonth === $right->policy->endOfMonth && $left->policy->dstGapPolicy === $right->policy->dstGapPolicy
            && $left->policy->dstFoldPolicy === $right->policy->dstFoldPolicy;
    }

    private function applicableRevision(TransactionContext $context, stdClass $head, DateTimeImmutable $periodStart): stdClass
    {
        $query = $this->store->database($context)->table('billium_schedule_billing_schedule_revision')->where('schedule_id', $head->id);

        return (clone $query)->where('effective_from', '<=', OccurrenceTime::format($periodStart))->orderByDesc('revision')->first()
            ?? $query->orderBy('revision')->first()
            ?? throw new LogicException('Schedule revision missing');
    }

    /** @return array{stdClass, ScheduleRevisionTerms, ResolvedInterval} */
    private function applicableInterval(TransactionContext $context, stdClass $head, int $ordinal, DateTimeImmutable $start): array
    {
        $revision = $this->applicableRevision(context: $context, head: $head, periodStart: $start);
        $terms = ScheduleRevisionTerms::fromArray(Payload::decode($revision->terms));
        $period = $this->resolver->resolve(terms: $terms, ordinal: $ordinal);
        $applicable = $this->applicableRevision(context: $context, head: $head, periodStart: new DateTimeImmutable($period->start));
        $terms = ScheduleRevisionTerms::fromArray(Payload::decode($applicable->terms));

        return [$applicable, $terms, $this->resolver->resolve(terms: $terms, ordinal: $ordinal)];
    }

    private function runnable(TransactionContext $context, string $ref): stdClass
    {
        $head = $this->store->head(ref: $ref, context: $context);
        if ($head === null || $head->state !== 'active' || (bool) $head->runnable === false) {
            OccurrenceProblems::refuse('schedule.not_runnable');
        }

        return $head;
    }

    private function validate(EnsureOccurrence $c): void
    {
        if ($c->periodEnd <= $c->periodStart || $c->scheduleRevision < 1 || $c->ordinal < 0 || $c->ordinal > 100000
            || (($c->purpose === OccurrencePurpose::Proration) !== ($c->parentOccurrenceRef !== null))) {
            OccurrenceProblems::refuse('occurrence.invalid_interval_or_lineage');
        }
        foreach ([$c->sellerRef, $c->environment, $c->componentRef, $c->scheduleRef, $c->inputsDigest] as $ref) {
            if ($ref === '' || strlen($ref) > 200) {
                OccurrenceProblems::refuse('occurrence.invalid_reference');
            }
        }
    }

    private function ref(EnsureOccurrence $c): string
    {
        return OccurrenceIdentity::ref(seller: $c->sellerRef, env: $c->environment, component: $c->componentRef,
            purpose: $c->purpose->value, start: $c->periodStart, end: $c->periodEnd);
    }

    /** @param list<stdClass> $rows */
    private function fingerprint(array $rows): string
    {
        return Canonical::digest(array_map(static fn (stdClass $r): array => [$r->id, $r->state, $r->row_version], $rows));
    }

    private function ensureCommand(stdClass $head, ScheduleRevisionTerms $terms, ResolvedInterval $period, int $revision, string $digest, int $ordinal): EnsureOccurrence
    {
        return new EnsureOccurrence(sellerRef: $terms->sellerRef, environment: $terms->environment, componentRef: $terms->componentRef,
            purpose: OccurrencePurpose::from(Payload::text($head->purpose)), periodStart: new DateTimeImmutable($period->start),
            periodEnd: new DateTimeImmutable($period->end), scheduleRef: Payload::text($head->schedule_ref),
            scheduleRevision: $revision, inputsDigest: $digest, ordinal: $ordinal);
    }
}
