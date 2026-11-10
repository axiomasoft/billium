<?php

declare(strict_types=1);

namespace Billium\Schedule\Internal\Storage;

use Billium\Core\Contracts\CallerContext;
use Billium\Core\Contracts\TransactionContext;
use Billium\Schedule\Data\Commands\EnsureOccurrence;
use Billium\Schedule\Data\Commands\RegisterCorrectionChild;
use Billium\Schedule\Data\OccurrenceReceipt;
use Billium\Schedule\Internal\Canonical;
use Billium\Schedule\Internal\Errors\OccurrenceProblems;
use Billium\Schedule\Internal\OccurrenceTime;
use Billium\Schedule\Internal\Payload;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use LogicException;
use stdClass;

final readonly class OccurrenceStore
{
    public function __construct(private DatabaseManager $databases, private CallerContext $callers) {}

    public function database(?TransactionContext $context = null): Connection
    {
        $name = $context?->connectionName() ?? config('billium.connection') ?? config('database.default');
        if (! is_string($name)) {
            throw new LogicException('Schedule connection is required');
        }

        return $this->databases->connection($name);
    }

    public function query(string $table, ?TransactionContext $context = null): Builder
    {
        $caller = $context?->caller() ?? $this->callers->current();

        return $this->database($context)->table('billium_schedule_'.$table)
            ->where(['seller_ref' => $caller->sellerRef, 'environment' => $caller->environment]);
    }

    public function head(string $ref, ?TransactionContext $context = null): ?stdClass
    {
        return $this->query(table: 'billing_schedule', context: $context)->where('schedule_ref', $ref)->first();
    }

    /** @return list<stdClass> */
    public function active(string $ref, ?TransactionContext $context = null): array
    {
        $head = $this->head(ref: $ref, context: $context);
        if ($head === null) {
            return [];
        }

        return array_values($this->query(table: 'billing_occurrence', context: $context)
            ->where('schedule_id', $head->id)->whereIn('state', ['planned', 'rated', 'obligated'])
            ->orderBy('occurrence_ref')->get()->all());
    }

    public function occurrence(string $ref, ?TransactionContext $context = null, bool $activeOnly = true): ?stdClass
    {
        $query = $this->query(table: 'billing_occurrence', context: $context)->where('occurrence_ref', $ref);
        if ($activeOnly) {
            $query->whereIn('state', ['planned', 'rated', 'obligated']);
        }

        return $query->orderByDesc('incarnation')->first();
    }

    /** @param array<string, mixed> $evidence */
    public function ensure(TransactionContext $context, EnsureOccurrence $command, string $ref, stdClass $head, array $evidence = []): OccurrenceReceipt
    {
        $db = $this->database($context);
        $db->statement('SAVEPOINT billium_occurrence_insert');
        try {
            $row = $db->selectOne(<<<'SQL'
                INSERT INTO public.billium_schedule_billing_occurrence
                    (seller_ref,environment,occurrence_ref,incarnation,component_ref,purpose,period_start,period_end,
                     schedule_id,created_revision,inputs_digest,state,parent_occurrence_ref,cancel_generation,ordinal,boundary_evidence)
                SELECT ?,?,?,COALESCE(MAX(incarnation),0)+1,?,?,?,?,?,?,?,'planned',?,?,?,?::jsonb
                FROM public.billium_schedule_billing_occurrence
                WHERE seller_ref=? AND environment=? AND occurrence_ref=?
                ON CONFLICT (seller_ref,environment,occurrence_ref) WHERE state IN ('planned','rated','obligated')
                DO NOTHING RETURNING *
                SQL, [$command->sellerRef, $command->environment, $ref, $command->componentRef, $command->purpose->value,
                OccurrenceTime::format($command->periodStart), OccurrenceTime::format($command->periodEnd),
                $head->id, $command->scheduleRevision, $command->inputsDigest, $command->parentOccurrenceRef,
                $head->cancel_generation, $command->ordinal, Canonical::encode((object) $evidence),
                $command->sellerRef, $command->environment, $ref]);
        } catch (QueryException $error) {
            $db->statement('ROLLBACK TO SAVEPOINT billium_occurrence_insert');
            $db->statement('RELEASE SAVEPOINT billium_occurrence_insert');
            if (! in_array($error->errorInfo[0] ?? null, ['23P01', '23505'], true)) {
                throw $error;
            }
            $existing = $this->occurrence(ref: $ref, context: $context);
            if ($existing !== null) {
                return $this->existing(command: $command, row: $existing);
            }
            OccurrenceProblems::refuse(detail: 'occurrence.overlap', stored: true);
        }
        $db->statement('RELEASE SAVEPOINT billium_occurrence_insert');
        if ($row === null) {
            $existing = $this->occurrence(ref: $ref, context: $context)
                ?? throw new LogicException('Occurrence arbiter returned no active row');

            return $this->existing(command: $command, row: $existing);
        }
        if (! $row instanceof stdClass) {
            throw new LogicException('Occurrence insert did not return a row');
        }
        $receipt = $this->receipt(row: $row, created: true);
        $this->event(context: $context, kind: 'schedule.occurrence.ensured.v1', body: $receipt->body());

        return $receipt;
    }

    public function existing(EnsureOccurrence $command, stdClass $row): OccurrenceReceipt
    {
        if ($row->inputs_digest !== $command->inputsDigest) {
            OccurrenceProblems::refuse(detail: 'occurrence.inputs_changed', stored: true);
        }

        return $this->receipt(row: $row, created: false);
    }

    public function receipt(stdClass $row, bool $created): OccurrenceReceipt
    {
        return new OccurrenceReceipt(['occurrenceRef' => $row->occurrence_ref, 'incarnation' => $row->incarnation,
            'created' => $created, 'inputsDigest' => $row->inputs_digest, 'state' => $row->state,
            'periodStart' => OccurrenceTime::format(new \DateTimeImmutable(Payload::text($row->period_start))),
            'periodEnd' => OccurrenceTime::format(new \DateTimeImmutable(Payload::text($row->period_end)))]);
    }

    /** @param array<string, mixed> $body */
    public function event(TransactionContext $context, string $kind, array $body): void
    {
        $this->database($context)->table('billium_schedule_occurrence_outbox')->insert([
            ...$this->scope($context), 'event_kind' => $kind, 'body' => Canonical::encode((object) $body)]);
    }

    /** @param array<string, mixed> $detail */
    public function amendment(TransactionContext $context, stdClass $row, int $revision, string $kind, array $detail = []): void
    {
        $this->database($context)->table('billium_schedule_occurrence_amendment')->insert([
            ...$this->scope($context), 'occurrence_ref' => $row->occurrence_ref, 'schedule_revision' => $revision,
            'kind' => $kind, 'detail' => Canonical::encode((object) $detail)]);
        if ($kind === 'amendment_required') {
            $this->event(context: $context, kind: 'schedule.occurrence.amendment_required.v1',
                body: ['occurrenceRef' => $row->occurrence_ref, 'scheduleRevision' => $revision, ...$detail]);
        }
    }

    /** @return array{seller_ref: string, environment: string} */
    public function scope(TransactionContext $context): array
    {
        return ['seller_ref' => $context->caller()->sellerRef, 'environment' => $context->caller()->environment];
    }

    public function child(TransactionContext $context, string $parent, string $frontier): ?stdClass
    {
        return $this->query(table: 'billing_correction', context: $context)
            ->where(['parent_occurrence_ref' => $parent, 'source_frontier_digest' => $frontier])->first();
    }

    public function correctionHead(TransactionContext $context, string $parent): stdClass
    {
        $this->database($context)->table('billium_schedule_correction_head')->insertOrIgnore([
            ...$this->scope($context), 'parent_occurrence_ref' => $parent]);

        return $this->query(table: 'correction_head', context: $context)->where('parent_occurrence_ref', $parent)->first()
            ?? throw new LogicException('Correction head missing under parent lock');
    }

    public function registerChild(TransactionContext $context, RegisterCorrectionChild $c, stdClass $parent, string $ref, string $digest): void
    {
        $this->database($context)->table('billium_schedule_billing_correction')->insert([
            ...$this->scope($context), 'correction_ref' => $ref, 'purpose' => 'correction',
            'parent_occurrence_id' => $parent->id, 'parent_occurrence_ref' => $c->parentOccurrenceRef,
            'original_period_ref' => 'period:'.$c->parentOccurrenceRef,
            'original_period_start' => OccurrenceTime::format($c->originalPeriodStart), 'original_period_end' => OccurrenceTime::format($c->originalPeriodEnd),
            'correction_revision' => $c->correctionRevision, 'predecessor_revision' => $c->predecessorRevision,
            'predecessor_rated_basis_ref' => $c->predecessorRatedBasisRef, 'source_frontier_digest' => $c->sourceFrontierDigest,
            'tax_correction_policy_ref' => $c->taxCorrectionPolicyRef, 'tax_correction_policy_version' => $c->taxCorrectionPolicyVersion,
            'reason_code' => $c->reasonCode, 'obligation_basis_ref' => $c->obligationBasisRef, 'registered_digest' => $digest]);
        $changed = $this->query(table: 'correction_head', context: $context)
            ->where(['parent_occurrence_ref' => $c->parentOccurrenceRef, 'head_revision' => $c->expectedHeadRevision])
            ->where('head_frontier_digest', $c->expectedHeadFrontierDigest)->update([
                'head_revision' => $c->correctionRevision, 'head_frontier_digest' => $c->sourceFrontierDigest,
                'row_version' => $this->database($context)->raw('row_version+1')]);
        if ($changed !== 1) {
            OccurrenceProblems::refuse('correction.frontier_stale');
        }
    }
}
