# billium-contracts — ревизия спеки 2026-10

Статус: **draft** до release gate P10 (M-01, M-03). Источник смысла — `docs/contracts/billium-contracts-v1.md`;
ревизия ему не противоречит, расхождение оформляется D#. PHP `axiomasoft/billium-contracts` и Dart
`billium_contracts` сверяются только через эту ревизию и `fixtures/`, не через общий код (REQ-CON-03, D9).

## Состав

| Файл | Назначение | Как меняется |
|:--|:--|:--|
| `01-money-wire.md` … `06-conformance.md` | документы правил с маркерами `[BC-<AREA>-<NN>]` | вручную, каждая строка правила со ссылками REQ/INV/D#/M (D7) |
| `schema/**` | JSON Schema 2020-12, `$id` = `urn:billium:spec:2026-10:<path>` | вручную, проверяет `scripts/spec/lint.py` |
| `problem-codes.json` | единственный реестр кодов ошибок (M-44, M-45) | вручную |
| `currencies.json` | таблица ISO 4217 со scale (D19) | `scripts/spec/build-currencies.py` из `sources/list-one.xml` |
| `sources/list-one.xml` | SIX ISO 4217 List One, байты как скачаны | заменяется только новой загрузкой |
| `rules.json` | индекс правил | `scripts/spec/build-rules.py` |
| `revision.lock.json` | sha256 каждого файла ревизии (M-03) | `scripts/spec/build-lock.py` |

Производный индекс кодов вне ревизии — `docs/contracts/problem-codes.md` (`scripts/spec/build-codes-index.py`, M-46).

Области маркеров: `MW` — MoneyWire и денежная арифметика, `ID` — идемпотентность и digest, `ENV` —
envelopes, `ERR` — ошибки, `EVT` — события и receipts, `CNF` — conformance.

## Набор envelope

Ревизия фиксирует набор версий envelope (M-01): `schema` вне этой таблицы — `billium.schema.unsupported`
(REQ-CON-20).

