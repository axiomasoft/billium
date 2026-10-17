# P1 — независимый review, раунд 2 (PLAN1.P1.9)

**Verdict:** RED

Дата: 2026-10-17. Рецензент r2: anthropic/claude-opus-5-5/high, сессия `d04db85a-a925-43cf-bdc2-db576bebdd48` —
свежая сессия, не исполнявшая P1.6, P1.7 (сессия `c4fe3d1f-636d-44cb-9971-2edeb900d6f8`) и корректировки
P1.10, P1.11 (сессия `a41852ec-be93-4366-bd71-925f0df9b959`). Review r1 — сессия
`c124bef1-ff2a-4408-825f-aa9c09490264`, текст неизменен в `reviews/P1-review-r1.md` (git mv). Авторы P1.1–P1.5:
`edd9064a…`, `3b95f128…`, `4ad4cb0d…`. Модель совпадает с авторской; независимость — отдельная сессия и
контекст. Review только читает: артефакты P1 не правились.

Pre-review: `check-p1.py pre-review` — P1.1…P1.8 GREEN. Восемь из девяти findings r1 закрыты. P19-F01 закрыт
частично: внешний допуск D34 не обнаруживает копию, если после снятия копии writer не делал dispatch, а
денежное состояние уже изменил. Это незащищённый INV-02/REQ-FAIL-15, поэтому verdict RED. Вторая корректировка
запрещена без решения владельца (D8, `policy.corrections_max = 1`): эскалация.

| ID | Severity | Где | Суть | Owning item |
|:--|:--|:--|:--|:--|
| P19R2-F01 | blocker | `docs/architecture/data-model.md` § Retention и restore («копия после остановки writer без последующих dispatch…»), § Операции и блокировки (пара «restore без процедуры × workers до сверки»); `decisions/D34.json` consequences | Checkpoint ротируется только dispatch-пакетом; cancel, применение исходов, allocation и admission writer после снятия копии не меняют fence, и копия становится writer | P1.6 |
| P19R2-F02 | major | `docs/contracts/billium-contracts-v1.md` § payment.observation.v1; `data-model.md` § Словарь (`payment.refunded_minor`) | Нет канонической денежной базы refund: `refundedTotal` charge и наблюдение refund-операции можно вычесть дважды (SRC-023 § 2) | P1.7 |
| P19R2-F03 | minor | `data-model.md` § Операции и блокировки (строки Dispatch, Cancel; пара dispatch × cancel), § FSM (абзац Attempt, D37) | Tx B не блокирует операцию, cancel не трогает attempt: CAS на attempt не видит параллельный cancel | P1.6 |
| P19R2-F04 | minor | `decisions/D35.json`; `data-model.md` § Коррекции, строка Policy reallocation; contracts `allocation.reallocate.v1` (`mode`) | Режим `credit_note` без reversal не снимает `reallocation_required` и нарушает CHECK obligation | P1.6 |
| P19R2-F05 | minor | `billium-contracts-v1.md` § Ошибки (`billium.observation.parked`, `billium.observation.conflict`) | У наблюдения нет `commandKey`, а коды требуют «тем же ключом» и `stored`; stored `parked` устаревает после применения из custody | P1.7 |

## Findings

### P19R2-F01 — fence D34 не видит изменений writer без dispatch

**Где.** `data-model.md` § Retention и restore, пункт «Restore без процедуры и сырой клон»: «копия после
остановки writer без последующих dispatch совпадает с ним по отправкам, поэтому второго денежного эффекта нет»;
пара «restore без процедуры × workers до сверки»; строки Cancel (`operation FOR UPDATE`, без
`installation_state`), Collection step (нужен только lease, без CAS checkpoint); `D34.json` consequences:
«Остаточное окно отсутствует для денежных эффектов».

**Дефект.** Authority отличает writer от копии только по `writer_checkpoint`, а checkpoint меняется только в
Tx B dispatch-пакета. Lease исключителен, но после остановки writer (deploy, сбой, потеря БД) его получает
любой держатель учётных данных authority с той же incarnation и тем же checkpoint. Копия в том же deployment
и restore без процедуры такими учётными данными обладают. Writer меняет денежное состояние и без dispatch:
cancel до отправки (REQ-CON-07), применение callback/lookup (Tx C), allocation и credit note, Tx A admission и
шаг collection. Копии эти изменения не видны. Restore без процедуры не обнаруживается, поэтому recovery
barrier («отсутствие записи — не свежее намерение», REQ-FAIL-25) не включается. Пилот (SRC-005 § Rollback
gates) требует: «Activation/dispatch admission должна проверяться вне клонированного backup state; до
reconciliation effect dispatch выключен». D34 разрешает dispatch копии без reconciliation.

