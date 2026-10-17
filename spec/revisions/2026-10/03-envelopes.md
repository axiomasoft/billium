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

[BC-ENV-25] `billium.query.v1` только читает: `query` ∈ {`paymentStatus`, `obligation`, `invoice`, `allocationByOperation`, `refundAuthorization`, `readRender`}; ответ не выдаёт нерешённое за успех. (REQ-CON-14)

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