| schema | Файл | Направление |
|:--|:--|:--|
| `payment.execute.v1` | `schema/payment/execute.v1.json` | команда |
| `payment.authorize.v1` | `schema/payment/authorize.v1.json` | команда |
| `payment.capture.v1` | `schema/payment/capture.v1.json` | команда |
| `payment.void_authorization.v1` | `schema/payment/void-authorization.v1.json` | команда |
| `payment.execution_status.v1` | `schema/query/execution-status.v1.json` | ответ |
| `payment.refund.v1` | `schema/payment/refund.v1.json` | команда |
| `payment.cancel.v1` | `schema/payment/cancel.v1.json` | команда |
| `payment.find.v1` | `schema/payment/find.v1.json` | запрос и ответ |
| `payment.admission.v1` | `schema/payment/admission.v1.json` | ответ |
| `payment.outcome.v1` | `schema/payment/outcome.v1.json` | исход |
| `payment.observation.v1` | `schema/payment/observation.v1.json` | команда (наблюдение) |
| `allocation.reallocate.v1` | `schema/allocation/reallocate.v1.json` | команда |
| `usage.fact.v1` | `schema/usage/fact.v1.json` | команда |
| `usage.receipt.v1` | `schema/usage/receipt.v1.json` | ответ |
| `obligation.prepare.v1` | `schema/obligation/prepare.v1.json` | команда |
| `occurrence.ensure.v1` | `schema/occurrence/ensure.v1.json` | команда |
| `collection.authorize.v1` | `schema/collection/authorize.v1.json` | команда |
| `billium.query.v1` | `schema/query/query.v1.json` | запрос |
| `ledger.payment_status.v1` | `schema/query/payment-status.v1.json` | ответ |
| `ledger.due_obligations.v1` | `schema/query/due-obligations.v1.json` | ответ |
| `billium.event.v1` | `schema/event/event.v1.json` | событие |
| `payment.effect.outcome.v1` | `schema/payment/effect-outcome.v1.json` | исход child (D63) |
| `billium.event.catalog.v1` | `schema/event/catalog.v1.json` | pinned catalog (D63) |
| `billium.event.v2` | `schema/event/event.v2.json` | negotiated событие (D63) |
| `billium.event.v3` | `schema/execution/repair-event.v1.json` | зарегистрированное событие repair |
| `billium.event.v4` | `schema/execution/certified-no-effect-event.v1.json` | подтверждённое сертификатом завершение без эффекта |
| `billium.feed.selection.v1` | `schema/feed/selection.v1.json` | pinned подписка (D63) |
| `billium.feed.page.v1` | `schema/feed/page.v1.json` | страница safe feed (D52, D63) |
| `billium.feed.bootstrap.v1` | `schema/feed/bootstrap.v1.json` | snapshot с retention pin (D60, D63) |
| `usage.metric.v1` | `schema/usage/metric.v1.json` | immutable MetricVersion (D56) |
| `usage.fact.v2` | `schema/usage/fact.v2.json` | immutable attribution command (D13, D56) |
| `usage.receipt.v2` | `schema/usage/receipt.v2.json` | closed acceptance receipt (D56) |
| `usage.aggregate.v1` | `schema/usage/aggregate.v1.json` | AggregateSnapshot (D56) |
| `usage.quantity.v1` | `schema/usage/quantity.v1.json` | host-approved QuantitySnapshot (D56) |
| `rating.price.v1` | `schema/rating/price.v1.json` | immutable PriceVersion (D56) |
| `period.commercial.v1` | `schema/period/commercial.v1.json` | persisted calendar period (D12, D56) |
| `rating.basis.v1` | `schema/rating/basis.v1.json` | RatedBasis independent of payment/invoice (D56) |
| `occurrence.ensure.v2` | `schema/occurrence/ensure.v2.json` | frozen commercial period (D12, D56) |
| `obligation.prepare.v2` | `schema/obligation/prepare.v2.json` | frozen RatedBasis (D56) |
| `occurrence.no_charge.v1` | `schema/occurrence/no-charge.v1.json` | explicit no-charge receipt (D56) |
| `receipt.find.v1` | `schema/receipt/find.v1.json` | запрос и ответ поиска receipt (D31, D58) |
| `billium.capabilities.v1` | `schema/capabilities/capabilities.v1.json` | описание поддержанной семантики, не права (D57, D61, D63) |
| `ledger.allocation_receipt.v1` | `schema/ledger/allocation-receipt.v1.json` | ответ AllocationReceipt (INV-08, D31) |
| `ledger.observation_custody.v1` | `schema/ledger/observation-custody.v1.json` | именованный custody parked, не бизнес-receipt (INV-08, D63) |
| `billium.command_receipt.v1` | `schema/command/receipt.v1.json` | синхронный receipt prepare/ensure/authorize (D31) |
| `billium.feed.open.v1` | `schema/feed/open.v1.json` | запрос pinned подписки (D63) |
| `billium.feed.read.v1` | `schema/feed/read.v1.json` | запрос страницы по cursor (D63) |
| `billium.feed.bootstrap_request.v1` | `schema/feed/bootstrap-request.v1.json` | запрос snapshot с тем же selection (D63) |
| `billium.feed.bootstrap_ack.v1` | `schema/feed/bootstrap-ack.v1.json` | подтверждение durable snapshot и cursor (D60, D63) |
| `ledger.obligation.v1` | `schema/query/obligation.v1.json` | ответ query obligation (REQ-CON-14) |
| `ledger.invoice.v1` | `schema/query/invoice.v1.json` | ответ query invoice (REQ-CON-14) |
| `ledger.allocations.v1` | `schema/query/allocations.v1.json` | ответ query allocationByOperation (REQ-CON-14) |
| `ledger.refund_authorization.v1` | `schema/query/refund-authorization.v1.json` | ответ query refundAuthorization (D35) |

Общие узлы: `schema/common/defs.json` (идентификаторы, время, digest, все перечисления),
`schema/common/money-wire.json`, `schema/problem/problem.json`. Формат conformance:
`schema/conformance/vector.json`, `schema/conformance/adapter-message.json`.

## Команды

```sh
docker compose run --rm toolchain bash scripts/ci.sh --layer lint      # lint.py
docker compose run --rm toolchain bash scripts/ci.sh --layer derived   # генераторы с --check
docker compose run --rm toolchain python3 scripts/spec/build-rules.py  # перезаписать rules.json
docker compose run --rm toolchain python3 scripts/spec/build-lock.py   # перезаписать lock последним
```

Трассировка: MoneyWire — REQ-CON-02, REQ-CON-30, D29; идемпотентность — INV-05, REQ-CON-32, D32; envelopes —
REQ-CON-04…REQ-CON-21, REQ-CON-27, D30; ошибки — REQ-CON-19, M-44, M-45, D31; события — REQ-CON-18, INV-08;
conformance — REQ-CON-03, M-06, M-09…M-16.
