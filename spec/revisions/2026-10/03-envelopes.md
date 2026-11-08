# 03 — Envelopes

Набор envelope ревизии — таблица `README.md`; поля и семантика — `docs/contracts/billium-contracts-v1.md`,
форма — `schema/**`.

## Общие правила

[BC-ENV-01] Каждый envelope несёт член `schema` вида `<family>.<name>.v<N>`; схема ревизии задаёт его как `const`. (M-01, REQ-CON-20)

[BC-ENV-02] `schema` вне набора ревизии (неизвестное имя или major, например `payment.outcome.v2`) даёт `billium.schema.unsupported` до любого эффекта. (REQ-CON-20)

[BC-ENV-03] Decode проверяет по порядку JSON-синтаксис, `schema`, поля и суммы; отказ даёт первая нарушенная ступень. (REQ-CON-20, D29)

[BC-ENV-04] Невалидный JSON, отсутствие строкового `schema` и несоответствие схеме вне MoneyWire дают `billium.validation.invalid`. (REQ-CON-20, REQ-CON-05)

[BC-ENV-05] Входящие команды (`payment.execute`, `payment.refund`, `payment.cancel`, запрос `payment.find`, `obligation.prepare`, `occurrence.ensure`, `collection.authorize`, `usage.fact`, `payment.observation`) закрыты: неизвестный член даёт `billium.validation.invalid`. (REQ-CON-20, M-05)

[BC-ENV-06] Исходы, ответы и события (`payment.outcome`, `payment.admission`, ответ `payment.find`, `usage.receipt`, `billium.event`) открыты для аддитивных необязательных членов: потребитель игнорирует неизвестный необязательный член. (REQ-CON-20, M-04)

[BC-ENV-07] Суммы — MoneyWire (исключение — итоги outcome по BC-ENV-18), количества — неотрицательная decimal string; JSON number для сумм и количеств запрещён схемой. (REQ-CON-02, D29, M-15)

[BC-ENV-08] Время — RFC 3339 в UTC с суффиксом `Z`; идентификаторы — непрозрачные непустые строки. (REQ-CON-21, REQ-CON-35)

[BC-ENV-09] Команды `payment.*`, `obligation.prepare`, `occurrence.ensure`, `collection.authorize` и запрос `billium.query` несут `sellerRef`, `installationRef` и `environment`; все они, кроме `payment.find` и `payment.observation`, несут `commandKey`. (D30, D43, INV-14, REQ-CON-21)

[BC-ENV-10] `sellerRef`, `environment` и `installationRef` тела сверяются с аутентифицированным контекстом; несовпадение даёт `billium.auth.denied` без раскрытия; ID в payload — селекторы, а не права. (REQ-CON-21, INV-14)

## Оплаты и возвраты

[BC-ENV-11] Имена членов пилота `payment.execute.v1` и `payment.outcome.v1` в ревизии неизменны; их изменение — новая major в новой ревизии. (REQ-CON-04, REQ-CON-09, REQ-CON-20, M-01)

[BC-ENV-12] `payment.execute.v1`: члены пилота, `operationKind` = `charge`, положительная `money`, `executionProfile` ∈ {`remote-billium`, `standalone-billium`}. (REQ-CON-04, D30)

[BC-ENV-13] `payment.refund.v1` несёт собственный `operationRef`, `originalOperationRef` исходного charge, положительную `money`, неизменяемые `reasonCode` и `category` и `source` refund-намерения. (REQ-CON-06, INV-07, D30)

[BC-ENV-14] `payment.cancel.v1` запрещает новые отправки и не отменяет отправленное; `cancelled` — только при доказанном отсутствии эффекта. (REQ-CON-07)

[BC-ENV-15] Ответ `payment.find.v1` несёт `status` ∈ {`accepted-unknown`, `pending`, `succeeded`, `failed-terminal`, `cancelled`, `absent`}; при статусе операции — `revision`, `digest`, `capturedTotal`, `refundedTotal`, `reservedRefundTotal`; при `absent` — `absenceCertificate`. (REQ-CON-08, REQ-FAIL-08, D43)

[BC-ENV-16] Неполный lookup (stale replica, отказ доступа, пробел retention, restore) отвечает `billium.lookup.unavailable` или `billium.recovery.required`, а не `absent`. (REQ-CON-08)

[BC-ENV-17] `payment.admission.v1` несёт `result` ∈ {`accepted`, `known-terminal`, `conflict`}, `operationRef`, `revision`, `digest`, `outcomeQueryRef` и `receipt` = `CommandAccepted`. (REQ-CON-04, D30)

[BC-ENV-18] `payment.outcome.v1`: `revision` монотонна; `capturedTotal` и `refundedTotal` — неотрицательные decimal string в валюте `money.currency` со scale этой валюты (форма пилота), cumulative и присваиваются, а не складываются; нарушение формы итога даёт `billium.money.scale_invalid`; refund outcome несёт `originalOperationRef`. (REQ-CON-09, INV-06, D30, SRC-005)

