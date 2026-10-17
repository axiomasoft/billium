# billium-contracts v1

Статус: PLAN1.P1.7 (2026-10-17). Документ задаёт форму и семантику контрактов; JSON Schema, fixtures и
код пакетов пишет P2 (D9). Термины и требования — `docs/requirements.md`; архитектура —
`docs/architecture/target-architecture.md`; модель — `docs/architecture/data-model.md`.

Пакеты: PHP `axiomasoft/billium-contracts`, Dart `billium_contracts` (D9). Оба содержат схемы, value
objects, codec и коды ошибок; ни один не пишет денежное state. Dart формирует команды и queries и
читает исходы (REQ-OWN-18, REQ-CON-26).

## Принципы

1. **Версии.** Каждый envelope несёт `schema` вида `<family>.<name>.v<N>`; ревизия спеки фиксирует
   набор версий (M-01). До 1.0 — заморозка, после — только аддитивно (REQ-CON-20). Неизвестная
   обязательная схема или major-версия отвергаются до эффекта; неизвестные необязательные поля
   допустимы только вне digest и авторизации.
2. **MoneyWire (CD-05, D29).** Деньги — объект `{"amount": "<decimal>", "currency": "<ISO 4217>"}`.
   `amount` — строка в major units ровно с числом знаков scale валюты по версионированной таблице
   ISO 4217 (`"1490.00"` RUB, `"1"` JPY, `"1.000" KWD`); без экспоненты, `+`, пробелов. JSON number для
   сумм и количеств запрещён. Внутри Billium — bigint minor (D19). Totals (`capturedTotal`,
   `refundedTotal`) — та же строка в валюте операции.
3. **Идемпотентность (INV-05).** Каждая команда, меняющая деньги, несёт `commandKey` вызывающего.
   Scope ключа — `(installationRef, environment, sellerRef, operationKind, commandKey)`; отдельно
   уникальна business binding `(source.context, source.intentRef, operationKind)` — без `sellerRef`, как в
   пилоте: совпадение с операцией того же продавца возвращает её, с операцией иного продавца —
   `billium.idempotency.conflict` без раскрытия чужой операции (D36, INV-14). `digest` —
   `sha256` от RFC 8785 канонизации envelope без транспортных полей; тот же ключ с другим digest —
   `billium.idempotency.conflict`; повтор — исходный receipt (REQ-CON-32).
4. **Unknown (INV-04).** Каждая денежная команда имеет исход `accepted-unknown`; timeout и транспортная
   ошибка не означают отказ (REQ-CON-05). Разрешение — `payment.find.v1`, не новый ключ.
5. **Authority.** `sellerRef`, environment и installation берутся из аутентифицированного контекста
   хоста и сверяются с телом; ID в payload — селекторы, не права (REQ-CON-21, INV-14).
6. **Ошибки.** problem+json на HTTP и тот же объект в PHP/Dart: `code` = `billium.<category>.<slug>`,
   `category`, `retryable`, `sameKey`, `receipt`, `title`, `detail` (M-44, M-45; REQ-CON-19).
7. **Время** — RFC 3339 UTC; **ID** — непрозрачные стабильные строки.
8. **ACK ≠ receipt.** HTTP 2xx, queue ACK и callback ACK не являются бизнес-receipt (INV-08).

## Оплаты и возвраты

Совместимость с пилотом Commerz↔Billium (REQ-CON-04, REQ-CON-09; D30). Breaking-изменений нет.

