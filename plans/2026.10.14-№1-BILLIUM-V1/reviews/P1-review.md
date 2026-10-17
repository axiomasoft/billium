# P1 — независимый review (PLAN1.P1.9)

**Verdict:** RED

Дата: 2026-10-17. Рецензент: anthropic/claude-opus-5-5/high, сессия `c124bef1-ff2a-4408-825f-aa9c09490264` —
свежая сессия, не исполнявшая P1.1–P1.8 (их сессии: `edd9064a…`, `3b95f128…`, `4ad4cb0d…`, `c4fe3d1f…`).
Модель совпадает с авторской; независимость обеспечена отдельной сессией и контекстом. Review только
читает: артефакты P1 не правились.

Pre-review: `check-p1.py pre-review` — P1.1…P1.8 GREEN (структура, инвентари, разрешимость ссылок).
Смысловая проверка нашла один незащищённый денежный инвариант (blocker) и четыре major-дефекта, поэтому
verdict RED. Каждый finding возвращается в owning item на одну корректировку (D8), затем — повторный P1.9.

| ID | Severity | Где | Суть | Owning item |
|:--|:--|:--|:--|:--|
| P19-F01 | blocker | `docs/architecture/data-model.md` § Retention и restore, § Инварианты (INV-02) | Fence против restore/clone хранится в копируемом состоянии (`installation_state.dispatch_enabled`); внешнего допуска writer нет | P1.6 |
| P19-F02 | major | `docs/architecture/data-model.md` § Операции и блокировки («Refund authorization (ledger)») | Запись reversal до admission refund противоречит REQ-CON-14, не атомарна с refund и не имеет ключа | P1.6 |
| P19-F03 | major | `docs/contracts/billium-contracts-v1.md` § Obligations и invoices (`payment.observation.v1`) | Конверт наблюдения не покрывает поля REQ-CON-27, хотя это вход первой поставки | P1.7 |
| P19-F04 | major | `docs/contracts/billium-contracts-v1.md` § Ошибки (receipt policy) | Кэширование validation-отказов приписано M-45, а M-45 и Vaulter ADR-0029 говорят обратное | P1.7 |
| P19-F05 | major | `docs/acceptance-catalog.md` § Покрытие требований (REQ-ACC-03) | PE-01…PE-09 «учтены» одной строкой PF-02; PE-08 (ротация credentials, одинаковый external ID у двух merchants) без сценария | P1.7 |
| P19-F06 | minor | `docs/architecture/data-model.md` § Операции и блокировки; `decisions/D27.json` | Глобальный порядок locks расходится между D27 и моделью; шаг collection берёт locks до claim ключа | P1.6 |
| P19-F07 | minor | `docs/architecture/data-model.md` § Словарь данных (`money_operation`) | Business binding с `seller_ref` в словаре против пилота, контракта и матрицы пар без seller | P1.6 |
| P19-F08 | minor | `docs/architecture/data-model.md` § FSM денежной операции, § Словарь данных (`attempt`) | `attempt_no` и partial UNIQUE предполагают повторные attempts, но FSM не даёт перехода к новой attempt | P1.6 |
| P19-F09 | minor | `docs/acceptance-catalog.md` (PB-21, PB-17, MG-10); `knowledge/roadmap-proposal.md` § P5 | Приёмка INV-07 и INV-14 приходит позже фазы реализации; MG-10 в P5 зависит от PriceVersion P4 | P1.7 |

## Findings

### P19-F01 — fence restore/clone живёт внутри копируемой БД

**Где.** `data-model.md` § Retention и restore («Restore создаёт новую incarnation с `dispatch_enabled =
false`»), § Инварианты строка INV-02 (Lock: `installation_state` FOR SHARE при dispatch), матрица пар
«dispatch × restore»; `knowledge/P1.6-self-check.md` контрпример 7.

