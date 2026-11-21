# Server API v1

Статус: PLAN1.P8.2. Нормативная карта HTTP — этот документ и `spec/server/openapi.v1.json`.
Wire-схемы живут в `spec/revisions/2026-10/`; PHP и Dart читают одну ревизию (D9, REQ-CON-03).
Контроллеры этот пункт не подменяют: схемы и карта объявлены до них.

Префикс маршрута — `/billium/v1`. Host может добавить свой mount, не меняя route identity (D58).
`sellerRef` в пути — селектор: он сравнивается с аутентифицированным контекстом и с телом.
Расхождение — отказ до чтения чужого объекта (REQ-CON-21, INV-14, D23).

## Байты, digest и идемпотентность

Команды и ответы — `Content-Type: application/json`, ошибки — `application/problem+json`.
`Accept` тот же. Тело — UTF-8. Глубина и размер проверяются до admission; числа берутся из
конфигурации лимитов и возвращаются в `billium.capabilities.v1` (D57).

Digest — D32: SHA-256 канонизации RFC 8785 всего envelope без объявленного члена digest
(`digest` или `contentDigest`). Это не whitelist полей DTO. Необязательное поле ответа,
включая неизвестное членом потребителя поле, входит в digest до проверки. Транспортные
заголовки, correlation и HTTP-статус в semantic digest не входят (D32, REQ-CON-32).

Команды закрыты: неизвестное поле — `billium.validation.invalid` до эффекта. Ответы из
открытого набора (`additionalProperties` не запрещены) сохраняют такие поля в `raw`.
Уже опубликованные feed selection/page/bootstrap остаются закрытыми, как в P3; этот пункт
их не переоткрывает (D63).

`commandKey` в теле — идентичность команды. Заголовок `Idempotency-Key`, если он есть,
обязан совпасть с `commandKey`; иначе `billium.validation.invalid` до эффекта (INV-05).
У наблюдения идентичность — `eventId`, не заголовок. Timeout транспорта не терминальный
отказ и не новый ключ: клиент хранит исходные key и digest и читает receipt или find
(INV-04, REQ-CON-05). HTTP timeout не имеет смысла PSP. 5xx после commit не разрешает
новый `commandKey`.

Неизвестный обязательный major (`schema` вне ревизии или вне принятых capabilities)
отклоняется до custody и эффекта: `billium.schema.unsupported` (REQ-CON-20). Неизвестный
enum со бизнес-смыслом не отображается на `failed` или `succeeded`.

## Статусы

Успех отделён от problem+json. Таблица ниже собрана из реестра `problem-codes.json`
(category, retryable, sameKey, receipt — D31, M-45, REQ-CON-19). Отдельной retry-таблицы
Dart нет. Функция одна: `scripts/spec/check-server-api.py`.

| Исход | HTTP |
|:--|:--|
| новая принятая команда | 202, исходный admission при replay не переписывается |
| known-terminal, чтение, сохранённый синхронный receipt | 200 |
| custody parked | 202 и `ledger.observation_custody.v1` с именованным `custodyStatus`; это не бизнес-receipt (INV-08) |
| validation, schema, money | 400, receipt `none` |
| credential отсутствует или недействителен | 401 `billium.auth.unauthenticated`, receipt `none` |
| отказ scope или действия, без утечки существования | 403 `billium.auth.denied`, receipt `none` |
| конфликт ключа или бизнес-состояния | 409 |
| `in_flight`, `lookup.unavailable`, `transport.unavailable` | 503 и `Retry-After`, когда срок известен; receipt `none` |
| `key.expired` | 410, receipt `none` |
| `recovery.required`, `installation.quarantined` | 423, receipt `none` |
| модуль не включён | 409 `billium.module.unavailable`, receipt `none`, до денежного эффекта (D55) |

Ingress throttle 429 не издаётся: порта и конфигурации лимита запросов нет, зависимости
на очередь или Redis нет. Код для 429 в реестр не добавлен.

Problem+json для `billium.observation.parked` остаётся 409, если хост отдаёт проблему.
Нормативный успех parked custody на маршруте наблюдений — 202 и схема custody
(HTTP-21, D31, владельцы Server и ledger).