| Поле пилота | Поле Billium | Совместимость |
|:--|:--|:--|
| `payment.execute.v1.schema` | `schema` = `payment.execute.v1` | совместимо: то же имя |
| `commandKey` | `commandKey` | совместимо |
| `operationRef` | `operationRef` | совместимо |
| `operationKind` | `operationKind` = `charge` | совместимо; refund — отдельный `payment.refund.v1` (D30) |
| `source.context`, `source.orderRef`, `source.intentRef` | те же | совместимо; `orderRef` необязателен вне Commerz |
| `installationRef`, `environment` | те же | совместимо |
| `executionProfile`, `routingEpoch` | те же | совместимо |
| `sellerRef`, `merchantAccountRef` | те же | совместимо |
| `money` | `money` (MoneyWire) | совместимо: форма пилота принята (D29) |
| `bindingRevision` | `bindingRevision` | совместимо |
| `returnContextRef`, `requestedAt` | те же | совместимо |
| ответ `accepted` / `known-terminal` / `conflict` | `payment.admission.v1.result` | совместимо; отдельная схема ответа (D30) |
| `payment.outcome.v1.operationRef`, `revision`, `status` | те же | совместимо |
| `money`, `capturedTotal`, `refundedTotal` | те же | совместимо: cumulative (INV-06) |
| `bindingRevision`, `sellerRef`, `merchantAccountRef` | те же | совместимо |
| `providerEvidenceRef`, `occurredAt`, `digest` | те же | совместимо |
| `originalOperationRef` (refund outcome, текст пилота) | `originalOperationRef` | совместимо: обязателен для refund |

### payment.execute.v1 (ExecutePayment)

| Поле | Тип wire | Обязательно | Семантика |
|:--|:--|:--|:--|
| `schema` | string | да | `payment.execute.v1` |
| `commandKey` | string (UUID) | да | ключ вызывающего; повтор — тот же ключ и весь envelope |
| `operationRef` | string | да | logical charge; закрепляется исполнителем атомарно |
| `operationKind` | string | да | `charge` |
| `source` | object | да | `context`, `intentRef` обязательны; `orderRef` — у Commerz |
| `installationRef`, `environment` | string | да | scope ключа; сверяются с контекстом |
| `executionProfile` | string | да | `remote-billium` или `standalone-billium`; неизменяем для операции |
| `routingEpoch` | integer | да | epoch маршрутизации на момент Tx A вызывающего |
| `sellerRef`, `merchantAccountRef` | string | да | продавец и account исполнения (REQ-OWN-21) |
| `money` | MoneyWire | да | положительная сумма |
| `bindingRevision` | string | да | ревизия frozen binding |
| `returnContextRef` | string | нет | ссылка для возврата пользователя |
| `requestedAt` | string (RFC 3339) | да | время исходного запроса; повтор не меняет |

### payment.refund.v1 (RequestRefund)

Те же `schema`, `commandKey`, `operationRef` (свой), `installationRef`, `environment`, `sellerRef`,
`money`, `requestedAt`, плюс:

| Поле | Тип wire | Обязательно | Семантика |
|:--|:--|:--|:--|
| `originalOperationRef` | string | да | исходный charge; исполнитель — тот же (INV-07) |
| `reasonCode`, `category` | string | да | неизменяемые причина и категория (REQ-CON-06) |
| `source` | object | да | `context`, `intentRef` refund-намерения |

Admission резервирует сумму под lock исходного charge: captured − confirmed − reserved/pending/unknown
(INV-10); превышение — `billium.refund.capacity_exceeded`.

### payment.cancel.v1 и payment.find.v1

| Поле | Тип wire | Обязательно | Семантика |
|:--|:--|:--|:--|
| `operationRef` | string | да | цель |
| `commandKey` | string | да (cancel) | ключ cancel-команды |
| `sellerRef`, `environment`, `installationRef` | string | да | scope |
| `status` (ответ find) | string | да | `accepted-unknown`, `pending`, `succeeded`, `failed-terminal`, `cancelled`, `absent` |
| `revision`, `digest`, `capturedTotal`, `refundedTotal`, `reservedRefundTotal` | integer/string | да | cumulative снимок |
| `absenceCertificate` | object | при `absent` | доказательство полноты lookup в namespace (REQ-FAIL-08) |

Cancel запрещает новые отправки; отправленное не отменяет; `cancelled` — только при доказанном
отсутствии эффекта (REQ-CON-07). Find при stale replica, отказе доступа, пробеле retention или restore
отвечает `billium.lookup.unavailable` / `billium.recovery.required`, не `absent` (REQ-CON-08).

