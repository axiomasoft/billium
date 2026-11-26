<?php

declare(strict_types=1);

namespace Billium\Server\Internal\Operations;

use Billium\Core\Configuration\BilliumOptions;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;
use Throwable;

#[Internal]
final class ServerMetricsCommand extends Command
{
    protected $signature = 'billium:server:metrics';

    protected $description = 'Read aggregate Billium server operating signals';

    public function handle(BilliumOptions $options, DatabaseManager $databases, ServerReadinessCheck $readiness): int
    {
        try {
            $failure = $readiness->failure();
            $db = $databases->connection($options->connection);
            $platform = $db->selectOne(<<<'SQL'
                SELECT
                    (SELECT count(DISTINCT partition_id) FROM public.billium_inbox_message WHERE status = 'parked_gap') AS feed_gap_partitions,
                    (SELECT count(*) FROM public.billium_inbox_message WHERE status IN ('parked_gap', 'parked_dependency', 'held_recovery')) AS parked_custody,
                    (SELECT count(*) FROM public.billium_inbox_conflict) AS inbox_conflicts,
                    (SELECT count(*) FROM public.billium_module_state WHERE state = 'draining') AS modules_draining,
                    (SELECT coalesce(greatest(0, extract(epoch FROM now() - min(created_at)))::bigint, 0) FROM (
                        SELECT e.created_at FROM public.billium_outbox_delivery d JOIN public.billium_outbox_event e ON e.event_id = d.event_id
                        WHERE d.status <> 'delivered'
                        UNION ALL
                        SELECT c.created_at FROM public.billium_outbox_command_delivery d JOIN public.billium_outbox_command c ON c.command_ref = d.event_id
                        WHERE d.status <> 'delivered'
                    ) waiting) AS outbox_oldest_undelivered_seconds
                SQL, [], false);
            $metrics = [
                'ready' => $failure === null ? 1 : 0,
                'session_quarantined' => $failure === 'billium.server.session_quarantined' ? 1 : 0,
                ...(array) $platform,
            ];

            if ($options->moduleEnabled('ledger')) {
                $metrics['unmatched_refund_charge_totals'] = $db->scalar(<<<'SQL'
                    SELECT count(DISTINCT d.observed_payment_id) FROM public.billium_ledger_discrepancy d
                    WHERE d.reason = 'refund_basis_mismatch' AND NOT EXISTS (
                        SELECT FROM public.billium_discrepancy_event e WHERE e.discrepancy_id = d.id AND e.kind = 'resolved'
                    )
                    SQL, [], false);
            }

            if ($options->moduleEnabled('execution')) {
                $execution = $db->selectOne(<<<'SQL'
                    SELECT
                        count(*) FILTER (WHERE accepted_unknown) AS accepted_unknown,
                        coalesce(greatest(0, extract(epoch FROM now() - min(created_at) FILTER (WHERE accepted_unknown)))::bigint, 0) AS accepted_unknown_oldest_operation_seconds,
                        count(*) FILTER (WHERE attempt_state = 'unknown') AS attempts_unknown,
                        count(*) FILTER (WHERE lease_expires_at <= now()) AS expired_attempt_leases,
                        (SELECT count(*) FROM public.billium_execution_admission_conflict) AS execution_admission_conflicts,
                        (SELECT count(*) FROM public.billium_execution_namespace WHERE state = 'recovery_required') AS execution_namespaces_in_recovery
                    FROM (
                        SELECT o.created_at, a.state AS attempt_state, a.lease_expires_at,
                            (o.state IN ('unknown', 'recovery_required', 'held') OR (o.state = 'admitted' AND a.state IN ('dispatching', 'unknown'))) AS accepted_unknown
                        FROM public.billium_execution_operation o LEFT JOIN public.billium_execution_attempt a
                            USING (installation_ref, seller_ref, environment, operation_ref)
                    ) operations
                    SQL, [], false);
                $metrics += (array) $execution;
            }

            $this->line(json_encode($metrics, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            $this->error('billium.server.metrics_unavailable');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
