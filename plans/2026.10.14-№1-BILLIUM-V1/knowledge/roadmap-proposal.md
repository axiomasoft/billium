# Billium — roadmap-proposal P2–P10 (PLAN1.P1.8)

Вход design-сессии следующей фазы (D1, D7 п. 4). Состав фаз совпадает со скелетом plan.json; изменены
только зависимости: P5 зависит от P3, а не от P4, — execution отдаёт исходы в ledger через
`applyPaymentObservation` и не читает rating (D28). Корректировка P1.11 (P19-F03, P19-F09): приёмка
фида наблюдений (PF-13, PF-14, ACC-CON-16, ACC-RST-07) начинается в P3 — первая поставка embedded ledger
принимает feed Commerz (REQ-PROF-20, REQ-OWN-11) — и повторяется в P9 с мостом; MG-10 перенесён из P5 в P7,
которая зависит от P4 и P5 (PriceVersion компонента, REQ-OWN-19). Scope фазы берётся только отсюда, из D#, REQ/INV и
`docs/acceptance-catalog.md`. Item-детализация — дело plan-design Pn.

## P2 — Bootstrap и billium-contracts PHP+Dart

**Цель:** монорепо по `knowledge/target-structure.md`, CI-гейт и пакет контрактов с общим корпусом fixtures.
**Выходы:** `packages/contracts` (PHP), `packages/contracts-dart`, `spec/revisions/<rev>/` (JSON Schema
envelopes `.v1`, MoneyWire, problem-codes, rules index, lock), `fixtures/` и `conformance/` (runner, JCS-оракул),
реестр identities и type-map контрактов, один скрипт CI-гейта; выбор Money-библиотеки PHP прогоном fixtures.
**Зависит от:** P1 (verdict P1.9 GREEN).
**Решения:** D9, D16, D17, D19, D29, D30; REQ-CON-03, REQ-ACC-13.
**Приёмка:** PB-15; наборы fixtures `money/*`, `canon/digest`, `payment/*`, `errors/codes`, `compat/pilot`
зелёные в PHP и Dart; composer resolver на Laravel 13 (CL-116); consumer install smoke пакета контрактов.
**Вопросы/риски:** Money-библиотека (CL-117); ревизия ISO 4217 таблицы; publish_to Dart — по I-02.

## P3 — Core: platform и ledger

**Цель:** ядро и модуль ledger с conservation-инвариантами и единым путём записи.
**Выходы:** `billium-core`: OperationRunner, idempotency claim, outbox/relay, inbox, `installation_state`;
obligations, invoices (FSM, триггер заморозки), credit notes, allocations/reversals, customer credit,
`applyPaymentObservation`, queries; документ lock model; N-процессный стенд PostgreSQL.
**Зависит от:** P2.
**Решения:** D11, D20, D21, D22, D23, D25, D27, D28, D35, D41; INV-09, INV-13, INV-14.
**Приёмка:** PB-14, PF-10, PF-12, PF-13, PF-14, SB-07, ACC-CON-08, ACC-CON-09, ACC-CON-14, ACC-CON-16,
ACC-CON-17, ACC-CON-19, ACC-CON-24, ACC-CON-26, ACC-RST-05, ACC-RST-06, ACC-RST-07, ACC-RST-10 (feed — с test double
источника); fixtures `ledger/conservation`, `payment/observation`.
**Вопросы/риски:** GRANT по модулям в embedded невозможен — INV-01 только arch-тестом; горизонты retention.

## P4 — PriceVersion, rating, schedule, usage

