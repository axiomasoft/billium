<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Outbox;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Platform\Contracts\Outbox;
use Billium\Platform\Data\OutboxEvent;
use Billium\Platform\Internal\Installation\Rows;
use Closure;
use Illuminate\Database\DatabaseManager;
use LogicException;

final readonly class OutboxRecorder implements Outbox
{
    /** @param Closure(): OperationRunner $runner */
    public function __construct(private DatabaseManager $databases, private Codec $codec, private Closure $runner) {}

    public function record(TransactionContext $context, OutboxEvent $event): int
    {
        // Same runner, connection and root Tx as the owning state (PF-10); platform is in every admitted closure.
        ($this->runner)()->assertCurrent($context, 'platform');
        $payload = $this->codec->canonicalize($event->payload === [] ? new \stdClass : $event->payload);
        if (strlen($payload) > OutboxEvent::MAX_PAYLOAD_BYTES) {
            throw new LogicException("Outbox payload of {$event->type} exceeds ".OutboxEvent::MAX_PAYLOAD_BYTES.' bytes');
        }
        $caller = $context->caller();
        $row = $this->databases->connection($context->connectionName())->selectOne(<<<'SQL'
            INSERT INTO public.billium_outbox_event
                (event_id, seller_ref, environment, type, schema_version, aggregate_ref, aggregate_revision, occurred_at, digest, payload)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)
            RETURNING id
            SQL, [
            $event->eventId, $caller->sellerRef, $caller->environment, $event->type, $event->schemaVersion, $event->aggregateRef,
            $event->aggregateRevision, $event->occurredAt->format('Y-m-d\TH:i:s.uP'), 'sha256:'.hash('sha256', $payload), $payload,
        ]);

        return Rows::int(Rows::field($row, 'id'));
    }
}
