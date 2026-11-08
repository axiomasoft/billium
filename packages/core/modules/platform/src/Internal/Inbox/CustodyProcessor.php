<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Inbox;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Exceptions\LockPlanChanged;
use Billium\Platform\Contracts\CustodyHandler;
use Billium\Platform\Data\Inbox\CustodyDisposition;
use Billium\Platform\Data\Inbox\CustodyResult;
use Billium\Platform\Data\Inbox\HandlerOutcome;
use Billium\Platform\Data\Inbox\HandlerOutcomeKind;
use Billium\Platform\Internal\Feed\PartitionRow;
use Billium\Platform\Internal\Feed\PartitionStore;
use Billium\Platform\Internal\Installation\Rows;
use Closure;
use Illuminate\Database\Connection;
use LogicException;
use stdClass;

/**
 * Source-scoped observation custody inside the caller's open root Tx (INV-08, REQ-FAIL-12/13/23).
 *
 * Order of one root: partition lock (rank 30) → immutable delivery claim → frontier decision → at most ONE handler call
 * (economic apply) for the delivery that is next in order. Further sequences are drained by new roots; no handler loop
 * ever discovers lower-rank locks after funds. The custody frontier advances over contiguous durable arrivals; the
 * accounting frontier advances over durable dispositions in source order, a dependency-parked one included, so a parked
 * observation never blocks its partition.
 */