<!-- billium-problem-http:start -->
| Код | HTTP | Категория | Повторяемо | Тот же ключ | Receipt |
|:--|--:|:--|:--|:--|:--|
| `billium.validation.invalid` | 400 | validation | нет | да | none |
| `billium.schema.unsupported` | 400 | schema | нет | да | none |
| `billium.money.scale_invalid` | 400 | money | нет | да | none |
| `billium.money.currency_mismatch` | 400 | money | нет | да | none |
| `billium.auth.denied` | 403 | auth | нет | да | none |
| `billium.idempotency.conflict` | 409 | idempotency | нет | нет | none |
| `billium.idempotency.in_flight` | 503 | idempotency | да | да | none |
| `billium.key.expired` | 410 | key | нет | нет | none |
| `billium.recovery.required` | 423 | recovery | нет | да | none |
| `billium.lookup.unavailable` | 503 | lookup | да | да | none |
| `billium.refund.capacity_exceeded` | 409 | refund | нет | нет | stored |
| `billium.operation.not_cancellable` | 409 | operation | нет | нет | stored |
| `billium.observation.parked` | 409 | observation | да | да | none |
| `billium.observation.conflict` | 409 | observation | нет | нет | stored |
| `billium.usage.conflict` | 409 | usage | нет | нет | none |
| `billium.policy.unknown` | 409 | policy | нет | нет | stored |
| `billium.invoice.frozen` | 409 | invoice | нет | нет | stored |
| `billium.allocation.reallocation_required` | 409 | allocation | нет | нет | stored |
| `billium.installation.quarantined` | 423 | installation | нет | да | none |
| `billium.transport.unavailable` | 503 | transport | да | да | none |
| `billium.allocation.exceeds_open` | 409 | allocation | нет | нет | stored |
| `billium.allocation.exceeds_available` | 409 | allocation | нет | нет | stored |
| `billium.invoice.transition_invalid` | 409 | invoice | нет | нет | stored |
| `billium.reference.unknown` | 409 | reference | да | да | none |
| `billium.capability.unsupported` | 409 | capability | нет | да | none |
| `billium.capture.capacity_exceeded` | 409 | capture | нет | нет | stored |
| `billium.authorization.capacity_exceeded` | 409 | authorization | нет | нет | stored |
| `billium.operation.financial_hold` | 409 | operation | нет | нет | stored |
| `billium.lookup.ambiguous` | 409 | lookup | нет | да | none |
| `billium.module.unavailable` | 409 | module | нет | да | none |
| `billium.auth.unauthenticated` | 401 | auth | нет | да | none |
<!-- billium-problem-http:end -->

## Возможности

`GET /billium/v1/capabilities` возвращает `billium.capabilities.v1` для аутентифицированных
installation и environment. В ответе: `specRevision`, `specHash`, `serverApiVersion`,
принятые command schema, производимые response schema, event schema, включённые модули и
их API-версии, флаги `charge` / `refund` / `find` / `receipt` / `document` / `execution`,
эффекты `sale` / `authorize` / `capture` / `voidAuthorization` / `cancel`, лимиты тела и
ссылка политики полноты lookup. `selectionMode` всегда `explicit`: включение модуля не
расширяет уже закреплённый набор типов потребителя (D63). Каталоги называют revision,
envelope schema и явные event type. Это не список продавцов, не credential, не secret
locator, не ticket и не право (D57, D61).

Переговоры о capability не авторизуют вызов. Клиент не шлёт команду, если нужной schema
или capability нет. Кэш capabilities сбрасывается по TTL, смене версии, 401 или
`schema.unsupported`. Повтор после обновления несёт исходные тело, ключ и совместимую
schema. Старая команда не пересобирается в текущий default и не конвертируется v1→v2.
Если исходная форма больше не принимается, клиент читает receipt или find (D58, D61).

`features.execution = false` значит, что маршрут execute не вызывается. `effects.sale`
не переименовывает `payment.execute.v1`. `effects.cancel` — местный запрет новой отправки,
не provider void. `authorize`, `capture` и `voidAuthorization` — отдельные команды;
дочерние исходы — `payment.effect.outcome.v1`, не расширение `payment.outcome.v1` (D57).

## Маршруты

У каждой команды указаны действие авторизации, schema запроса, schema результата и
успешные статусы. Документных команд P6 в карте нет: их wire-команды этой ревизией не
реализованы, placeholder не публикуется. `readRender` остаётся значением `queryKind`,
но HTTP-результата и маршрута document не получает.

