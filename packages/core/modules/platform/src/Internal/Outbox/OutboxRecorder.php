<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Outbox;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\OperationRunner;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Platform\Contracts\Outbox;
use Billium\Platform\Data\EventContracts\RegisteredPublicEvent;
use Billium\Platform\Data\OutboxEvent;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use Billium\Platform\Internal\Installation\Rows;
use Closure;
use Illuminate\Database\DatabaseManager;
use LogicException;

final readonly class OutboxRecorder implements Outbox
{
    /** @param Closure(): OperationRunner $runner */
    public function __construct(private DatabaseManager $databases, private Codec $codec, private Closure $runner) {}

    public function record(TransactionContext $context, OutboxEvent|RegisteredPublicEvent $event): int
    {
        // Same runner, connection and root Tx as the owning state (PF-10); platform is in every admitted closure.
        ($this->runner)()->assertCurrent($context, 'platform');
        // @phpstan-ignore identical.alwaysFalse (Keep the explicit admission invariant although sessionEpoch returns int or throws.)
        if (! $context->spec()->mode->financial() || $context->sessionEpoch() === null
            || in_array($context->spec()->operationClass, [ModuleOperationClass::ReceiptReplay, ModuleOperationClass::HistoricalRead, ModuleOperationClass::CallbackCustody], true)) {
            throw new LogicException('Outbox recording requires an admitted financial write operation class and session (D54, D55, D60)');
        }
        $registered = $event instanceof RegisteredPublicEvent ? $event : null;
        $event = $registered->event ?? $event;
        if (! $event instanceof OutboxEvent) {
            throw new LogicException('Missing public event body');
        }
        $db = $this->databases->connection($context->connectionName());
        $revision = $registered?->contract->contractRevision ?? 'events-2026-10-v1';
        $contract = $db->selectOne('SELECT * FROM public.billium_event_contract WHERE contract_revision = ? AND envelope_schema = ? AND event_type = ?',
            [$revision, $event->schemaVersion, $event->type]);
        if ($contract === null) {
            throw new LogicException('module.event_contract_conflict: public event is not registered');
        }
        $contract = DatabaseEventContractRegistrar::registration($contract);
        if ($registered !== null && (! $registered->contract->sameContract($contract) || $registered->contract->key() !== $contract->key())) {
            throw new LogicException('module.event_contract_conflict: foreign registered event');
        }
        ($this->runner)()->assertCurrent($context, $contract->ownerModuleId);
        $payload = $this->codec->canonicalize($event->payload === [] ? new \stdClass : $event->payload);
        if (strlen($payload) > OutboxEvent::MAX_PAYLOAD_BYTES) {
            throw new LogicException("Outbox payload of {$event->type} exceeds ".OutboxEvent::MAX_PAYLOAD_BYTES.' bytes');
        }
        $caller = $context->caller();
        $digest = 'sha256:'.hash('sha256', $payload);
        $wire = ['schema' => $event->schemaVersion, 'eventId' => $event->eventId, 'eventType' => $event->type,
            'sellerRef' => $caller->sellerRef, 'aggregateRef' => $event->aggregateRef, 'revision' => $event->aggregateRevision,
            'occurredAt' => $event->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'digest' => $digest, 'payload' => json_decode($payload, false, 512, JSON_THROW_ON_ERROR)];
        if ($event->schemaVersion !== 'billium.event.v1') {
            $wire['eventContractRevision'] = $contract->contractRevision;
        }
        // Validate every immutable public envelope before either content or destination work can be inserted.
        $decoded = $this->codec->decode($this->codec->canonicalize($wire));
        if ($event->schemaVersion === 'billium.event.v2') {
            $effect = $decoded->raw->payload;
            if (! $effect instanceof \stdClass) {
                throw new LogicException('Public effect payload must be an object');
            }
            if (($effect->installationRef ?? null) !== $caller->installationRef || ($effect->environment ?? null) !== $caller->environment
                || ($effect->sellerRef ?? null) !== $caller->sellerRef || ($effect->operationRef ?? null) !== $event->aggregateRef
                || ($effect->revision ?? null) !== $event->aggregateRevision || ($effect->schema ?? null) !== $event->type) {
                throw new LogicException('Public effect source scope/identity differs from authenticated root');
            }
        }
        $feedScope = EventRows::scope($db, $context);
        $scope = $feedScope->key();
        $conflictClause = $registered === null ? '' : ' ON CONFLICT DO NOTHING';
        $row = $db->selectOne(<<<SQL
            INSERT INTO public.billium_outbox_event
                (event_id, seller_ref, environment, type, schema_version, aggregate_ref, aggregate_revision, occurred_at, digest, payload,contract_revision,owner_module_id,source_scope)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb,?,?,?)
            {$conflictClause} RETURNING id
            SQL, [
            $event->eventId, $caller->sellerRef, $caller->environment, $event->type, $event->schemaVersion, $event->aggregateRef,
            $event->aggregateRevision, $event->occurredAt->format('Y-m-d\TH:i:s.uP'), $digest, $payload,
            $revision, $contract->ownerModuleId, $scope,
        ]);
        if ($row === null) {
            $existing = $db->selectOne('SELECT * FROM public.billium_outbox_event WHERE event_id = ?', [$event->eventId]);
            if ($existing === null || ! EventRows::sameScope(Rows::field($existing, 'source_scope'), $feedScope) || Rows::field($existing, 'owner_module_id') !== $contract->ownerModuleId
                || $this->codec->canonicalize(EventRows::envelope($existing)) !== $this->codec->canonicalize($wire)) {
                throw new ProblemException(ProblemCode::IdempotencyConflict, 'Public event identity/revision has different immutable content');
            }
            $stored = array_map(static fn (mixed $delivery): string => Rows::string(Rows::field($delivery, 'destination_ref')),
                $db->select('SELECT destination_ref FROM public.billium_outbox_delivery WHERE event_id = ? ORDER BY destination_ref', [$event->eventId]));
            $destinations = array_values(array_unique($registered->destinations ?? ['default']));
            sort($destinations, SORT_STRING);
            if ($stored !== $destinations) {
                throw new ProblemException(ProblemCode::IdempotencyConflict, 'Public event destinations are frozen');
            }

            return Rows::int(Rows::field($existing, 'id'));
        }
        EventRows::deliveries($db, 'billium_outbox_delivery', $event->eventId, $registered->destinations ?? ['default']);

        return Rows::int(Rows::field($row, 'id'));
    }
}