[BC-ENV-19] Та же revision outcome с иным digest уходит в quarantine у потребителя; каждое материальное изменение суммы — новая revision. (REQ-CON-09, INV-06)

[BC-ENV-20] `payment.observation.v1` несёт члены REQ-CON-27; `originalPaymentRef` обязателен при `operationKind` = `refund`; все суммы — в `currency` операции. (REQ-CON-27, INV-07)

[BC-ENV-21] Получатель наблюдения дедуплицирует по `eventId`, затем по `(sellerRef, operationRef, operationRevision)`, затем применяет `sourceSequence` partition только contiguous. (REQ-CON-27, REQ-FAIL-13, REQ-FAIL-23)

## Usage, obligations, queries

[BC-ENV-22] `usage.fact.v1`: `semantics` ∈ {`additive`, `gauge`, `distinct`}; `quantity` — неотрицательная decimal string; `interval` — полуинтервал `[start, end)`. (REQ-CON-15, REQ-CON-16)

[BC-ENV-23] `usage.receipt.v1` несёт `result` ∈ {`accepted`, `duplicate`, `conflict`} и identity факта. (REQ-CON-15)

[BC-ENV-24] `obligation.prepare.v1`, `occurrence.ensure.v1` и `collection.authorize.v1` несут ключевые поля § Obligations и invoices контрактов v1; повтор ensure возвращает тот же `billingOccurrenceRef`. (REQ-CON-11, REQ-CON-12, INV-11)

[BC-ENV-25] `billium.query.v1` только читает: `query` ∈ {`paymentStatus`, `obligation`, `invoice`, `allocationByOperation`, `refundAuthorization`, `readRender`, `dueObligations`}; ответ не выдаёт нерешённое за успех. (REQ-CON-14, D60)

[BC-ENV-26] `allocation.reallocate.v1` — закрытая команда со scope `installationRef`, `environment`, `sellerRef`, ключом `commandKey` и необязательным `digest` по BC-ID-17; несёт `paymentRef`, `policyVersion` и `mode` ∈ {`credit_note`, `reversal`}; `credit_note` — reversal с `cause = refund` и credit note на ту же сумму в одной транзакции, `reversal` — только reversal; ни один режим не создаёт collection step. (INV-09, REQ-FAIL-19, REQ-CON-20, REQ-CON-21, D35, D47)

[BC-ENV-27] Открытые ответы `ledger.payment_status.v1` и `ledger.due_obligations.v1` несут `installationRef`, `environment`, `sellerRef` и объект `result`; неизвестные необязательные члены сохраняются при roundtrip. Scope тела — селекторы, а не права: query проверяет аутентифицированный seller/environment/account. Запрос `dueObligations` дополнительно требует `selector.billingAccountRef`, `asOf` (UTC) и положительный целый `limit`; `cursor` необязателен. Эти три параметра не расширяют прежние виды запроса. (REQ-CON-14, REQ-CON-20, INV-14, D60)

`paymentStatus.result` идентифицирует `paymentRef`, `sourceAuthority`, `providerAccountRef`; `billingAccountRef` и `applied` отсутствуют до разрешения account/основания. `applied` — последняя согласованная revision/state/requested/authorizedTotal/capturedTotal/refundedTotal/allocated/unallocated; `observed` — подтверждённые actual revision/authorizedTotal/capturedTotal/refundedTotal/voidedTotal/evidenceRefs/observedAt, без ограничения refunded<=captured. Все деньги — MoneyWire в валюте операции. (INV-06, REQ-CON-14, D57, D60, D63)

`financialHold` содержит `active`, `reasonCodes[]`, `discrepancyRefs[]`; `exposure` — `currency`, `overCaptured`, `overRefunded`; `spendable` равен нулю при hold или отсутствии applied. `projectionStatus` ∈ {`current`, `allocation_pending`, `held_discrepancy`, `recovery_required`}; `current` требует applied/account, `allocation_pending` требует непустой `pendingAllocationReason` — машинную причину зависимости. Наблюдаемые деньги не объявляются отсутствующими из-за неприменённой проекции. (REQ-CON-14, REQ-FAIL-12, D57, D60, D63)

`bootstrap.status` ∈ {`not_started`, `loading`, `verified`, `applying`, `ready`, `adopted`, `failed`}; `reconciliation.status` ∈ {`not_started`, `pending`, `consistent`, `discrepancy`}, с необязательными `lastCompletedAt` и `completenessCursor`. `consistent` означает равенство последней сравниваемой базы, а не доказательство полноты источника; отсутствие аутентифицированного completenessCursor не выдаётся за полноту. Окно задержки сверки не доказывает полноту. (REQ-CON-14, REQ-FAIL-22, D48, D60)

`dueObligations.result` содержит account/asOf/obligations[] и необязательный nextCursor. Каждая строка несёт obligation/account refs, revision, положительный open, ненулевой dueAt и due-policy provenance; collectionEligibleAt необязателен. Сервер выбирает открытые строки того же scope/account с dueAt<=asOf и collectionEligibleAt<=asOf, когда задан; порядок (due_at,id), keyset cursor привязан к scope/account/asOf. Строки с hold/recovery исключаются. Ответ — кандидаты, не авторизация collection; null dueAt не заменяется createdAt. (REQ-CON-14, INV-14, D60)