### payment.admission.v1 и payment.outcome.v1

`payment.admission.v1`: `result` (`accepted` | `known-terminal` | `conflict`), `operationRef`,
`revision`, `digest`, `outcomeQueryRef`, `receipt` = `CommandAccepted`.
`payment.outcome.v1` — поля пилота (таблица выше) + `originalOperationRef` для refund; та же revision
с другим digest — quarantine у потребителя; каждое материальное изменение суммы — новая revision.

## Usage и overage

CD-15: Billium принимает факты по своему контракту и не импортирует источник (REQ-CON-15…17, D13).
OverageEvent v1 Tariffler не меняется; его адаптер у источника отображает событие в `usage.fact.v1`
по compatibility map, которую ведёт владелец источника.

### usage.fact.v1

| Поле | Тип wire | Обязательно | Семантика |
|:--|:--|:--|:--|
| `schema` | string | да | `usage.fact.v1` |
| `source`, `factId` | string | да | стабильная identity факта у источника |
| `revision` | integer | да | ревизия факта; коррекция — та же identity, выше revision |
| `sellerRef`, `subjectRef` | string | да | продавец и billable-субъект |
| `metricRef`, `metricVersion` | string | да | неизменяемое определение метрики |
| `semantics` | string | да | `additive` / `gauge` / `distinct` (REQ-CON-16) |
| `unit` | string | да | единица количества |
| `quantity` | string (decimal) | да | количество; не деньги, без float |
| `interval` | object `{start, end}` | да | RFC 3339 UTC, полуинтервал |
| `eventAt`, `observedAt` | string | да | время события и время наблюдения источником |
| `provenance` | object | да | коллектор, версия, исходный ref |
| `correctsFactId` | string | нет | связанная коррекция |
| `digest` | string | да | sha256 RFC 8785 |

Ответ `usage.receipt.v1`: `accepted` | `duplicate` (тот же digest) | `conflict` (иной digest при той же
identity и revision). Поздний факт после закрытого периода — adjustment по policy метрики
(REQ-FAIL-21), не тихий перерасчёт.

## Obligations и invoices

| Операция | Envelope | Ключевые поля | Ответ / receipt | Основа |
|:--|:--|:--|:--|:--|
| prepareObligation | `obligation.prepare.v1` | `agreementRef`, `agreementRevision`, `componentRef`, `billingOccurrenceRef`, `priceVersionRef`, `snapshotDigest`, `commandKey` | `obligationRef`, money, rounding, `taxSnapshotRef` | REQ-CON-11 |
| ensureBillingOccurrence | `occurrence.ensure.v1` | `componentRef`, `purpose`, `interval`, `scheduleRevision`, `commandKey` | `billingOccurrenceRef` (тот же при повторе) | INV-11 |
| authorizeCollection | `collection.authorize.v1` | `obligationRef`, `businessPaymentIntentRef`, `executorBindingRef` | разрешение или `already-settled` | REQ-CON-12 |
| applyPaymentObservation | `payment.observation.v1` | `operationRef`, `authoritativeRevision`, cumulative totals, `evidenceRef`, `sourceAuthority` | `AllocationReceipt` / `ObservationParked` | REQ-CON-13, REQ-CON-27 |
| queries | `billium.query.v1` | `paymentStatus`, `obligation`, `invoice`, `allocationByOperation`, `refundAuthorization`, `readRender` | снимок + revision | REQ-CON-14 |

`invoice` view: `invoiceRef`, `status` (`draft`, `issued`, `partially_paid`, `paid`, `uncollectible`,
`void`), `legalNumber` (после `issued`), `seller`, `buyerSnapshotDigest`, `currency`, `lines[]`
(`obligationRef`, money), `total`, `open`, `taxSnapshotRef`, `revision`. После `issued` поля, кроме
allow-list metadata, не меняются (INV-13). Credit note — отдельный документ со своим номером и
ссылкой на invoice (REQ-CON-25). Queries только читают и не выдают нерешённое за успех.