final readonly class CustodyProcessor
{
    /** @param Closure(): OperationRunner $runner */
    public function __construct(
        private PartitionStore $partitions,
        private Closure $runner,
        private int $retryBackoffSeconds = 5,
        private int $retryBudget = 10,
    ) {}

    public function process(TransactionContext $context, string $channel, stdClass $envelope, CustodyHandler $handler): CustodyResult
    {
        ($this->runner)()->assertCurrent($context, 'platform');
        $e = InboxEnvelope::from($channel, $envelope);
        $e->authorize($context->caller());
        $db = $this->partitions->connection($context);

        $partition = $this->partitions->live($db, $e->channel, $e->sourceAuthority, $e->sellerRef, $e->environment, $e->partitionRef);
        if ($partition !== null) {
            $partition = $this->partitions->lock($context, $partition);
        } elseif ($e->sourceSequence === 1 && ! $this->bootstrapOpen($db, $e) && ! $this->retiredNamespace($db, $e)) {
            // The first delivery of a namespace opens its partition (cursor 0); anything later waits for earlier sequences.
            $partition = $this->partitions->create($db, $e->channel, $e->sourceAuthority, $e->sourceLineage, $e->sellerRef, $e->environment, $e->partitionRef);
            if ($partition === null) {
                $live = $this->partitions->live($db, $e->channel, $e->sourceAuthority, $e->sellerRef, $e->environment, $e->partitionRef);
                $partition = $live === null ? throw new LockPlanChanged('Concurrent partition creation') : $this->partitions->lock($context, $live);
            }
            $this->partitions->attachParkedGaps($db, $partition);
        }

        $existing = $this->identity($db, $e);
        if ($existing !== null) {
            return $this->duplicate($db, $e, $existing);
        }

        $status = $this->decide($db, $e, $partition);
        $record = $db->selectOne(<<<'SQL'
            INSERT INTO public.billium_inbox_message
                (channel, source_authority, source_lineage, seller_ref, environment, external_event_id, content_digest, payload,
                 partition_id, partition_ref, source_sequence, operation_ref, operation_revision, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (channel, source_authority, source_lineage, seller_ref, environment, external_event_id) DO NOTHING
            RETURNING *
            SQL, [
            $e->channel, $e->sourceAuthority, $e->sourceLineage, $e->sellerRef, $e->environment, $e->eventId, $e->contentDigest, $e->json,
            $partition?->id, $e->partitionRef, $e->sourceSequence,
            $e->operationRef, $e->operationRevision, $status,
        ]);
        if ($record === null) {
            // A concurrent root claimed the same identity (no partition to serialise on): replay it.
            return $this->duplicate($db, $e, $this->identity($db, $e) ?? throw new LogicException('Delivery claim conflict without a committed row'));
        }
        $id = Rows::int(Rows::field($record, 'id'));

        if ($partition === null || $status === 'held_recovery') {
            return new CustodyResult($status === 'held_recovery' ? CustodyDisposition::RecoveryRequired : CustodyDisposition::ParkedGap, $id, $status);
        }
        $this->partitions->extendCustody($db, $partition);
        $partition = $this->partitions->find($db, $partition->id, $e->sellerRef, $e->environment) ?? throw new LogicException('Locked partition vanished');
        if ($status === 'parked_gap') {
            return new CustodyResult(CustodyDisposition::ParkedGap, $id, $status);
        }
        if (! $context->spec()->mode->financial()) {
            // Raw custody commits even while the financial session is sealed; a financial root applies it later.
            return new CustodyResult(CustodyDisposition::Received, $id, $status, null, $partition->id);
        }

        return $this->apply($context, $db, $partition, $record, $handler);
    }

    /** Applies the single delivery at accounted_sequence + 1, if it is in custody. */
    public function drain(TransactionContext $context, int $partitionId, CustodyHandler $handler): CustodyResult
    {
        ($this->runner)()->assertCurrent($context, 'platform');
        if (! $context->spec()->mode->financial()) {
            throw new LogicException('Economic apply needs a financial root; raw custody never applies');
        }
        $caller = $context->caller();
        $db = $this->partitions->connection($context);
        $planned = $this->partitions->find($db, $partitionId, $caller->sellerRef, $caller->environment)
            ?? throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown partition');
        $partition = $this->partitions->lock($context, $planned);
        if (! $partition->active()) {
            return new CustodyResult(CustodyDisposition::Idle);
        }
        $record = $db->selectOne(<<<'SQL'
            SELECT * FROM public.billium_inbox_message
            WHERE partition_id = ? AND source_sequence = ? AND status IN ('ready', 'parked_gap')
            SQL, [$partition->id, $partition->accountedSequence + 1]);

        return $record === null ? new CustodyResult(CustodyDisposition::Idle) : $this->apply($context, $db, $partition, $record, $handler);
    }

    /** Retries the earliest parked dependency of one operation; an earlier parked revision blocks a later one. */
    public function retryDependency(TransactionContext $context, int $inboxId, CustodyHandler $handler): CustodyResult
    {
        ($this->runner)()->assertCurrent($context, 'platform');
        if (! $context->spec()->mode->financial()) {
            throw new LogicException('Economic apply needs a financial root; raw custody never applies');
        }
        $caller = $context->caller();
        $db = $this->partitions->connection($context);
        $planned = $db->selectOne('SELECT * FROM public.billium_inbox_message WHERE id = ? AND seller_ref = ? AND environment = ?', [$inboxId, $caller->sellerRef, $caller->environment])
            ?? throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown inbox message');
        $partitionId = Rows::field($planned, 'partition_id');
        if ($partitionId === null) {
            return new CustodyResult(CustodyDisposition::Idle, $inboxId, Rows::string(Rows::field($planned, 'status')));
        }
        $partitionRow = $this->partitions->find($db, Rows::int($partitionId), $caller->sellerRef, $caller->environment) ?? throw new LockPlanChanged('Partition is gone');
        if ($partitionRow->state !== 'retired') {
            $this->partitions->lock($context, $partitionRow);
        }
        $record = $db->selectOne('SELECT * FROM public.billium_inbox_message WHERE id = ?', [$inboxId]);
        if ($record === null || Rows::field($record, 'status') !== 'parked_dependency') {
            return new CustodyResult(CustodyDisposition::Idle, $inboxId, $record === null ? null : Rows::string(Rows::field($record, 'status')));
        }
        $earlier = $this->earlierParked($db, $record);
        if ($earlier !== null) {
            return new CustodyResult(CustodyDisposition::ParkedDependency, $inboxId, 'parked_dependency', null, null, $earlier);
        }
        if (Rows::int(Rows::field($record, 'attempts')) >= $this->retryBudget) {
            $db->update("UPDATE public.billium_inbox_message SET last_error_class = 'retry_budget_exhausted' WHERE id = ?", [$inboxId]);

            return new CustodyResult(CustodyDisposition::ParkedDependency, $inboxId, 'parked_dependency');
        }
        $outcome = $handler->handle($context, InboxRecords::row($record), InboxRecords::payload($record));
        $result = $this->dispose($db, $record, $outcome);
        if ($result->status === 'applied') {
            $result = $result->withDrain(null, $this->laterParked($db, $record));
        }

        return $result;
    }

    private function apply(TransactionContext $context, Connection $db, PartitionRow $partition, mixed $record, CustodyHandler $handler): CustodyResult
    {
        $sequence = Rows::int(Rows::field($record, 'source_sequence'));
        if ($sequence !== $partition->accountedSequence + 1 || ! $partition->active()) {
            throw new LogicException('Only the next accounted sequence of an active partition is applied');
        }
        $blocking = $this->earlierParked($db, $record);
        // Per-operation order: a later revision never overtakes an earlier parked one, yet the source order still advances.
        $outcome = $blocking !== null
            ? HandlerOutcome::parkedDependency('operation_revision', 'inbox:'.$blocking)
            : $handler->handle($context, InboxRecords::row($record), InboxRecords::payload($record));
        $result = $this->dispose($db, $record, $outcome);
        $this->partitions->account($db, $partition, $sequence);
        $next = $db->selectOne(<<<'SQL'
            SELECT 1 AS present FROM public.billium_inbox_message
            WHERE partition_id = ? AND source_sequence = ? AND status IN ('ready', 'parked_gap')
            SQL, [$partition->id, $sequence + 1]);

        return $result->withDrain($next === null ? null : $partition->id, $result->status === 'applied' ? $this->laterParked($db, $record) : null);
    }

    /** Records the handler's durable disposition of one delivery. */
    private function dispose(Connection $db, mixed $record, HandlerOutcome $outcome): CustodyResult
    {
        $id = Rows::int(Rows::field($record, 'id'));

        return match ($outcome->kind) {
            HandlerOutcomeKind::Applied => $this->applied($db, $id, $outcome),
            HandlerOutcomeKind::ParkedDependency => $this->parked($db, $id, $outcome),
            HandlerOutcomeKind::Conflict => $this->quarantined($db, $id, $outcome),
        };
    }

    private function applied(Connection $db, int $id, HandlerOutcome $outcome): CustodyResult
    {
        $db->update(<<<'SQL'
            UPDATE public.billium_inbox_message SET status = 'applied', result_ref = ?, applied_at = now(), attempts = attempts + 1,
                next_attempt_at = NULL, park_kind = NULL, park_ref = NULL, last_error_class = NULL
            WHERE id = ?
            SQL, [$outcome->reference, $id]);

        return new CustodyResult(CustodyDisposition::Applied, $id, 'applied', $outcome->reference);
    }

    private function parked(Connection $db, int $id, HandlerOutcome $outcome): CustodyResult
    {
        $db->update(<<<'SQL'
            UPDATE public.billium_inbox_message SET status = 'parked_dependency', park_kind = ?, park_ref = ?, attempts = attempts + 1,
                next_attempt_at = now() + make_interval(secs => ?)
            WHERE id = ?
            SQL, [$outcome->dependencyKind, $outcome->reference, $this->retryBackoffSeconds, $id]);

        return new CustodyResult(CustodyDisposition::ParkedDependency, $id, 'parked_dependency');
    }

    private function quarantined(Connection $db, int $id, HandlerOutcome $outcome): CustodyResult
    {
        $db->update("UPDATE public.billium_inbox_message SET status = 'quarantined', last_error_class = ?, attempts = attempts + 1, next_attempt_at = NULL, park_kind = NULL, park_ref = NULL WHERE id = ?", [substr($outcome->reference, 0, 200), $id]);

        return new CustodyResult(CustodyDisposition::Quarantined, $id, 'quarantined');
    }

    /** Status of a new delivery under the locked partition (or none). */
    private function decide(Connection $db, InboxEnvelope $e, ?PartitionRow $partition): string
    {
        $taken = $db->selectOne(<<<'SQL'
            SELECT 1 AS taken FROM public.billium_inbox_message
            WHERE channel = ? AND source_authority = ? AND source_lineage = ? AND seller_ref = ? AND environment = ? AND partition_ref = ?
              AND source_sequence = ? AND status NOT IN ('held_recovery', 'quarantined')
            SQL, [$e->channel, $e->sourceAuthority, $e->sourceLineage, $e->sellerRef, $e->environment, $e->partitionRef, $e->sourceSequence]);
        if ($partition === null) {
            return $taken === null && ! $this->retiredNamespace($db, $e) ? 'parked_gap' : 'held_recovery';
        }
        if ($partition->state === 'recovery_required') {
            return 'held_recovery'; // custody continues, the tail waits for the adopted bootstrap
        }
        if ($partition->sourceLineage !== $e->sourceLineage) {
            $this->partitions->requireRecovery($db, $partition, 'lineage_changed');

            return 'held_recovery';
        }
        if ($e->sourceSequence <= $partition->accountedSequence || $taken !== null) {
            // A new event identity at an already accounted or taken slot: the source regressed or forked (REQ-FAIL-23).
            $this->partitions->requireRecovery($db, $partition, 'sequence_regressed');

            return 'held_recovery';
        }

        return $e->sourceSequence === $partition->accountedSequence + 1 ? 'ready' : 'parked_gap';
    }

    private function duplicate(Connection $db, InboxEnvelope $e, mixed $existing): CustodyResult
    {
        $id = Rows::int(Rows::field($existing, 'id'));
        $status = Rows::string(Rows::field($existing, 'status'));
        $result = Rows::field($existing, 'result_ref');
        if (Rows::field($existing, 'content_digest') !== $e->contentDigest) {
            // The original stays untouched; this delivery is preserved apart (REQ-FAIL-04).
            $db->insert(<<<'SQL'
                INSERT INTO public.billium_inbox_conflict (inbox_id, conflicting_digest, payload) VALUES (?, ?, ?::jsonb)
                ON CONFLICT (inbox_id, conflicting_digest) DO NOTHING
                SQL, [$id, $e->contentDigest, $e->json]);

            return new CustodyResult(CustodyDisposition::Conflict, $id, $status, $result === null ? null : Rows::string($result));
        }
        $partition = Rows::field($existing, 'partition_id');

        return new CustodyResult(CustodyDisposition::Duplicate, $id, $status, $result === null ? null : Rows::string($result),
            in_array($status, ['ready', 'parked_gap'], true) && $partition !== null ? Rows::int($partition) : null);
    }

    private function identity(Connection $db, InboxEnvelope $e): mixed
    {
        return $db->selectOne(<<<'SQL'
            SELECT * FROM public.billium_inbox_message
            WHERE channel = ? AND source_authority = ? AND source_lineage = ? AND seller_ref = ? AND environment = ? AND external_event_id = ?
            SQL, [$e->channel, $e->sourceAuthority, $e->sourceLineage, $e->sellerRef, $e->environment, $e->eventId]);
    }

    private function bootstrapOpen(Connection $db, InboxEnvelope $e): bool
    {
        return $db->selectOne(<<<'SQL'
            SELECT 1 AS open FROM public.billium_feed_bootstrap_session
            WHERE channel = ? AND source_authority = ? AND seller_ref = ? AND environment = ? AND partition_ref = ?
              AND status IN ('loading', 'verified', 'applying', 'ready')
            SQL, [$e->channel, $e->sourceAuthority, $e->sellerRef, $e->environment, $e->partitionRef]) !== null;
    }

    private function retiredNamespace(Connection $db, InboxEnvelope $e): bool
    {
        return $db->selectOne(<<<'SQL'
            SELECT 1 FROM public.billium_feed_partition
            WHERE channel = ? AND source_authority = ? AND seller_ref = ? AND environment = ? AND partition_ref = ? AND state = 'retired'
            SQL, [$e->channel, $e->sourceAuthority, $e->sellerRef, $e->environment, $e->partitionRef]) !== null;
    }

    /** Id of the earliest parked lower revision of the same operation, if any. */
    private function earlierParked(Connection $db, mixed $record): ?int
    {
        $earlier = $db->selectOne(<<<'SQL'
            SELECT id FROM public.billium_inbox_message
            WHERE channel = ? AND source_authority = ? AND seller_ref = ? AND environment = ? AND operation_ref = ?
              AND status = 'parked_dependency' AND operation_revision < ? AND id <> ?
            ORDER BY operation_revision, id LIMIT 1
            SQL, [
            Rows::field($record, 'channel'), Rows::field($record, 'source_authority'), Rows::field($record, 'seller_ref'), Rows::field($record, 'environment'),
            Rows::field($record, 'operation_ref'), Rows::field($record, 'operation_revision'), Rows::field($record, 'id'),
        ]);

        return $earlier === null ? null : Rows::int(Rows::field($earlier, 'id'));
    }

    /** The next parked revision of the same operation to retry after this one resolved. */
    private function laterParked(Connection $db, mixed $record): ?int
    {
        $later = $db->selectOne(<<<'SQL'
            SELECT id FROM public.billium_inbox_message
            WHERE channel = ? AND source_authority = ? AND seller_ref = ? AND environment = ? AND operation_ref = ?
              AND status = 'parked_dependency' AND operation_revision > ?
            ORDER BY operation_revision, id LIMIT 1
            SQL, [
            Rows::field($record, 'channel'), Rows::field($record, 'source_authority'), Rows::field($record, 'seller_ref'), Rows::field($record, 'environment'),
            Rows::field($record, 'operation_ref'), Rows::field($record, 'operation_revision'),
        ]);

        return $later === null ? null : Rows::int(Rows::field($later, 'id'));
    }
}