[BC-ENV-28] PHP command DTO ledger несут обязательный billingAccountRef через BillingAccountScope: sellerRef/environment/account — неизменяемые селекторы. RecordObligation, RecordCustomerCredit и DeclareSettlementIntent не допускают безымянного account; immutable ordered targets intent принадлежат одному account/currency. ObligationDuePolicy несёт nullable dueAt/collectionEligibleAt, duePolicyVersion/sourcePolicyRef/sourcePolicySnapshotDigest; ненулевая дата или заданная provenance требует полной provenance. AmendObligationDuePolicy — отдельная keyed команда с полной provenance, включая отключение auto-due. DTO не выполняют денежные эффекты, не читают настройки хоста и не подставляют due date. (INV-14, REQ-CON-14, D60)

[BC-ENV-29] Экономическая revision наблюдения отделена от transport contentDigest: ключ — (installationRef, environment, sellerRef, sourceAuthority, providerAccountRef, operationRef, operationRevision). Нормализация `ledger-material/1` строит объект из этих полей и operationKind, originalPaymentRef (только когда задан), businessIntentRef, currency, requested/authorizedTotal/capturedTotal/refundedTotal, state и economic discrepancy. MoneyWire приводится к канонической строке amount по scale валюты; currency каждого итога совпадает с currency операции. Discrepancy включает только discrepancyRef/kind/revision и заданные acceptedTotals/resolution; acceptedTotals также нормализуются как MoneyWire. Отсутствующие optional members опускаются. SHA-256 берётся от RFC 8785 этого объекта; версия нормализации хранится рядом с digest. (INV-06, REQ-CON-27, D60)

eventId/contentDigest/sourceLineage/partitionRef/sourceSequence/observedAt/recordedAt/evidenceRef/providerOperationId и discrepancy.verifiedExternalFacts не входят в materialDigest: это transport/evidence custody. Иной eventId или delivery metadata с той же authenticated economic revision и materialDigest добавляет evidence без повторного эффекта; иной materialDigest той же revision — conflict/quarantine. Проверка eventId по полному contentDigest остаётся прежней; sourceLineage и sequence продолжают контролировать feed/recovery независимо от экономической дедупликации. (INV-06, REQ-CON-27, REQ-FAIL-04, REQ-FAIL-13, REQ-FAIL-23, D60)

[BC-ENV-30] `payment.effect.outcome.v1` — отдельный открытый исход child capture/void_authorization: operationRef отличается от originalOperationRef исходного charge в authenticated installation/environment/seller scope; положительная money — запрос, effectTotal — неотрицательный cumulative actual итог child со scale money.currency, присваиваемый по revision без admission upper bound. Нулевой pending итог не доказывает отсутствие эффекта; inherited bindingRevision/merchantAccountRef и evidence сохраняются. Иной digest той же revision — conflict; actual сверх лимита сохраняется для hold/discrepancy. Авторизация root, refund, local cancel и ledger observation сохраняют отдельную семантику; публикация и отдельное наблюдение ledger принадлежат execution P5.9. (D57, D60, D63, INV-06)

[BC-ENV-31] `billium.event.v2` выбирается явной negotiation: eventContractRevision = events-2026-10-v2 фиксирует единственный payment.effect.outcome.v1 и canonical JSON SHA-256 его payload schema; catalog/type-set неизменяем. Внешние sellerRef/aggregateRef/revision совпадают с payload sellerRef/operationRef/revision. Execution stream replay_only до отдельной negotiation snapshot-capable stream; disabled never-installed execution не регистрируется. Семь eventType billium.event.v1 и его digest bytes сохраняются; payment.observation.v1 не публичное событие, внутренние команды доставляются отдельной durable lane. (D63, REQ-CON-20)

[BC-ENV-32] FeedSelection фиксирует subscriptionRef, полный source scope, catalog, envelopeSchemas, eventTypes и selectionDigest. Аутентифицированный cursor связывает их с физической парой decimal strings (tx_id,id); scan допускает только tx_id < pg_snapshot_xmin(pg_current_snapshot()) и продвигается через unselected public rows, включая пустую selected page. Bootstrap pin резервируется до REPEATABLE READ snapshot и удерживается до verified staged adoption/ack; prune требует retention, доставленную очередь и все active pins/required consumer floors. Missing tail, expired pin, отсутствующий selected SnapshotSource или replay_only ниже floor требуют rebootstrap. Позиции feed не заменяют gapless sourceSequence наблюдений и не доказывают свежесть БД. (D52, D60, D63, REQ-FAIL-22)

## Пример ответа

```json
{
  "schema": "payment.admission.v1",
  "result": "accepted",
  "operationRef": "op-1",
  "revision": 1,
  "digest": "sha256:4e51ca0273f40946bfc6ada7f414e9fd8d6228786142c0391070fa51132f4b52",
  "outcomeQueryRef": "q-op-1",
  "receipt": "CommandAccepted"
}
```