**Дефект.** Единственная защита от второго исполнителя после restore или клона — флаг и incarnation в
таблице `installation_state` той же БД. Копия БД получает этот флаг как есть (`dispatch_enabled = true`,
та же incarnation, тот же `routing_epoch`), а «новую incarnation» создаёт только ручная процедура, которую
модель не описывает. REQ-FAIL-15 требует «внешнего допуска активного writer», а пилот (SRC-005 § Rollback
gates) прямо говорит: «Activation/dispatch admission должна проверяться вне клонированного backup state».
Механизма вне БД в модели нет; ACC-RST-04 и PB-20 проверяют исход «dispatch выключен» как предусловие, а не
обнаружение копии.

**Сценарий отказа.** `standalone-billium`; ночной backup prod разворачивается как staging (или restore
выполнен, а workers стартовали до процедуры). В копии `dispatch_enabled = true`: dispatch-worker отправляет
attempts в состоянии `admitted` повторно, collection-job создаёт новые шаги и charge по obligations, уже
оплаченным в prod после backup. Binding и `credential_ref` указывают на боевой merchant account — второй
исполнитель списывает деньги реальных клиентов (INV-02, REQ-FAIL-19). Provider idempotency не спасает:
ЮKassa держит ключ 24 ч (DR02-02), CloudPayments — 1 ч (DR02-08).

**Ожидаемое исправление.** D# с ≥ 2 альтернативами на внешний допуск writer: например, dispatch (Tx B CAS)
и запуск collection требуют действующего lease/ticket от authority вне БД (порт хоста или deployment),
привязанного к `installation_ref` + incarnation; при отсутствии или несовпадении — quarantine с
`billium.installation.quarantined`. Обновить строку INV-02, матрицу пар и Retention; ACC-RST-04 дополнить
сырым клоном без процедуры restore.

### P19-F02 — «Refund authorization (ledger)» как отдельная запись до refund

**Где.** `data-model.md` § Операции и блокировки, строка «Refund authorization (ledger)» («reversal по
policy, если unallocated < суммы | одна Tx до admission refund | receipt `refundAuthorization` (REQ-CON-14)»);
`P1.6-self-check.md` контрпример 3; § Инварианты INV-09 (`reallocation_required`).

**Дефект.** (1) REQ-CON-14 называет `refundAuthorization` запросом восстановления, который «только читает»;
контракт v1 тоже даёт его только в `billium.query.v1`. Модель же делает его денежной мутацией (reversal
allocation) без envelope, `commandKey` и digest — в обход INV-05 («каждая команда, меняющая деньги, несёт
commandKey»). (2) Reversal коммитится до admission refund и не связан с ней: отказ admission
(`billium.refund.capacity_exceeded`, `billium.installation.quarantined`, `auth.denied`) или crash между Tx
оставляет allocation снятой без возврата денег. (3) Параллельно модель решает тот же случай флагом
`payment.reallocation_required`, который выключает CHECK INV-09 для платежа, а запрет новых allocation из
такого платежа (контрпример 1 self-check) нигде не закреплён constraint или триггером.

**Сценарий отказа.** Invoice 100.00 оплачен и распределён. Refund 30.00: ledger снимает allocation 30.00,
invoice → `partially_paid`, obligation open = 30.00; admission refund отклонён по ёмкости. Деньги у продавца,
долг снова открыт — collection создаёт шаг и списывает 30.00 повторно (REQ-FAIL-19, INV-09).