## События и receipts

Событие — outbox той же транзакции (REQ-CON-18): `eventId` (ULID), `eventType`, `schemaVersion`,
`sellerRef`, `aggregateRef`, `revision`, `occurredAt`, `digest`, `payload`. Повтор доставки сохраняет
identity и содержимое; потребитель дедуплицирует по `eventId` и `(aggregateRef, revision)`.

| Receipt | Кто выдаёт | Подтверждает | Не подтверждает |
|:--|:--|:--|:--|
| `CommandAccepted` | исполнитель | сохранены identity и digest команды | эффект у провайдера |
| `ProviderOutcomeRecorded` | исполнитель | нормализованный исход записан | allocation, fulfilment |
| `ProjectionApplied` | потребитель исхода | revision применена | allocation Billium |
| `AllocationReceipt` | ledger Billium | obligation получила распределение | grant, fulfilment |
| `FulfilmentReceipt` | хост | поставка завершена | проводку |
| `UsageFactAccepted` | usage Billium | факт принят один раз | rating |
| `DocumentReserved` | documents Billium | номер зарезервирован | рендер и доставку |

Типы событий v1: `payment.outcome.v1`, `obligation.created.v1`, `obligation.adjusted.v1`,
`invoice.issued.v1`, `credit_note.issued.v1`, `allocation.applied.v1`, `discrepancy.recorded.v1`;
экспорт в учёт — факты для `billium-finbridge` (REQ-CON-22, REQ-CON-35) со стабильным `sourceFactRef`.

## Ошибки

| Код | Когда | Повторяемо | Действие клиента | Основание |
|:--|:--|:--|:--|:--|
| `billium.validation.invalid` | нарушена схема или поле | нет | исправить и отправить новый ключ | REQ-CON-05, REQ-CON-20 |
| `billium.schema.unsupported` | неизвестная обязательная схема/major | нет | обновить клиента | REQ-CON-20 |
| `billium.money.scale_invalid` | строка суммы не по scale валюты или JSON number | нет | исправить сумму | REQ-CON-02, D29 |
| `billium.money.currency_mismatch` | валюта не совпадает с операцией/obligation | нет | исправить | REQ-CON-30 |
| `billium.auth.denied` | caller, seller или environment не совпадают с контекстом | нет | не повторять новым ключом | REQ-CON-05, REQ-CON-21, INV-14 |
| `billium.idempotency.conflict` | тот же ключ, другой digest | нет | найти исходную команду | INV-05, REQ-FAIL-06 |
| `billium.idempotency.in_flight` | ключ claim-нут, команда выполняется | да, тем же ключом | повторить тот же envelope позже | INV-05, M-53 |
| `billium.key.expired` | ключ старше горизонта namespace | нет | `payment.find.v1`; нового эффекта нет | REQ-FAIL-07, REQ-FAIL-16 |
| `billium.recovery.required` | restore/clone/пробел retention | нет | ждать сверки оператора | REQ-FAIL-15, REQ-FAIL-25 |
| `billium.lookup.unavailable` | lookup неполон (реплика, доступ) | да, тем же запросом | повторить find | REQ-CON-08, REQ-FAIL-08 |
| `billium.refund.capacity_exceeded` | сумма refund > доступной ёмкости | нет | уменьшить сумму или ждать исходов | INV-10 |
| `billium.operation.not_cancellable` | запрос уже отправлен или исход terminal | нет | ждать исхода; refund отдельно | REQ-CON-07 |
| `billium.observation.parked` | refund раньше платежа / нет основания | да, тем же ключом | дослать зависимость | REQ-FAIL-12 |
| `billium.observation.conflict` | та же revision с другим digest | нет | reconcile/оператор | REQ-FAIL-04 |
| `billium.usage.conflict` | та же identity и revision, иной digest | нет | новая revision факта | REQ-CON-15 |
| `billium.policy.unknown` | нет policy для денежного эффекта | нет | задать policy | REQ-ACC-23 |
| `billium.invoice.frozen` | изменение issued invoice вне allow-list | нет | credit note или void/reissue | INV-13 |
| `billium.installation.quarantined` | dispatch выключен после restore | нет | ждать допуска writer | REQ-FAIL-15 |
| `billium.transport.unavailable` | сервер недоступен / timeout | да, тем же ключом | повторить тот же envelope | REQ-CON-05, INV-04 |

