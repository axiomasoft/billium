<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Feed;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Platform\Internal\Installation\Rows;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;

/**
 * Rank-30 access to feed_partition: the live partition of a source namespace is looked up WITHOUT its lineage (a changed
 * lineage is detected, never a second partition), then locked through the context's LockSet and re-read.
 */
#[Internal]
final readonly class PartitionStore
{
    public function __construct(private DatabaseManager $databases, private BilliumOptions $options) {}

    public function connection(TransactionContext $context): Connection
    {
        return $this->databases->connection($context->connectionName());
    }

    public function defaultConnection(): Connection
    {
        return $this->databases->connection($this->options->connection);
    }

    /**
     * Serializes bootstrap staging/adoption with first-delivery creation and custody in retired-only namespaces.
     * Precedes partition/session row locks; fresh orphan gaps can commit without namespace admission.
     */
    public function lockNamespace(Connection $db, string $channel, string $authority, string $seller, string $environment, string $partitionRef): void
    {
        // Lineage is deliberately absent: its replacement recovers the same namespace.
        $key = json_encode(['billium.feed.namespace', $channel, $authority, $seller, $environment, $partitionRef], JSON_THROW_ON_ERROR);
        $db->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?::text, 0))', [$key]);
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

    /** Under the inserted/locked active partition, reattaches orphan custody and restores its contiguous frontier. */
    public function attachParkedGaps(Connection $db, PartitionRow $partition): void
    {
        if (! $partition->active()) {
            return;
        }
        $namespace = [$partition->channel, $partition->sourceAuthority, $partition->sourceLineage,
            $partition->sellerRef, $partition->environment, $partition->partitionRef];
        $scopeId = ObservationScopes::binding($db, 'partition_id', $partition->id)?->id;
        // Custody can commit after adoption has already scanned the tail. Covered orphans have
        // the same durable disposition as arrivals seen during adoption, even below the cursor.
        $db->update(<<<'SQL'
            UPDATE public.billium_inbox_message m
            SET partition_id = ?, status = 'quarantined', last_error_class = 'superseded_by_bootstrap', park_kind = NULL, park_ref = NULL
            WHERE (SELECT scope_id FROM public.billium_feed_observation_scope_link WHERE inbox_id=m.id) IS NOT DISTINCT FROM ?::bigint
              AND m.partition_id IS NULL AND m.status = 'parked_gap'
              AND m.channel = ? AND m.source_authority = ? AND m.source_lineage = ?
              AND m.seller_ref = ? AND m.environment = ? AND m.partition_ref = ? AND m.source_sequence <= ?
              AND EXISTS (SELECT 1 FROM public.billium_feed_bootstrap_session s
                  WHERE s.new_partition_id = ? AND s.status = 'adopted' AND m.source_sequence <= s.cursor_sequence)
            SQL, [$partition->id, $scopeId, ...$namespace, $partition->accountedSequence, $partition->id]);
        $regressed = $db->update(<<<'SQL'
            UPDATE public.billium_inbox_message SET status = 'held_recovery'
            WHERE (SELECT scope_id FROM public.billium_feed_observation_scope_link WHERE inbox_id=billium_inbox_message.id) IS NOT DISTINCT FROM ?::bigint
              AND partition_id IS NULL AND status = 'parked_gap'
              AND channel = ? AND source_authority = ? AND source_lineage = ?
              AND seller_ref = ? AND environment = ? AND partition_ref = ? AND source_sequence <= ?
              AND NOT EXISTS (SELECT 1 FROM public.billium_feed_bootstrap_session s
                  WHERE s.new_partition_id = ? AND s.status = 'adopted' AND billium_inbox_message.source_sequence <= s.cursor_sequence)
            SQL, [$scopeId, ...$namespace, $partition->accountedSequence, $partition->id]);
        if ($regressed > 0) {
            $this->requireRecovery($db, $partition, 'sequence_regressed');

            return;
        }
        $attached = $db->update(<<<'SQL'
            UPDATE public.billium_inbox_message SET partition_id = ?
            WHERE (SELECT scope_id FROM public.billium_feed_observation_scope_link WHERE inbox_id=billium_inbox_message.id) IS NOT DISTINCT FROM ?::bigint
              AND partition_id IS NULL AND status = 'parked_gap'
              AND channel = ? AND source_authority = ? AND source_lineage = ?
              AND seller_ref = ? AND environment = ? AND partition_ref = ?
              AND source_sequence > ?
            SQL, [$partition->id, $scopeId, $partition->channel, $partition->sourceAuthority, $partition->sourceLineage,
            $partition->sellerRef, $partition->environment, $partition->partitionRef, $partition->accountedSequence]);
        if ($attached > 0) {
            $this->extendCustody($db, $partition);
        }
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