**Ожидаемое исправление.** Один механизм с D#: либо refund admission идёт первым, а ledger реагирует на
исход refund через `applyPaymentObservation` и policy reallocation; либо ledger-авторизация — keyed резерв с
receipt, который refund admission потребляет, а terminal-отказ освобождает. Согласовать с REQ-CON-14 (или
записать отступление D#), закрепить блок allocation при `reallocation_required` триггером и правило снятия
флага.

### P19-F03 — `payment.observation.v1` не покрывает REQ-CON-27

**Где.** `billium-contracts-v1.md` § Obligations и invoices, строка `applyPaymentObservation`: ключевые
поля только `operationRef`, `authoritativeRevision`, cumulative totals, `evidenceRef`, `sourceAuthority`;
трассировка указывает REQ-CON-13, REQ-CON-27.

**Дефект.** REQ-CON-27 требует в конверте наблюдения `schemaVersion`, `eventId`, `contentDigest`,
`sourceLineage`, `partitionRef`, `sourceSequence`, operation kind, `originalPaymentRef`, `businessIntentRef`,
`operationRevision`, state, currency, `providerAccountRef` и отдельную lineage discrepancy. В конверте нет
даже `sellerRef` (INV-14) и валюты. У execute/refund/find есть полные таблицы полей, у наблюдения — нет.
Это вход первой поставки: embedded-библиотека ledger с исходами из feed Commerz (REQ-PROF-20,
REQ-OWN-11, P3), а приёмка feed (PF-13, PF-14, ACC-CON-16) стоит только в P9.

**Сценарий отказа.** P2 детализируется «только из артефактов P1» (D7) и замораживает схему
`payment.observation.v1` по этой строке. В P3 получатель не может обнаружить пропуск sequence, restore
источника ниже применённой revision и чужого продавца (REQ-FAIL-13, REQ-FAIL-23, INV-14); добавление
обязательных полей позже — breaking (REQ-CON-20).

**Ожидаемое исправление.** Таблица полей `payment.observation.v1` с отображением каждого поля REQ-CON-27,
`sellerRef` в scope и правилами dedupe (eventId, `(operationRef, revision)`, partition sequence); проверить,
что фаза приёмки feed согласована с первой поставкой (при необходимости — правка roadmap P1.8).

### P19-F04 — receipt policy приписана M-45 с обратным смыслом

**Где.** `billium-contracts-v1.md` § Ошибки: «кэшируемые отказы (`conflict`, `validation`, `capacity`)
сохраняются как receipt ключа; auth, transient и 5xx — нет (M-45)».

**Дефект.** M-45 (`knowledge/methods.json`) и его источник `vaulter/docs/adr/0029-idempotency.md` п. 4:
«authentication, authorization, visibility, validation, 428, 429, 5xx, transient — none». Validation в
источнике не кэшируется. Нормативное утверждение ссылается на ID, который говорит обратное (D7 —
происхождение, уровень major).

**Сценарий отказа.** Клиент отправил envelope с ошибкой схемы, исправил тело и повторил тем же ключом. По
контракту ключ уже несёт receipt отказа, и исправленный envelope получает `billium.idempotency.conflict`
(другой digest); по M-45 claim откатился, и тот же ключ обрабатывается заново. Реализация P2 и fixtures
`errors/codes` (PHP и Dart) разойдутся в зависимости от того, какой из двух текстов взят за норму.

**Ожидаемое исправление.** Либо привести policy к M-45 (validation не сохраняется), либо записать D# с
отступлением от M-45 и причиной; согласовать «Действие клиента» у `billium.validation.invalid`.

### P19-F05 — REQ-ACC-03 (PE-01…PE-09) покрыт номинально

**Где.** `acceptance-catalog.md` § Покрытие требований: `REQ-ACC-03 | учтено | PF-02`.

**Дефект.** REQ-ACC-03 требует применить к Billium execution девять сценариев PE-01…PE-09 (SRC-006 §9).
Каталог не отображает их на свои строки; PF-02 (custody ответа до fenced transition) покрывает только часть
PE-02. Большинство PE имеют аналоги (PE-01 ≈ ACC-CON-01, PE-03 ≈ PF-03, PE-06 ≈ PB-23, PE-07 ≈ ACC-RST-08),
но PE-08 — «два merchants с одинаковым external ID, credential rotation, callback без internal ref» — не
имеет ни одного сценария, хотя механизм есть в модели (FS-08, REQ-OWN-20, REQ-OWN-21, D26).

**Сценарий отказа.** P5 закрывает приёмку по каталогу; ротация credentials ломает lookup/refund старых
операций или callback одного merchant применяется к операции другого с тем же external ID — регрессия не
ловится ни одним сценарием.

**Ожидаемое исправление.** В примечаниях или отдельной таблице отобразить каждый PE на строки каталога;
для непокрытых (минимум PE-08, часть PE-04 «late success после replacement refund») добавить строки
ACC-CON/ACC-RST с фазой P5; обновить строку покрытия REQ-ACC-03.

### P19-F06 — расхождение глобального порядка locks

**Где.** `decisions/D27.json` (порядок «occurrence → dunning case → obligation → invoice → payment → charge →
refund → series») против `data-model.md` § Операции и блокировки (claim → `installation_state` →
`billing_schedule` → …); строка «Collection step»: «case → obligation FOR UPDATE … Tx + admission charge».

**Дефект.** D27 не содержит `installation_state` и `billing_schedule`. Шаг collection в одной Tx с admission
charge берёт locks case и obligation раньше claim ключа и `installation_state` FOR SHARE — против правила
«claim — первая запись корневой Tx» (контрпример 4 self-check).

**Ожидаемое исправление.** Один нормативный порядок в D27 и модели; для collection — либо две Tx (шаг, затем
admission), либо claim первым.

### P19-F07 — scope business binding

**Где.** `data-model.md` § Словарь данных: `money_operation` UNIQUE (`seller_ref`, `source_context`,
`business_intent_ref`, `kind`); против § Операции (пара «тот же intent, иной ключ» — `(source_context,
business_intent_ref, kind)`), `billium-contracts-v1.md` § Принципы п. 3 и SRC-005 § Admission — без seller.

**Сценарий отказа.** Хост нескольких продавцов повторяет то же намерение новым ключом с другим `sellerRef`:
по словарю — вторая операция и второй charge, по контракту — исходная операция (REQ-FAIL-07).

**Ожидаемое исправление.** Выбрать одну форму (с D#, если отступление от пилота ради INV-14) и выровнять три
места.

### P19-F08 — FSM операции и повторные attempts

**Где.** `data-model.md` § FSM денежной операции; `attempt` (`attempt_no`, partial UNIQUE незавершённой);
INV-04 («новая attempt только после terminal-исхода прежней»).

**Дефект.** Terminal-состояния операции финальны (кроме позднего capture), перехода к новой attempt нет —
не ясно, когда она допустима и что считается «terminal-исходом прежней».

**Ожидаемое исправление.** Отдельная FSM attempt с переходом к новой attempt (по доказанному отсутствию
эффекта) либо явная норма «одна attempt на операцию, повтор — новая операция по новому основанию».

### P19-F09 — приёмка инвариантов позже фазы реализации

**Где.** `acceptance-catalog.md`: INV-07 покрыт только PB-21 (P9), refund реализуется в P5; INV-14 — только
PB-17 (P5), ledger с составными FK — P3; MG-10 (P5) требует PriceVersion компонента (REQ-OWN-19, P4), а
`roadmap-proposal.md` § P5 зависит только от P3.

**Ожидаемое исправление.** Строки P5 «смена маршрута, затем refund старого charge» (тест-идея INV-07 из
модели) и P3 «allocation платежа продавца A на obligation продавца B»; фаза MG-10 или зависимость P5 от P4.

## Проверено

1. **Решения областей.** Семь областей — D9…D15, у каждой 3–4 альтернативы, основа — факты DR-01/02/03/04 и
   CL; сквозные D16…D30 — ≥ 2 альтернатив, evidence разрешимо. Gaps: нет.
2. **INV-01…INV-08.** Constraint, lock и закрытый обход есть у каждого. Разобраны: два исполнителя
   (lease/fence CAS, неизменяемые профиль и binding — достаточно для workers), lost response (ACC-RST-01,
   -05, -09, -11), restore/clone — finding P19-F01. INV-01 в embedded держится arch-тестом — риск записан
   в self-check, принят. Дополнительно INV-09/INV-10 — P19-F02.
3. **Границы plan.md §2.** Ни одно решение не берёт права/квоты, сагу, розницу, GL, credits продуктов,
   драйверы или лицензии; мост Commerz — в Commerz; фискал — через исполнителя (CD-04). Нарушений нет;
   Brain commit `22ee611f` трогает только Package-Status, Bridge-Catalog и Canon-Decision-Log (CD-16 —
   «предложено»).
4. **Трассировка и пилот.** Покрытие REQ/INV в каталоге полное по ID; дефекты смысла — P19-F05, P19-F09.
   Поля `payment.execute.v1`, ответ admission и `payment.outcome.v1` сверены с SRC-005 § Command/Outcome
   envelope, § Admission: совпадают, breaking нет; MoneyWire `"1490.00"` совпадает с пилотом (D29).
   Отклонения — P19-F03, P19-F07.
5. **Внешние факты (12, перепроверены 2026-10-17):** DR01-08, DR01-09
   (`manual_create_service.rb` @25b8b7b, строки 24–46, 78–80); DR01-10 (`credit_notes/create_service.rb`
   139–145); DR01-17 (`create_payment_factory.rb` 9–21); DR01-20, DR01-21 (Kill Bill `payment/ddl.sql`
   @4f3fa45, строки 123, 153–161); DR01-24 (`ProcessorBase.java:169`, `ACCNT_INV_PAY`); DR01-28
   (`eventoutbox.go:13-14` @e00163c); DR04-11 (SIX List One, Pblshd 2026-09-17: RUB 2, JPY 0, KWD 3);
   DR02-01, DR02-02 (yookassa.ru interaction-format: ≤ 64 символов, 24 часа); D16 (packagist
   laravel/framework v13.37.0 от 2026-10-16, php ^8.3; php.net 8.4.26 от 24 Sep 2026; postgresql.org 16.15
   до 2028-11-09). Все подтверждены.
6. **Вопросы владельцу.** Продавцы, платность, налоги, первый потребитель, PSP, I-28, валюты, горизонты,
   BRAIN-REPO — в `owner-questions.md` с рекомендацией и «Блокирует»; в docs и D# бизнес-факты не выбраны
   (RUB — только пример формы, горизонты — вопрос RET-01). Подмен нет.
7. **Происхождение (37 нормативных утверждений).** Сверены с источником Brain: INV-03, REQ-ACC-06,
   REQ-ACC-08, REQ-ACC-09, REQ-ACC-10, REQ-ACC-15, REQ-ACC-16, REQ-ACC-17, REQ-ACC-19, REQ-CON-03,
   REQ-CON-04, REQ-CON-29, REQ-FAIL-06, REQ-FAIL-17, REQ-FAIL-27, REQ-FAIL-29, REQ-NBR-01, REQ-OUT-04,
   REQ-OWN-08, REQ-PROF-20 (случайная выборка, seed 20261017) — подтверждены. В docs/knowledge:
   target-architecture § Итог (Lago, Kill Bill), § Стек, § Модули (M-21, M-23); data-model § Общие правила
   (REQ-FAIL-29), § Retention (M-31, REQ-FAIL-15), § Операции (REQ-CON-14 — P19-F02); contracts § Принципы
   п. 3 (SRC-005), § payment.refund.v1 (INV-10), § Ошибки (M-45 — P19-F04); D25 (DR01-28), D29 (SRC-005);
   methods M-56 (agentools `12_hardening-semantics.md` HRD-15/16 @74bf925); owner-questions PSP-01 (DR02-02);
   roadmap § P5 (D28 — P19-F09); каталог REQ-ACC-03 (P19-F05). Несоответствий источнику — 2 (F02, F04).
8. **Полнота (32 ID).** P1-corpus: SRC-014, SRC-018, SRC-023, SRC-072, SRC-096, SRC-100, SRC-112,
   SRC-132, SRC-134, SRC-147, SRC-153, SRC-167, SRC-190, SRC-193, SRC-214, SRC-220 — диспозиции в
   corpus-ledger есть и согласуются с документом (SRC-100, SRC-220 сверены с Brain). P1-audit-inventory:
   AUD-015, AUD-018, AUD-071, AUD-077, AUD-078, AUD-088, C-01, C-24, FS-18, FS-22, L-10, R-02, R-21, V3-06,
   V3-15, V3-19 — покрытие в audit-matrix есть, CL-строки соответствуют смыслу. Пропусков нет.
9. **Прочее.** methods.json: 63 практики, пути agentools и erp-sdk (каталогов на машине нет) проверены по
   GitHub API на `74bf925`/`825fd60`; target-structure согласована с D17; roadmap — 9 фаз скелета.
