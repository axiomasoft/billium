# 05 — События и receipts

Envelope события — `schema/event/event.v1.json`. Версию схемы события (`schemaVersion` контрактов v1) несёт
член `schema`, как у `payment.observation.v1` (принцип 1 контрактов v1).

[BC-EVT-01] Событие публикуется через outbox Billium в транзакции изменения state. (REQ-CON-18)

[BC-EVT-02] `billium.event.v1` несёт `eventId` (ULID), `eventType`, `sellerRef`, `aggregateRef`, `revision`, `occurredAt`, `digest` и `payload`; версию схемы несёт `schema`. (REQ-CON-18, M-01, D43)

[BC-EVT-03] `eventType` v1 ∈ {`payment.outcome.v1`, `obligation.created.v1`, `obligation.adjusted.v1`, `invoice.issued.v1`, `credit_note.issued.v1`, `allocation.applied.v1`, `discrepancy.recorded.v1`}. (REQ-CON-18, REQ-CON-22)

[BC-EVT-04] Повтор доставки сохраняет identity и содержимое события; потребитель дедуплицирует по `eventId` и по `(aggregateRef, revision)`. (REQ-CON-18, INV-08)

[BC-EVT-05] Транспортный ACK (HTTP 2xx, queue ACK, callback ACK) не является бизнес-receipt. (INV-08)

[BC-EVT-06] Receipts `CommandAccepted`, `ProviderOutcomeRecorded`, `ProjectionApplied`, `AllocationReceipt`, `FulfilmentReceipt`, `UsageFactAccepted` и `DocumentReserved` различны, и ни один не подменяет другой. (REQ-CON-10, INV-08)

[BC-EVT-07] `CommandAccepted` подтверждает сохранение identity и digest команды, но не эффект у провайдера. (REQ-CON-10, INV-04)

[BC-EVT-08] `AllocationReceipt` подтверждает распределение на obligation, но не grant и не fulfilment. (REQ-CON-10, INV-09)

[BC-EVT-09] Экспортный факт для `billium-finbridge` несёт стабильный `sourceFactRef`; несколько статусов одного capture не дают нескольких фактов выручки. (REQ-CON-22, REQ-CON-34, REQ-CON-35)
