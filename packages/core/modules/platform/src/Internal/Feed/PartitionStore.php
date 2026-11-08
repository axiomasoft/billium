<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Feed;

use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\LockRequest;
use Billium\Core\Enums\LockRank;
use Billium\Core\Exceptions\LockPlanChanged;
use Billium\Platform\Internal\Installation\Rows;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;

/**
 * Rank-30 access to feed_partition: the live partition of a source namespace is looked up WITHOUT its lineage (a changed
 * lineage is detected, never a second partition), then locked through the context's LockSet and re-read.
 */
final readonly class PartitionStore
{
    public function __construct(private DatabaseManager $databases) {}

    public function connection(TransactionContext $context): Connection
    {
        return $this->databases->connection($context->connectionName());
    }

    public function live(Connection $db, string $channel, string $authority, string $seller, string $environment, string $partitionRef): ?PartitionRow
    {
        $row = $db->selectOne(<<<'SQL'
            SELECT * FROM public.billium_feed_partition
            WHERE channel = ? AND source_authority = ? AND seller_ref = ? AND environment = ? AND partition_ref = ? AND state <> 'retired'
            SQL, [$channel, $authority, $seller, $environment, $partitionRef]);

        return $row === null ? null : PartitionRow::from($row);
    }

    public function find(Connection $db, int $id, string $seller, string $environment): ?PartitionRow
    {
        $row = $db->selectOne('SELECT * FROM public.billium_feed_partition WHERE id = ? AND seller_ref = ? AND environment = ?', [$id, $seller, $environment]);

        return $row === null ? null : PartitionRow::from($row);
    }

    /** Locks the planned partition and re-reads it; a vanished or retired partition re-plans the whole database work. */
    public function lock(TransactionContext $context, PartitionRow $planned): PartitionRow
    {
        $db = $this->connection($context);
        $caller = $context->caller();
        if ($context->lock(new LockRequest(LockRank::FeedPartition, 'billium_feed_partition', [$planned->id])) === []) {
            throw new LockPlanChanged("Feed partition {$planned->id} is gone");
        }
        $current = $this->find($db, $planned->id, $caller->sellerRef, $caller->environment) ?? throw new LockPlanChanged("Feed partition {$planned->id} is gone");
        if ($current->state === 'retired' || $current->generation !== $planned->generation) {
            throw new LockPlanChanged("Feed partition {$planned->id} was replaced");
        }

        return $current;
    }

    /** Inserts the first partition of a namespace; null when a concurrent root created it first. */
    public function create(Connection $db, string $channel, string $authority, string $lineage, string $seller, string $environment, string $partitionRef, int $generation = 1, int $cursor = 0): ?PartitionRow
    {
        $row = $db->selectOne(<<<'SQL'
            INSERT INTO public.billium_feed_partition
                (channel, source_authority, source_lineage, seller_ref, environment, partition_ref, generation, custody_sequence, accounted_sequence, state)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ON CONFLICT (channel, source_authority, seller_ref, environment, partition_ref) WHERE state <> 'retired' DO NOTHING
            RETURNING *
            SQL, [$channel, $authority, $lineage, $seller, $environment, $partitionRef, $generation, $cursor, $cursor]);

        return $row === null ? null : PartitionRow::from($row);
    }

    /** Attaches pre-partition custody to the newly inserted or locked opening partition. */
    public function attachParkedGaps(Connection $db, PartitionRow $partition): void
    {
        $db->update(<<<'SQL'
            UPDATE public.billium_inbox_message SET partition_id = ?
            WHERE partition_id IS NULL AND status = 'parked_gap'
              AND channel = ? AND source_authority = ? AND source_lineage = ?
              AND seller_ref = ? AND environment = ? AND partition_ref = ?
              AND source_sequence > ?
            SQL, [$partition->id, $partition->channel, $partition->sourceAuthority, $partition->sourceLineage,
            $partition->sellerRef, $partition->environment, $partition->partitionRef, $partition->accountedSequence]);
    }

    public function requireRecovery(Connection $db, PartitionRow $partition, string $reason): void
    {
        $db->update(<<<'SQL'
            UPDATE public.billium_feed_partition SET state = 'recovery_required', recovery_reason = ?, updated_at = now()
            WHERE id = ? AND state = 'active'
            SQL, [substr($reason, 0, 200), $partition->id]);
    }

    /** Extends the custody frontier across every contiguous durable arrival above it. */
    public function extendCustody(Connection $db, PartitionRow $partition): int
    {
        $top = $db->selectOne(<<<'SQL'
            SELECT max(source_sequence) AS top FROM (
                SELECT source_sequence, source_sequence - row_number() OVER (ORDER BY source_sequence) AS run
                FROM public.billium_inbox_message
                WHERE partition_id = ? AND source_sequence > ? AND status NOT IN ('held_recovery', 'quarantined')
            ) arrivals WHERE run = ?
            SQL, [$partition->id, $partition->custodySequence, $partition->custodySequence]);
        $value = $top === null ? null : Rows::field($top, 'top');
        $custody = is_int($value) ? $value : $partition->custodySequence;
        if ($custody > $partition->custodySequence) {
            $db->update('UPDATE public.billium_feed_partition SET custody_sequence = ?, updated_at = now() WHERE id = ?', [$custody, $partition->id]);
        }

        return $custody;
    }

    public function account(Connection $db, PartitionRow $partition, int $sequence): void
    {
        if ($sequence !== $partition->accountedSequence + 1) {
            throw new LogicException('The accounting frontier advances one source sequence at a time');
        }
        $db->update('UPDATE public.billium_feed_partition SET accounted_sequence = ?, updated_at = now() WHERE id = ? AND accounted_sequence = ?', [$sequence, $partition->id, $partition->accountedSequence]);
    }
}