Каждый код объявляет receipt policy: кэшируемые отказы (`conflict`, `validation`, `capacity`)
сохраняются как receipt ключа; auth, transient и 5xx — нет (M-45).

## План fixtures

Language-neutral vectors и golden (M-06, M-07); runner — по M-09; PHP гоняет весь корпус, Dart — срез
codec/контрактов. Поведенческие векторы денежных операций исполняет только PHP writer (REQ-OWN-18).

| Набор | Что проверяет | PHP | Dart | Оракул |
|:--|:--|:--|:--|:--|
| `money/scale` | RUB 2, JPY 0, KWD 3; лишние/недостающие знаки, экспонента, JSON number | да | да | ISO 4217 List One (DR04-11) |
| `money/bounds` | int64 границы, overflow, > 2^53 как строка | да | да | golden (DR04-12, DR04-13) |
| `money/allocation` | largest remainder, ничьи, отрицательные суммы, нулевые ratios | да | да | golden REQ-ACC-13 |
| `money/rounding` | HALF_EVEN/HALF_UP по имени режима | да | да | golden |
| `canon/digest` | RFC 8785 канонизация и sha256 digest envelopes; контрольный неверный эталон | да | да | вендоренный JCS-оракул (M-15, M-16) |
| `payment/execute` | валидные/невалидные execute, refund, cancel, find; неизвестное поле | да | да | JSON Schema 2020-12 |
| `payment/admission` | duplicate, conflict, expired, in_flight | да | codec | ожидаемые wire-исходы |
| `payment/outcome` | монотонная revision, cumulative totals, refund outcome | да | codec | golden |
| `usage/fact` | семантики, duplicate/conflict, поздний факт | да | codec | golden |
| `ledger/conservation` | invoice с частичными оплатами, refund после расчёта, prepaid | да | нет | golden REQ-ACC-10 |
| `errors/codes` | реестр кодов, receipt policy, retryable/sameKey | да | да | индекс кодов |
| `compat/pilot` | старый/новый потребитель outcome (PB-15) | да | да | pinned producer/consumer |

## Трассировка

| Элемент | Основание |
|:--|:--|
| MoneyWire | REQ-CON-02, REQ-CON-30, D29, D19 |
| commandKey + digest, scope | INV-05, REQ-CON-32, REQ-CON-21 |
| payment.execute.v1 | REQ-CON-04, REQ-CON-05, D30 |
| payment.refund.v1 | REQ-CON-06, INV-07, INV-10, D30 |
| payment.cancel.v1 | REQ-CON-07, REQ-FAIL-11 |
| payment.find.v1 | REQ-CON-08, REQ-FAIL-08, INV-04 |
| payment.outcome.v1 | REQ-CON-09, INV-06 |
| receipts | REQ-CON-10, INV-08 |
| obligation/collection/observation | REQ-CON-11, REQ-CON-12, REQ-CON-13, REQ-CON-14 |
| usage.fact.v1 | REQ-CON-15, REQ-CON-16, REQ-CON-17, D13 |
| события и outbox | REQ-CON-18, REQ-CON-35 |
| ошибки | REQ-CON-19, M-44, M-45 |
| версии и заморозка | REQ-CON-20, M-01 |
| Dart — клиент | REQ-OWN-18, REQ-CON-26, REQ-CON-37 |
| fixtures PHP/Dart | REQ-CON-03, REQ-ACC-13, M-06, M-09 |