**Сценарий отказа.** `standalone-billium`. Backup в T0, операция O в `admitted`. В T1 хост отменяет O
(`payment.cancel.v1`, отправки не было), и клиент оплачивает obligation B из `customer_credit`. Dispatch с
T0 не было. В T2 prod-БД теряется, оператор разворачивает backup T0 без процедуры, или копия рядом с prod
стартует после deploy-рестарта prod. Workers копии берут истёкший lease: incarnation и checkpoint совпадают
с authority. Tx B отправляет O, и деньги списываются за отменённую операцию. Collection-job создаёт шаг и
charge по B, хотя B уже оплачена: второе взыскание (REQ-FAIL-19, INV-02 — исход O и долг B у двух копий
разные).

**Ожидаемое исправление.** D# с ≥ 2 альтернативами. Варианты: (а) fence продвигается каждой корневой
денежной Tx (admission, cancel, применение исхода, allocation и reallocate, шаг collection), а не только
dispatch; (б) захват lease после истечения, а не продление тем же держателем, считается возможным restore:
quarantine и recovery barrier для `admitted` до сверки или активации оператором; (в) иное с доказательством.
Убрать утверждения «второго денежного эффекта нет» и «остаточное окно отсутствует» из data-model и D34.
В `acceptance-catalog.md` ACC-RST-04 (или новая строка ACC-RST) — сценарий «cancel или allocation после
снятия копии, без dispatch, затем копия берёт lease» с исходом 0 отправок и 0 collection step.

### P19R2-F02 — нет канонической базы refund в наблюдениях

**Где.** `billium-contracts-v1.md` § payment.observation.v1: `operationKind` = `charge|refund`, у refund
«`capturedTotal` = сумма возврата» и `originalPaymentRef`; у charge — cumulative `refundedTotal`. Правила
dedupe 1–6 не говорят, какое из двух наблюдений меняет `payment.refunded_minor` исходного платежа.
`data-model.md` § Словарь: `payment` UNIQUE (`seller_ref`, `operation_ref`) — неясно, создаёт ли наблюдение
refund-операции свою строку `payment`.

**Дефект.** SRC-023 § 2: «нельзя одновременно вычесть parent `refundedTotal` и те же individual refund sums.
Для allocation выбирается одна canonical monetary basis (payment accepted totals), refund snapshots служат
детализацией/сверкой». Конверт ввели по P19-F03 (REQ-CON-27), а это правило в контракт не перенесли.
D35 опирается на «succeeded refund приходит в ledger наблюдением (`refunded_total`)», не называя, чьим.

**Сценарий отказа.** Charge 100.00 распределён на invoice. Refund 30.00 succeeded. Получатель получает
наблюдение charge (rev 3, `refundedTotal` 30.00) и наблюдение refund-операции (`capturedTotal` 30.00,
`originalPaymentRef`). Реализация P3, применив оба, получает `refunded_minor` 60.00: `reallocation_required`
на 60.00 и credit note на 60.00 вместо 30.00 (REQ-ACC-10, INV-09). Fixtures `payment/observation` PHP и
Dart разойдутся в зависимости от выбранного толкования.

**Ожидаемое исправление.** Правило в § payment.observation.v1 (и в data-model § Словарь): какая база
меняет `payment.refunded_minor` — accepted totals charge, а наблюдение refund-операции только сверяется, или
наоборот. Запрет второго вычитания и обработка расхождения двух баз (discrepancy). Вектор в
`payment/observation` и проверка в ACC-CON-24 или ACC-CON-20.

### P19R2-F03 — dispatch × cancel без общего lock

**Где.** `data-model.md` § Операции и блокировки: Dispatch — `installation_state FOR UPDATE → CAS lease attempt
(state = admitted, epoch)`; Cancel — `operation FOR UPDATE`; пара dispatch × cancel — «CAS требует `state =
admitted` и не cancelled»; § FSM, абзац Attempt (D37): состояния attempt `admitted → dispatching → sent`, без
cancelled.