**Цель:** неизменяемые условия, денежные occurrences и rating по принятым фактам.
**Выходы:** модули rating, schedule, usage: PriceVersion, metric definitions, `usage.fact.v1` intake,
occurrence с EXCLUDE по бизнес-интервалу, supersession, proration по исходным условиям, adjustments,
`obligation.prepare.v1`, `occurrence.ensure.v1`.
**Зависит от:** P3.
**Решения:** D12, D13, D19; INV-11, INV-12, REQ-CON-15.
**Приёмка:** MG-01, MG-02, MG-03, MG-04, MG-08, MG-09, SB-02, SB-09, EC-11, EC-12, ACC-CON-11, ACC-CON-12.
**Вопросы/риски:** `btree_gist` в embedded-хосте (запасной вариант — D# P4); календарная policy и часовые пояса
месячных окон; compatibility map OverageEvent у источника.

## P5 — Execution journal

**Цель:** собственное исполнение платежей и возвратов: один исполнитель, unknown через lookup, refund к исходному charge.
**Выходы:** модуль execution: operation/attempt/binding, lease/fence, dispatch вне Tx, inbox callbacks,
lookup/reconcile worker, refund reservation, cancel, discrepancy, restore quarantine и порт `WriterAdmission`
(D34) с адаптерами embedded и server; тест-драйвер в
`billium-testkit`; PHP API платёжных команд без server-профиля.
**Зависит от:** P3; PaymentDrivers `payment-drivers/contracts` (сосед).
**Решения:** D10, D26, D27, D28, D30, D34, D35, D36, D37, D39, D40; INV-02, INV-04, INV-07, INV-10.
**Приёмка:** PB-02…PB-11, PB-13, PB-16…PB-20, PB-22…PB-24, PF-01…PF-11, ACC-CON-01…ACC-CON-07,
ACC-CON-15, ACC-CON-18, ACC-CON-20, ACC-CON-22, ACC-CON-23, ACC-CON-25, ACC-RST-01…ACC-RST-04, ACC-RST-08,
ACC-RST-12, ACC-RST-13; test double и реальный runtime — раздельно (REQ-ACC-18).
**Вопросы/риски:** PSP первого запуска и наличие драйвера; operational owner execution (REQ-PROF-21);
ротация credentials у провайдеров не описана (DR02-T05).

## P6 — Документы, fiscal port, экспорт в учёт

**Цель:** юридическая нумерация и документы продавца, фискал через исполнителя, факты для FinBridge.
**Выходы:** модуль documents (series, legal_document, render_record, порты renderer/storage/delivery),
fiscal_receipt через fiscal-contracts, credit notes как документы; `billium-finbridge` — только после gate
потребителя; выбор renderer через gate adopt.
**Зависит от:** P3; P5 для фискала исполнителя.
**Решения:** D14, D20, D24, D17; REQ-OWN-07, REQ-CON-24, REQ-CON-22.
**Приёмка:** FD-01, FD-02, FD-03, FD-06, FD-07, FD-09, FD-10, SB-10, ACC-CON-10, ACC-RST-09, ACC-RST-11.
**Вопросы/риски:** продавцы и нумерация (I-01, CL-121), налоговые и фискальные профили (I-16), применимость
54-ФЗ (DR02-T08), подтверждение FinBridge как потребителя.

## P7 — Collection, dunning, recurring

**Цель:** опциональный модуль сбора долга и повторных списаний без второго инициатора.
**Выходы:** модуль collection: dunning case и шаги, policy versions, `collection.authorize.v1`, recurring
через execution; закрытие сбора по cancellation generation хоста.
**Зависит от:** P4, P5.
**Решения:** D15, D35; REQ-OWN-06, REQ-FAIL-19, REQ-CON-12.
**Приёмка:** MG-06, MG-10, ACC-CON-13, ACC-CON-21; повтор scheduler не создаёт второго шага.
**Вопросы/риски:** grace/ограничение доступа — policy продукта, не Billium (REQ-OWN-06); сохранённые способы оплаты у PSP (DR02-21).

## P8 — Server-профиль и Dart SDK

**Цель:** тот же код как сервис для нескольких продуктов с изоляцией продавцов.
**Выходы:** `billium-server`: HTTP API, problem+json, auth verdict port, routing продавцов, outcome query/feed
для мостов; `billium_sdk` (Dart) — только после gate потребителя; отдельный item нагрузки (M-62).
**Зависит от:** P5, P6.
**Решения:** D18, D17, D23; REQ-PROF-08, INV-14.
**Приёмка:** PB-17 в server-профиле, PB-02 через HTTP, conformance Dart-клиента; нагрузка — отдельно.
**Вопросы/риски:** нужен ли server первому потребителю (REQ-PROF-11); Dart-потребитель SDK; operational owner и SLO.

## P9 — Миграции Memster/Edufy и контракт commerz-billium

**Цель:** перенос денежных частей legacy-биллингов и приёмка моста Commerz со стороны Billium.
**Выходы:** порт импорта и migration map (M-30), cutoff/handover writer, приём feed Commerz (snapshot + cursor +
tail), conformance-набор для моста; адаптеры продуктов живут у продуктов.
**Зависит от:** P5, P8.
**Решения:** D18, D26; REQ-FAIL-28, REQ-NBR-16, REQ-CON-27.
**Приёмка:** PB-12, PB-21, PF-09, PF-13, PF-14, MG-11, ACC-CON-16, ACC-RST-07 (с мостом Commerz; embedded — P3).
**Вопросы/риски:** версия subscriptionify в Memster (DR03-T07); готовность моста в репо Commerz; первый платный потребитель.

## P10 — Release gate 1.0

**Цель:** заморозить контракты, схему и identities и подтвердить приёмку на одном candidate SHA.
**Выходы:** таблица G-условий с evidence (M-32), API manifest и BC-gate (M-34), CHANGELOG со Stability (M-35),
mutation-профиль денежных модулей (M-60), полный прогон, consumer install smoke из артефактов.
**Зависит от:** P2–P9.
**Решения:** D9, D17, D21; REQ-CON-20, REQ-ACC-15, REQ-ACC-16.
**Приёмка:** все строки `billium` каталога приёмки зелёные на candidate SHA; baseline отказов пуст для INV (M-11).
**Вопросы/риски:** публикация и платность пакетов (I-02); тег и релиз — только по запросу владельца.