`POST .../queries` принимает `billium.query.v1`. Результат зависит от `query`:
`paymentStatus` → `ledger.payment_status.v1` (applied, observed, financialHold, exposure,
spendable, projectionStatus, pendingAllocationReason, reconciliation; observed не
ужимается), `dueObligations`, `obligation`, `invoice`, `allocationByOperation`,
`refundAuthorization`. Суммы — MoneyWire, не float (D29, D57).

Наблюдение требует `SourceAuthorityGrant`, выведенный из principal, на
sourceAuthority, seller, environment, channel и partition. Тело grant не создаёт.
Чужой source получает тот же 403 `billium.auth.denied` с receipt `none` и без строк
inbox, inbox_conflict, financial и outbox. Существование чужого события не раскрывается.
Дубликат ищется только в выданном составном namespace (D63, HTTP-21).

Feed open/read/bootstrap используют ответы P3 `billium.feed.selection.v1`,
`billium.feed.page.v1` и `billium.feed.bootstrap.v1`. Запросы — новые закрытые schema.
Cursor привязан к subscription, scope, lineage и selectionDigest. Смена набора — новая
подписка, не старый cursor (D63).

<!-- billium-routes:start -->
| Метод | Путь | Действие | Запрос | Результат | Успех | Примечание |
|:--|:--|:--|:--|:--|:--|:--|
| GET | `/billium/live` | `health.live` | — | — | 200 | — |
| GET | `/billium/ready` | `health.ready` | — | — | 200, 503 | — |
| GET | `/billium/v1/capabilities` | `capabilities.read` | — | `billium.capabilities.v1` | 200 | — |
| POST | `/billium/v1/sellers/{sellerRef}/payments/execute` | `payment.execute` | `payment.execute.v1` | `payment.admission.v1` | 202, 200 | — |
| POST | `/billium/v1/sellers/{sellerRef}/payments/refund` | `payment.refund` | `payment.refund.v1` | `payment.admission.v1` | 202, 200 | — |
| POST | `/billium/v1/sellers/{sellerRef}/payments/authorize` | `payment.authorize` | `payment.authorize.v1` | `payment.admission.v1` | 202, 200 | — |
| POST | `/billium/v1/sellers/{sellerRef}/payments/capture` | `payment.capture` | `payment.capture.v1` | `payment.admission.v1` | 202, 200 | дочерний исход `payment.effect.outcome.v1` |
| POST | `/billium/v1/sellers/{sellerRef}/payments/void-authorization` | `payment.void_authorization` | `payment.void_authorization.v1` | `payment.admission.v1` | 202, 200 | дочерний исход `payment.effect.outcome.v1` |
| POST | `/billium/v1/sellers/{sellerRef}/operations/{operationRef}/cancel` | `payment.cancel` | `payment.cancel.v1` | `payment.admission.v1` | 202, 200 | — |
| POST | `/billium/v1/sellers/{sellerRef}/operations/find` | `payment.find` | `payment.find.v1` | `payment.find.v1` | 200 | — |
| POST | `/billium/v1/sellers/{sellerRef}/obligations/prepare` | `obligation.prepare` | `obligation.prepare.v1` | `billium.command_receipt.v1` | 200 | — |
| POST | `/billium/v1/sellers/{sellerRef}/occurrences/ensure` | `occurrence.ensure` | `occurrence.ensure.v1` | `billium.command_receipt.v1` | 200 | — |
| POST | `/billium/v1/sellers/{sellerRef}/collection/authorize` | `collection.authorize` | `collection.authorize.v1` | `billium.command_receipt.v1` | 200 | — |
| POST | `/billium/v1/sellers/{sellerRef}/observations` | `payment.observe` | `payment.observation.v1` | `ledger.allocation_receipt.v1` | 200, 202 | HTTP-21: grant только из principal; impersonation → 403 billium.auth.denied, receipt none; 202 → `ledger.observation_custody.v1` |
| POST | `/billium/v1/sellers/{sellerRef}/usage/facts` | `usage.accept` | `usage.fact.v1` | `usage.receipt.v1` | 200 | — |
| POST | `/billium/v1/sellers/{sellerRef}/allocations/reallocate` | `allocation.reallocate` | `allocation.reallocate.v1` | `ledger.allocation_receipt.v1` | 200 | — |
| POST | `/billium/v1/sellers/{sellerRef}/queries` | `ledger.query` | `billium.query.v1` | — | 200 | paymentStatus→`ledger.payment_status.v1`, dueObligations→`ledger.due_obligations.v1`, obligation→`ledger.obligation.v1`, invoice→`ledger.invoice.v1`, allocationByOperation→`ledger.allocations.v1`, refundAuthorization→`ledger.refund_authorization.v1`; readRender без HTTP-результата |
| POST | `/billium/v1/sellers/{sellerRef}/receipts/find` | `receipt.find` | `receipt.find.v1` | `receipt.find.v1` | 200 | — |
| POST | `/billium/v1/sellers/{sellerRef}/feed/open` | `feed.open` | `billium.feed.open.v1` | `billium.feed.selection.v1` | 200 | — |
| POST | `/billium/v1/sellers/{sellerRef}/feed/read` | `feed.read` | `billium.feed.read.v1` | `billium.feed.page.v1` | 200 | — |
| POST | `/billium/v1/sellers/{sellerRef}/feed/bootstrap` | `feed.bootstrap` | `billium.feed.bootstrap_request.v1` | `billium.feed.bootstrap.v1` | 200 | — |
<!-- billium-routes:end -->