**Дефект.** Cancel меняет только операцию, Tx B — только attempt. При READ COMMITTED условие «операция не
cancelled» в CAS attempt читается снимком оператора и не перепроверяется после commit cancel: общего
блокируемого ряда нет. Нужный исход записан в паре, но операции его не обеспечивают.

**Ожидаемое исправление.** Одна норма: Tx B берёт `money_operation` FOR SHARE/UPDATE перед attempt (порядок
D27 это допускает), либо cancel переводит attempt в terminal-состояние той же Tx. ACC-CON-07 — DB proof по
выбранному механизму.

### P19R2-F04 — режим `credit_note` политики reallocation

**Где.** `D35.json` (option A: «credit note или reversal с cause = refund»); `data-model.md` § Коррекции,
строка «Refund после оплаты», строка Policy reallocation; contracts `allocation.reallocate.v1`: `mode`
(`credit_note` или `reversal`).

**Дефект.** После refund 30.00 у obligation 100.00 `allocated` = 100.00. Credit note 30.00 без reversal даёт
`allocated + credited` = 130.00 > `amount_due` (CHECK obligation), а `payment.allocated` остаётся выше net:
флаг не снимается. Режимы описаны как альтернативы, хотя `credit_note` обязан включать reversal. Сценарий
отказа: Tx reallocate в режиме `credit_note` всегда откатывается по CHECK, платёж навсегда остаётся в
`reallocation_required`. CHECK срабатывает раньше денег, поэтому это дефект формы, а не денежный.

**Ожидаемое исправление.** Описать `credit_note` как reversal с `cause = refund` плюс credit note на ту же
сумму в одной Tx, а `reversal` — как reversal без credit note (долг открыт, collection не стартует).
Выровнять D35, data-model и `mode` в контракте.

### P19R2-F05 — receipt-коды наблюдения без ключа

**Где.** `billium-contracts-v1.md` § Ошибки: `billium.observation.parked` — «Повторяемо: да, тем же ключом»,
Receipt `stored`; `billium.observation.conflict` — `stored`; § payment.observation.v1: «Наблюдение не несёт
`commandKey`: identity — `eventId`».

**Дефект.** Колонка Receipt определена через «receipt ключа» (абзац под таблицей), а у наблюдения ключа нет.
`parked` — состояние custody: после прихода зависимости наблюдение применяется из custody (SRC-023 § 4 п. 5).
Stored-receipt `parked` по `eventId` тогда возвращает повторной доставке устаревший отказ. Сценарий отказа:
источник повторяет доставку после зависимости и получает `parked` бесконечно, или реализация «чинит» это
повторным применением, и правило dedupe 1 нарушается.

**Ожидаемое исправление.** Для наблюдений Receipt — по `eventId` (назвать явно). `parked` — не терминальный
receipt: `none` или replay текущего статуса custody. Убрать «тем же ключом» у `parked`.

## Закрытие r1

