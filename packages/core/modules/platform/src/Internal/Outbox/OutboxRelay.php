<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Outbox;

use Billium\Core\Contracts\Runtime\EventPublisher;
use Billium\Platform\Data\Feed\RelayReport;
use Billium\Platform\Internal\Installation\Rows;
use Illuminate\Database\Connection;
use LogicException;
use Packman\Attributes\Internal;
use Throwable;

/** D60/D63: SKIP LOCKED claim, transport outside Tx, token-CAS acknowledgement. */
#[Internal]
final readonly class OutboxRelay
{
    public function __construct(
        private Connection $db,
        private EventPublisher $publisher,
        private int $claimSeconds = 60,
        private int $backoffSeconds = 5,
        private int $budget = 10,
        private string $lane = 'event',
    ) {
        if (! in_array($lane, ['event', 'command'], true) || $claimSeconds < 1 || $backoffSeconds < 1 || $budget < 1) {
            throw new LogicException('Invalid relay policy');
        }
    }

    public function relay(int $limit = 100): RelayReport
    {
        $this->outsideTransaction();
        $delivered = $failed = $stale = 0;
        $claims = $this->claim($limit);
        foreach ($claims as $claim) {
            try {
                $this->outsideTransaction();
                $this->publisher->publish($claim['destination'], $claim['message']);
                $this->outsideTransaction();
                $changed = $this->acknowledge($claim['id'], $claim['token']);
                $changed ? $delivered++ : $stale++;
            } catch (Throwable $error) {
                // Persist only a sanitized category; exception messages may contain credentials or payloads.
                $this->outsideTransaction();
                $changed = $this->fail($claim['id'], $claim['token'], $claim['failures'] + 1, $error::class);
                $changed ? $failed++ : $stale++;
            }
        }

        return new RelayReport(count($claims), $delivered, $failed, $stale);
    }

    /** @return list<array{id:int,token:string,destination:string,message:array<string,mixed>,failures:int}> */
    public function claim(int $limit = 100): array
    {
        $this->outsideTransaction();
        if ($limit < 1 || $limit > 1000) {
            throw new LogicException('Relay limit must be between 1 and 1000');
        }
        [$content, $delivery, $key] = $this->tables();

        return $this->db->transaction(function () use ($limit, $content, $delivery, $key): array {
            $rows = $this->db->select(<<<SQL
                SELECT e.*,d.id AS delivery_id,d.destination_ref,d.consecutive_failures,d.status AS delivery_status FROM public.{$delivery} d
                JOIN public.{$content} e ON e.{$key} = d.event_id
                WHERE (d.status = 'pending' AND d.available_at <= clock_timestamp())
                   OR (d.status = 'in_flight' AND d.claim_until <= clock_timestamp())
                ORDER BY d.available_at,d.id LIMIT {$limit} FOR UPDATE OF d SKIP LOCKED
                SQL);
            $claims = [];
            foreach ($rows as $row) {
                $token = bin2hex(random_bytes(24));
                $id = Rows::int(Rows::field($row, 'delivery_id'));
                $failures = Rows::int(Rows::field($row, 'consecutive_failures'));
                if (Rows::field($row, 'delivery_status') === 'in_flight') {
                    // The previous claim expired without an outcome: a worker that dies or hangs on a message counts as a failure.
                    $failures++;
                    if ($failures >= $this->budget) {
                        $this->db->update("UPDATE public.{$delivery} SET status = 'parked',claim_token = NULL,claim_until = NULL,attempts = attempts + 1,consecutive_failures = ?,last_error_class = 'claim_expired',park_reason = 'delivery_budget_exhausted' WHERE id = ?",
                            [$failures, $id]);

                        continue;
                    }
                }
                $this->db->update("UPDATE public.{$delivery} SET status = 'in_flight',claim_token = ?,claim_until = clock_timestamp() + (? * interval '1 second'),attempts = attempts + 1,consecutive_failures = ?,last_error_class = CASE WHEN ? > consecutive_failures THEN 'claim_expired' ELSE last_error_class END WHERE id = ?",
                    [$token, $this->claimSeconds, $failures, $failures, $id]);
                $message = $this->lane === 'event' ? EventRows::envelope($row) : [
                    'schema' => Rows::string(Rows::field($row, 'command_schema')), 'commandRef' => Rows::string(Rows::field($row, 'command_ref')),
                    'businessRef' => Rows::string(Rows::field($row, 'business_ref')), 'commandType' => Rows::string(Rows::field($row, 'command_type')),
                    'payloadDigest' => Rows::string(Rows::field($row, 'payload_digest')),
                    'payload' => json_decode(Rows::string(Rows::field($row, 'payload')), false, 512, JSON_THROW_ON_ERROR),
                ];
                $claims[] = ['id' => $id, 'token' => $token, 'destination' => Rows::string(Rows::field($row, 'destination_ref')),
                    'message' => $message, 'failures' => $failures];
            }

            return $claims;
        });
    }

    public function acknowledge(int $id, string $token): bool
    {
        $this->outsideTransaction();
        [, $delivery] = $this->tables();

        return $this->db->update("UPDATE public.{$delivery} SET status = 'delivered',delivered_at = clock_timestamp(),claim_token = NULL,claim_until = NULL,consecutive_failures = 0,last_error_class = NULL,park_reason = NULL WHERE id = ? AND status = 'in_flight' AND claim_token = ?", [$id, $token]) === 1;
    }

    public function requeue(string $eventId, string $destinationRef): bool
    {
        $this->outsideTransaction();
        [, $delivery] = $this->tables();

        return $this->db->update("UPDATE public.{$delivery} SET status = 'pending',available_at = clock_timestamp(),consecutive_failures = 0,park_reason = NULL,last_error_class = NULL WHERE event_id = ? AND destination_ref = ? AND status = 'parked'", [$eventId, $destinationRef]) === 1;
    }

    private function fail(int $id, string $token, int $failures, string $class): bool
    {
        [, $delivery] = $this->tables();
        $park = $failures >= $this->budget;
        $class = preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/D', $class) === 1 ? $class : 'publisher_error';
        $delay = min(3600, $this->backoffSeconds * (2 ** min(16, $failures - 1)));
        $delay += random_int(0, max(1, intdiv($delay, 4)));

        return $this->db->update("UPDATE public.{$delivery} SET status = ?,available_at = clock_timestamp() + (? * interval '1 second'),claim_token = NULL,claim_until = NULL,consecutive_failures = ?,last_error_class = ?,park_reason = ? WHERE id = ? AND status = 'in_flight' AND claim_token = ?",
            [$park ? 'parked' : 'pending', $delay, $failures, substr($class, 0, 200), $park ? 'delivery_budget_exhausted' : null, $id, $token]) === 1;
    }

    /** @return array{string,string,string} */
    private function tables(): array
    {
        return $this->lane === 'event' ? ['billium_outbox_event', 'billium_outbox_delivery', 'event_id']
            : ['billium_outbox_command', 'billium_outbox_command_delivery', 'command_ref'];
    }

    private function outsideTransaction(): void
    {
        if ($this->db->transactionLevel() !== 0 || $this->db->getPdo()->inTransaction()) {
            throw new LogicException('Relay transport requires transaction level zero');
        }
    }
}