## Установленный host

Таблица показывает объединение маршрутов, регистрируемых поддерживаемыми конфигурациями.
Доступный набор отражается в capabilities. Состав модулей и host SPI определяют доступность
новых операций. Исторические queries и receipts используют установленные read-порты (D55, D58, D61, владелец Server).

Хост задаёт лимиты `billium-server.max_body_bytes` и `billium-server.max_depth`.
Значения по умолчанию — 1048576 байт и 64 уровня JSON (D57, владелец Server).
`specHash` — SHA-256 точных байтов `revision.lock.json` (D9, владелец contracts).

<!-- billium-installed:start -->
| Метод | Путь host | Route name |
|:--|:--|:--|
| GET | `/billium/live` | `billium.server.live` |
| GET | `/billium/ready` | `billium.server.ready` |
| GET | `/billium/v1/capabilities` | `billium.server.capabilities` |
| POST | `/billium/v1/sellers/{sellerRef}/queries` | `billium.server.ledger.query` |
| POST | `/billium/v1/sellers/{sellerRef}/observations` | `billium.server.ledger.observations` |
| POST | `/billium/v1/sellers/{sellerRef}/allocations/reallocate` | `billium.server.ledger.reallocate` |
| POST | `/billium/v1/sellers/{sellerRef}/payments/execute` | `billium.server.execution.execute` |
| POST | `/billium/v1/sellers/{sellerRef}/payments/authorize` | `billium.server.execution.authorize` |
| POST | `/billium/v1/sellers/{sellerRef}/operations/{operationRef}/cancel` | `billium.server.execution.cancel` |
| POST | `/billium/v1/sellers/{sellerRef}/payments/refund` | `billium.server.execution.refund` |
| POST | `/billium/v1/sellers/{sellerRef}/payments/capture` | `billium.server.execution.capture` |
| POST | `/billium/v1/sellers/{sellerRef}/payments/void-authorization` | `billium.server.execution.void-authorization` |
| POST | `/billium/v1/sellers/{sellerRef}/operations/find` | `billium.server.execution.find` |
| POST | `/billium/v1/sellers/{sellerRef}/obligations/prepare` | `billium.server.rating.prepare` |
| POST | `/billium/v1/sellers/{sellerRef}/occurrences/ensure` | `billium.server.schedule.ensure` |
| POST | `/billium/v1/sellers/{sellerRef}/collection/authorize` | `billium.server.collection.authorize` |
| POST | `/billium/v1/sellers/{sellerRef}/usage/facts` | `billium.server.usage.accept` |
| POST | `/billium/v1/sellers/{sellerRef}/receipts/find` | `billium.server.receipts.find` |
| POST | `/billium/v1/sellers/{sellerRef}/feed/open` | `billium.server.feed.open` |
| POST | `/billium/v1/sellers/{sellerRef}/feed/read` | `billium.server.feed.read` |
| POST | `/billium/v1/sellers/{sellerRef}/feed/bootstrap` | `billium.server.feed.bootstrap` |
<!-- billium-installed:end -->

## Совместимость

N и N−1 — это две закреплённые поставки codec, а не пилотные примеры P2 (D59).
Этот пункт фиксирует форму и векторы неизвестного поля и digest. Архив предыдущей
поставки и process-isolated прогон остаются P8/P10. Forward compatibility к
неизвестным полям команды не заявляется.
