<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Outbox;

use Billium\Platform\Internal\Installation\Rows;
use Illuminate\Database\Connection;
use LogicException;

/** D25/D52/D60: owner-only maintenance, complete safe physical prefix, all delivery destinations and active pins. */
final readonly class OutboxPruner
{
    /** Upper bound of rows one maintenance transaction examines and deletes while it holds the exclusive barrier. */
    public const int DEFAULT_BATCH = 1000;

    public function __construct(private Connection $db, private ?int $retentionSeconds, private int $batchSize = self::DEFAULT_BATCH) {}

    /** Prunes the complete safe prefix in bounded transactions; readers get the barrier between batches. */
    public function prune(): int
    {
        if ($this->retentionSeconds === null || $this->retentionSeconds < 0) {
            throw new LogicException('Prune refuses an unconfigured retention floor');
        }
        if ($this->batchSize < 1) {
            throw new LogicException('Prune batch must be positive');
        }
        if ($this->db->transactionLevel() !== 0 || $this->db->getPdo()->inTransaction()) {
            throw new LogicException('Prune requires its own maintenance transaction');
        }
        $owner = $this->db->selectOne("SELECT current_user = pg_get_userbyid(relowner) AS allowed FROM pg_class WHERE oid = 'public.billium_outbox_event'::regclass");
        if (Rows::field($owner, 'allowed') !== true) {
            throw new LogicException('Prune requires the explicitly provisioned maintenance owner connection');
        }

        $total = 0;
        do {
            $deleted = $this->batch();
            $total += $deleted;
        } while ($deleted >= $this->batchSize);

        return $total;
    }

    private function batch(): int
    {
        return $this->db->transaction(function (): int {
            EventFeedReader::barrier($this->db);
            $this->db->statement("INSERT INTO public.billium_outbox_watermark(id,scope) VALUES (1,'installation') ON CONFLICT DO NOTHING");
            // Freeze the eligibility proof before deleting. A lower open xid cannot cross this horizon.
            // Only the first batchSize rows in physical order are examined: the prefix of a window is a prefix of the whole.
            $rows = $this->db->select(<<<SQL
                WITH safe AS MATERIALIZED (
                    SELECT e.tx_id,e.id,
                        e.created_at <= clock_timestamp() - (? * interval '1 second')
                        AND NOT EXISTS (SELECT 1 FROM public.billium_outbox_delivery d WHERE d.event_id = e.event_id AND d.status <> 'delivered')
                        AND NOT EXISTS (SELECT 1 FROM public.billium_feed_bootstrap_lease p
                            WHERE p.source_scope::jsonb = e.source_scope::jsonb AND p.state = 'active' AND p.expires_at > clock_timestamp()
                              AND (e.tx_id,e.id) > (p.floor_tx_id,p.floor_id))
                        AND NOT EXISTS (SELECT 1 FROM public.billium_feed_subscription s
                            WHERE s.source_scope::jsonb = e.source_scope::jsonb AND s.required AND s.closed_at IS NULL
                              AND (e.tx_id,e.id) > (s.floor_tx_id,s.floor_id)) AS eligible
                    FROM public.billium_outbox_event e WHERE e.tx_id < pg_snapshot_xmin(pg_current_snapshot())
                    ORDER BY e.tx_id,e.id LIMIT {$this->batchSize}
                ), first_blocked AS (SELECT tx_id,id FROM safe WHERE NOT eligible ORDER BY tx_id,id LIMIT 1)
                SELECT tx_id,id FROM safe WHERE eligible AND
                    (NOT EXISTS (SELECT 1 FROM first_blocked) OR (tx_id,id) < (SELECT tx_id,id FROM first_blocked))
                ORDER BY tx_id DESC,id DESC LIMIT 1
                SQL, [$this->retentionSeconds]);
            if ($rows === []) {
                return 0;
            }
            $last = $rows[0];
            $tx = Rows::field($last, 'tx_id');
            $id = Rows::field($last, 'id');
            // The boundary is the proven complete safe prefix, not max(deleted) over a sparse eligible set.
            $this->db->statement("SELECT set_config('billium.outbox_prune','on',true)");
            $this->db->delete('DELETE FROM public.billium_outbox_delivery d USING public.billium_outbox_event e WHERE d.event_id = e.event_id AND (e.tx_id,e.id) <= (?::xid8,?::bigint)', [$tx, $id]);
            $deleted = $this->db->delete('DELETE FROM public.billium_outbox_event WHERE (tx_id,id) <= (?::xid8,?::bigint)', [$tx, $id]);
            $this->db->update("UPDATE public.billium_outbox_watermark SET pruned_tx_id = ?::xid8,pruned_id = ?::bigint,updated_at = clock_timestamp() WHERE scope = 'installation' AND (pruned_tx_id,pruned_id) < (?::xid8,?::bigint)", [$tx, $id, $tx, $id]);
            $this->db->statement("SELECT set_config('billium.outbox_prune','off',true)");

            return $deleted;
        });
    }
}
