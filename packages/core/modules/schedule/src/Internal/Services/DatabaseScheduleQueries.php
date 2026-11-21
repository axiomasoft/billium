<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal\Services;

use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Schedule\Contracts\ScheduleQueries;
use Billium\Schedule\Data\DueBatch;
use Billium\Schedule\Data\OccurrenceView;
use Billium\Schedule\Enums\OccurrenceState;
use Billium\Schedule\Internal\Canonical;
use Billium\Schedule\Internal\Errors\OccurrenceProblems;
use Billium\Schedule\Internal\OccurrenceTime;
use Billium\Schedule\Internal\Payload;
use Billium\Schedule\Internal\Storage\OccurrenceStore;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use JsonException;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class DatabaseScheduleQueries implements ScheduleQueries
{
    public function __construct(private OccurrenceStore $store, private CallerContext $callers) {}

    public function occurrence(string $occurrenceRef): ?OccurrenceView
    {
        $row = $this->store->occurrence(ref: $occurrenceRef, activeOnly: false);
        if ($row === null) {
            return null;
        }
        $revisions = $this->store->database()->table('billium_schedule_billing_schedule_revision')
            ->where('schedule_id', $row->schedule_id);
        $applicable = (clone $revisions)->where('effective_from', '<=', $row->period_start)->orderByDesc('revision')->first()
            ?? $revisions->orderBy('revision')->first();
        $created = (clone $revisions)->where('revision', $row->created_revision)->first();
        // revision_confirmed records an identical occurrence identity (including its interval) and inputs digest.
        // Also pin the original terms: a caller must not reuse an inputs digest for changed commercial terms.
        $confirmed = $applicable !== null && $created !== null && $applicable->terms_digest === $created->terms_digest
            && $this->store->query('occurrence_amendment')->where('occurrence_ref', $row->occurrence_ref)
                ->where('schedule_revision', $applicable->revision)->where('kind', 'revision_confirmed')->exists();
        $action = $applicable === null ? null : (Payload::decode($applicable->terms)['action'] ?? null);

        return new OccurrenceView(
            occurrenceRef: Payload::text($row->occurrence_ref),
            sellerRef: Payload::text($row->seller_ref),
            environment: Payload::text($row->environment),
            componentRef: Payload::text($row->component_ref),
            state: OccurrenceState::from(Payload::text($row->state)),
            periodStart: new DateTimeImmutable(Payload::text($row->period_start)),
            periodEnd: new DateTimeImmutable(Payload::text($row->period_end)),
            inputsDigest: Payload::text($row->inputs_digest),
            createdRevision: Payload::integer($row->created_revision),
            ratedBasisRef: $row->rated_basis_ref === null ? null : Payload::text($row->rated_basis_ref),
            applicableRevision: $applicable === null ? null : Payload::integer($applicable->revision),
            applicableRevisionConfirmed: $confirmed,
            applicableInputsDigest: $applicable === null ? null : Payload::text($applicable->inputs_digest),
            applicableAction: $action === null ? null : Payload::text($action),
        );
    }

    public function dueSchedules(string $sellerRef, DateTimeImmutable $asOf, int $limit = 100, ?string $cursor = null): DueBatch
    {
        if ($limit < 1 || $limit > 500) {
            OccurrenceProblems::refuse('schedule.batch_limit');
        }
        $query = $this->due(seller: $sellerRef, asOf: $asOf);
        if ($cursor !== null) {
            try {
                $decoded = base64_decode($cursor, strict: true);
                $data = $decoded === false ? [] : Payload::decode($decoded);
            } catch (JsonException) {
                OccurrenceProblems::refuse('schedule.cursor_invalid');
            }
            $caller = $this->callers->current();
            if (($data['seller'] ?? null) !== $sellerRef || ($data['environment'] ?? null) !== $caller->environment
                || ($data['asOf'] ?? null) !== OccurrenceTime::format($asOf) || ! is_string($data['at'] ?? null) || ! is_int($data['id'] ?? null)) {
                OccurrenceProblems::refuse('schedule.cursor_invalid');
            }
            $query->whereRaw('(next_occurrence_at,id)>(?::timestamptz,?::bigint)', [$data['at'], $data['id']]);
        }
        $rows = $query->orderBy('next_occurrence_at')->orderBy('id')->limit($limit + 1)->get()->all();
        $more = count($rows) > $limit;
        if ($more) {
            array_pop($rows);
        }
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        $next = ! $more || $last === null ? null : base64_encode(Canonical::encode([
            'seller' => $sellerRef, 'environment' => $this->callers->current()->environment, 'asOf' => OccurrenceTime::format($asOf),
            'at' => $last->next_occurrence_at, 'id' => $last->id]));

        return new DueBatch(schedules: array_values(array_map(static fn (stdClass $r): array => Payload::map(get_object_vars($r)), $rows)), cursor: $next);
    }

    public function dueStats(string $sellerRef, DateTimeImmutable $asOf): array
    {
        $query = $this->due(seller: $sellerRef, asOf: $asOf);
        $row = $query->selectRaw('count(*) AS due_count, min(next_occurrence_at) AS oldest')->first();
        $oldest = $row?->oldest;

        return ['dueCount' => $row === null ? 0 : Payload::integer($row->due_count),
            'oldestDueAgeSeconds' => $oldest === null ? 0 : max(0, $asOf->getTimestamp() - new DateTimeImmutable(Payload::text($oldest))->getTimestamp())];
    }

    public function correctionHead(string $sellerRef, string $parentOccurrenceRef): array
    {
        $this->assertSeller($sellerRef);
        $row = $this->store->query('correction_head')->where('parent_occurrence_ref', $parentOccurrenceRef)->first();

        return ['revision' => $row === null ? 0 : Payload::integer($row->head_revision),
            'frontierDigest' => $row === null || $row->head_frontier_digest === null ? null : Payload::text($row->head_frontier_digest)];
    }

    private function due(string $seller, DateTimeImmutable $asOf): Builder
    {
        $this->assertSeller($seller);

        return $this->store->query('billing_schedule')->where('state', 'active')->where('runnable', true)
            ->where('next_occurrence_at', '<=', OccurrenceTime::format($asOf))
            ->where(static fn (Builder $q): Builder => $q->whereNull('claim_expires_at')->orWhere('claim_expires_at', '<', OccurrenceTime::format($asOf)))
            ->where(static fn (Builder $q): Builder => $q->whereNull('retry_at')->orWhere('retry_at', '<=', OccurrenceTime::format($asOf)));
    }

    private function assertSeller(string $seller): void
    {
        if ($seller !== $this->callers->current()->sellerRef) {
            OccurrenceProblems::refuse('schedule.scope_denied');
        }
    }
}