| Finding r1 | Статус | Где исправлено | Проверка |
|:--|:--|:--|:--|
| P19-F01 | частично | D34; `data-model.md` § Инварианты INV-02, § Retention и restore, § Операции (Dispatch, Collection step, Prune / restore, 6 новых пар), FS-26, `installation_state`; `target-architecture.md` § Профили п. 4; ACC-RST-04, ACC-CON-15, ACC-CON-22 | Закрыты: чужой deployment (нет учётных данных), клон рядом с живым writer при удержанном lease, restore по процедуре, writer делал dispatch после снятия копии. Не закрыт случай без dispatch после копии — P19R2-F01 |
| P19-F02 | закрыт | D35; data-model: Refund authorization — только чтение, Policy reallocation, Admission refund без ledger, INV-09 (CHECK флага, триггер `allocation_guard`), § Коррекции; contracts `allocation.reallocate.v1`, `refundAuthorization`, `billium.allocation.reallocation_required`; ACC-CON-20, ACC-CON-21 | Отказ admission не трогает ledger; reversal только после succeeded refund; без `commandKey` ledger меняет только наблюдение (`eventId` + `contentDigest`); флаг выводится и снимается той же Tx. Режим `credit_note` — P19R2-F04 |
| P19-F03 | закрыт | contracts § payment.observation.v1 (23 строки полей, 6 правил dedupe), строка `applyPaymentObservation`, fixtures `payment/observation`; PF-13, PF-14, ACC-CON-16, ACC-RST-07 → P3, P9; ACC-CON-24; roadmap § P3, § P9 | Все поля REQ-CON-27 и SRC-023 § 2 отображены, `sellerRef` в scope, `schemaVersion` → `schema` по принципу 1. База refund — новый дефект P19R2-F02 |
| P19-F04 | закрыт | D31; contracts § Ошибки — колонка Receipt у 20 кодов, абзац policy; `errors/codes` | `validation.invalid` — `none`, «тот же ключ допустим»; совпадает с M-45 и vaulter ADR-0029 п. 4 (validation — none) |
| P19-F05 | закрыт | catalog § PE → каталог; ACC-CON-23 (PE-08), ACC-RST-12 (PE-04); REQ-ACC-03 — 27 строк | PE-01…PE-09 сверены с SRC-006 § 9; PE-05 частично, часть соседей названа (REQ-NBR-02, REQ-OUT-11) |
| P19-F06 | закрыт | D27 (option A, decision, consequences); data-model — единый порядок, claim первым в Collection step, Admission refund, Allocation, Apply observation | Все 17 строк операций берут звенья в порядке D27; collection — claim ключа шага первым, одна Tx |
| P19-F07 | закрыт | D36; data-model § Словарь `money_operation`, пары, INV-14 (исключение), FS-01; contracts § Принципы п. 3; ACC-CON-02, ACC-CON-18 | Три места совпадают с SRC-005 § Admission: UNIQUE `(source context, intentRef, operationKind)` без seller, иной seller → conflict |
| P19-F08 | закрыт | D37; data-model ERD (1:1), FSM (абзац Attempt), INV-04, `attempt` UNIQUE `operation_id`; ACC-RST-02, ACC-CON-06 | `attempt_no` и partial UNIQUE удалены (grep по docs и knowledge: 0); совпадает с SRC-006 § 3 («attempts — не число новых списаний»). Dispatch × cancel — P19R2-F03 |
| P19-F09 | закрыт | ACC-CON-25 (INV-07, P5), ACC-CON-19 (INV-14, P3); MG-10 → P7; roadmap § P5, § P7 | P7 зависит от P4 и P5; тест-идеи INV-07 и INV-14 из модели стоят в фазах реализации |

## Проверено

1. **Закрытие r1 (проверка 1).** Каждый P19-F01…F09 сверен с «Ожидаемым исправлением» в
   `reviews/P1-review-r1.md` по диффам `15668dc` (P1.10) и `870f1a3` (P1.11) и по текущему тексту. Итог —
   таблица выше: 8 закрыто, 1 частично.
2. **Новые D# (проверка 2).** D34 — 4 альтернативы, D35 — 3, D36 — 2, D37 — 2. Применённые P1.11 D31 и
   D32 (созданы design-коррекцией `d110fdc`) — 2 и 3. Evidence разрешимо: `docs/requirements.md`,
   `research/P1-corpus.md`, `knowledge/research/DR-02.md`, `decisions/D28.json`, `reviews/P1-review.md`.
   После git mv этого item ссылка `reviews/P1-review.md` у D31, D34–D37 указывает на r2; IDs P19-F0x
   разрешимы через таблицу «Закрытие r1». Следующей корректировке стоит перенаправить ссылку на
   `reviews/P1-review-r1.md`. Границы plan.md §2: `WriterAdmission` — порт хоста или deployment (SPI
   Implement), владение соседей не берётся; D36 — явное отступление от буквы INV-14 с альтернативами,
   границ канона не нарушает.
3. **INV-02 после F01 (проверка 3).** Разобраны пути: сырой клон в чужом deployment — закрыт; клон рядом
   с живым writer при удержанном lease — закрыт; writer сделал dispatch после копии — закрыт (checkpoint
   устарел); restore по процедуре — закрыт; копия берёт lease после остановки writer без его dispatch —
   не закрыт (P19R2-F01). Остаточные риски доступности (не денежные): authority — fail-closed зависимость
   dispatch; crash держателя между ответом authority и commit Tx B оставляет действующую БД с устаревшим
   checkpoint, и нужна активация оператором. В требованиях к соседям порт `WriterAdmission` не записан,
   он есть только в target-architecture и roadmap § P5.
