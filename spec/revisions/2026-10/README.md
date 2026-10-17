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
| `payment.refund.v1` | `schema/payment/refund.v1.json` | команда |
| `payment.cancel.v1` | `schema/payment/cancel.v1.json` | команда |
| `payment.find.v1` | `schema/payment/find.v1.json` | запрос и ответ |
| `payment.admission.v1` | `schema/payment/admission.v1.json` | ответ |
| `payment.outcome.v1` | `schema/payment/outcome.v1.json` | исход |
| `payment.observation.v1` | `schema/payment/observation.v1.json` | команда (наблюдение) |
| `usage.fact.v1` | `schema/usage/fact.v1.json` | команда |
| `usage.receipt.v1` | `schema/usage/receipt.v1.json` | ответ |
| `obligation.prepare.v1` | `schema/obligation/prepare.v1.json` | команда |
| `occurrence.ensure.v1` | `schema/occurrence/ensure.v1.json` | команда |
| `collection.authorize.v1` | `schema/collection/authorize.v1.json` | команда |
| `billium.query.v1` | `schema/query/query.v1.json` | запрос |
| `billium.event.v1` | `schema/event/event.v1.json` | событие |

Общие узлы: `schema/common/defs.json` (идентификаторы, время, digest, все перечисления),
`schema/common/money-wire.json`, `schema/problem/problem.json`. Формат conformance:
`schema/conformance/vector.json`, `schema/conformance/adapter-message.json`.

`allocation.reallocate.v1` (D35) в набор 2026-10 не входит: его поведение появляется с ledger (P3, D43).

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