4. **F02 (проверка 4).** Денежные мутации: admission charge и refund, cancel, allocation, reallocate, шаг
   collection несут `commandKey` (у шага он детерминирован из case и step_no). Применение наблюдения
   идемпотентно по `eventId` + `contentDigest` и (`sellerRef`, `operationRef`, `operationRevision`), как
   требует REQ-CON-13. `refundAuthorization` — read-only Tx без записи. Отказ refund admission
   (`capacity_exceeded`, `quarantined`, `auth.denied`) и pending или unknown refund ledger не трогают:
   долг не открывается (ACC-CON-20, ACC-CON-21).
5. **Регрессии (проверка 5).** `check-p1.py pre-review` — P1.1…P1.8 GREEN. Покрытие REQ/INV в каталоге
   полное по ID (check P1.7). Новые ACC-CON-18…25 и ACC-RST-12 внесены в строки своих «Требований»,
   REQ-ACC-11 и REQ-ACC-12. Пилот: таблицы `payment.execute.v1`, ответа admission и `payment.outcome.v1`
   корректировками не менялись (диффы касаются § Принципы п. 3, `payment.refund.v1`, § Obligations, § Ошибки,
   fixtures, трассировки). П. 3 теперь буквально совпадает с SRC-005 § Admission; breaking нет. Новый код
   `billium.allocation.reallocation_required` аддитивен. Фазы: P5 зависит от P3, P7 — от P4 и P5; MG-10
   в P7 согласован.
6. **Происхождение (проверка 6, 21 утверждение из изменённых разделов, сверены с источником).**
   (1) Retention: REQ-FAIL-15 ↔ SRC-005 § Rollback gates, строка 261 — совпадает, расхождение D34 —
   P19R2-F01. (2) contracts п. 3 и D36: business binding без seller ↔ SRC-005 § Admission, строка 71 —
   совпадает. (3) D34 option D, DR02-02: ЮKassa 24 ч — совпадает. (4) DR02-08: CloudPayments 1 ч —
   совпадает. (5) Колонка Receipt и D31 ↔ M-45 и vaulter ADR-0029 п. 4 (строка 43) — совпадает.
   (6) `refundAuthorization` read-only ↔ REQ-CON-14 — совпадает. (7) Поля `payment.observation.v1` ↔
   REQ-CON-27 и SRC-023 § 2 — совпадает. (8) dedupe п. 3 `recovery_required` ↔ REQ-FAIL-23 и SRC-023 § 4 —
   совпадает. (9) dedupe п. 4 parked ↔ REQ-FAIL-12 и SRC-023 § 4 п. 3 — совпадает. (10) dedupe п. 1–2
   conflict и quarantine ↔ REQ-FAIL-04 и SRC-023 § 4 п. 2 — совпадает. (11) § PE → каталог ↔ SRC-006 § 9 —
   суть всех девяти PE совпадает. (12) D37 «attempts — счётчик reconcile» ↔ SRC-006 § 3 («это не число
   новых списаний») — совпадает. (13) ACC-CON-23, ACC-CON-25 ↔ REQ-OWN-20 (ротация в том же account) —
   совпадает. (14) ACC-CON-25 ↔ REQ-OWN-21 (refund по binding исходной операции) — совпадает. (15) MG-10 → P7
   ↔ REQ-OWN-19 (PriceVersion компонента) — совпадает. (16) feed в P3 ↔ REQ-PROF-20, REQ-OWN-11 — совпадает.
   (17) reversal `cause = refund` без шага ↔ REQ-FAIL-19 — вывод исполнителя, оформлен D35, источнику не
   противоречит. (18) `originalPaymentRef` обязателен для refund ↔ SRC-023 § 2 — совпадает. (19)
   `partitionRef` включает seller и environment ↔ SRC-023 § 2 — совпадает. (20) база refund ↔ SRC-023 § 2 —
   правило не перенесено (P19R2-F02). (21) INV-02 «внешний допуск» и пары D34 ↔ SRC-005 строка 261 «до
   reconciliation effect dispatch выключен» — нарушено для копии без dispatch (P19R2-F01). Несоответствий
   источнику — 2.
7. **Вопросы владельцу.** `owner-questions.md` корректировками не менялся. Выбор адаптеров authority
   отнесён к P5 без выбора бизнес-фактов; подмен нет.
8. **Эскалация.** RED r2 → P1.9 `blocked`. По D8 и `policy.corrections_max = 1` вторая корректировка
   P1.6/P1.7 (P19R2-F01…F05) требует решения владельца.
