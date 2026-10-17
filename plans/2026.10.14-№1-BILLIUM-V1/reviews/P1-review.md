# P1 — независимый review, раунд 3 (PLAN1.P1.9)

**Verdict:** RED

Дата: 2026-10-17. Рецензент r3: anthropic/claude-opus-5-5/high, сессия `976f6f3a-29ed-4fb3-81f8-f64499359809`.
Это свежая сессия: она не исполняла P1.6 и P1.7 (сессия `c4fe3d1f-636d-44cb-9971-2edeb900d6f8`), P1.10 и
P1.11 (`a41852ec-be93-4366-bd71-925f0df9b959`), P1.12 и P1.13 (`116dbde6-1d35-4121-b6df-498c22f09c5e`,
anthropic/claude-sonnet-5-5/high). Review r1 выполнила сессия `c124bef1-ff2a-4408-825f-aa9c09490264`, r2 —
`d04db85a-a925-43cf-bdc2-db576bebdd48`. Их тексты не менялись: `reviews/P1-review-r1.md`,
`reviews/P1-review-r2.md`. Авторы P1.1–P1.5: `edd9064a…`, `3b95f128…`, `4ad4cb0d…`. Review только читает,
артефакты P1 не правились.

Pre-review: `check-p1.py pre-review` — P1.1…P1.8 GREEN. Из findings r2 закрыты четыре. P19R2-F01 закрыт
частично: D39 закрывает crash writer и копию, которая отстаёт от writer по линейной истории. Остаётся путь,
при котором после `release` в БД writer коммитятся корневые денежные Tx без lease (cancel, allocation,
admission, применение исходов). Тогда копия с `commit_no = released_commit_no` получает режим active. Кроме
того, правило для `presented > released` не задано, а выход из recovery через release не запрещён. Это
незащищённый INV-02 и REQ-FAIL-19, поэтому verdict RED. По D8 и D38 третья корректировка возможна только по
новому решению владельца, поэтому вопрос эскалирован.

| ID | Severity | Где | Суть | Owning item |
|:--|:--|:--|:--|:--|
| P19R3-F01 | blocker | `docs/architecture/data-model.md` § Retention и restore (пункт «Restore без процедуры и сырой клон»), § Операции и блокировки (строки Cancel, Allocation, Admission charge, Apply payment observation; пары «cancel / allocation / шаг collection writer × копия», «acquire × acquire»); `decisions/D39.json` (decision, consequences); `target-architecture.md` § Профили п. 4 | Водяной знак `released_commit_no` не фиксирует состояние БД: корневые денежные Tx без lease продолжаются после release, поэтому копия с равным `commit_no` получает режим active. Случай `presented > released` не определён. Recovery снимается парой release и acquire без активации оператором | P1.6 |
| P19R3-F02 | minor | `docs/contracts/billium-contracts-v1.md` § payment.observation.v1, правило 7; `docs/acceptance-catalog.md` ACC-CON-26 | Не задан момент сверки Σ refund и `refundedTotal` charge. Если наблюдение refund пришло раньше revision charge, буквальное правило записывает discrepancy. Не сказано, где получатель хранит состояние refund-операции | P1.7 |
| P19R3-F03 | minor | `billium-contracts-v1.md` § Ошибки (`billium.installation.quarantined`); `data-model.md` § Retention и restore (режим recovery) | В контракте не описан режим recovery D39: нет кода и поведения команд допуска, хост не отличает recovery от quarantine | P1.7 |
| P19R3-F04 | minor | `docs/requirements.md` § Требования к соседям (REQ-NBR-*); `data-model.md` § Диспозиции новых требований; `D39.json` | Обязанности хоста или deployment по порту `WriterAdmission` не записаны в требованиях к соседям: authority, release при штатной остановке, хранение `released_commit_no`, recovery и активация оператором | P1.6 |

## Findings

### P19R3-F01 — release не фиксирует состояние, recovery снимается без активации

**Где.** `data-model.md` § Retention и restore: «при штатной остановке (drain) writer вызывает
`release(commit_no)`, authority хранит `released_commit_no`. Acquire копии с `commit_no` ниже
`released_commit_no` → quarantine; acquire без предшествующего release … → recovery … выход —
привилегированная активация оператором». `D39.json`, decision: «первый для incarnation или presented =
released → lease, режим active; presented < released → копия, quarantine; lease истёк без release →
режим recovery». Consequences: «Стоимость: … штатный deploy (drain + release) не требует [активации]».
§ Операции и блокировки: Cancel — `money_operation FOR UPDATE`, Allocation — `claim → obligation → invoice →
payment`, Admission — `installation FOR SHARE`, Apply observation — без lease. D39, pros: «ни одной
синхронной зависимости от authority на путях cancel/apply/allocation».

**Дефект.** У D39 три пробела.

1. *Release не закрывает БД.* Release вызывает держатель lease (workers) после drain. Корневые денежные
   Tx хоста в embedded и API в server lease не требуют. Нормы, которая запрещает им коммит после release до
   следующего acquire, нет: installation_state их не блокирует, флага released нет. Rolling deploy (workers
   отдельно от web) — штатный сценарий. После него `commit_no` в БД writer выше `released_commit_no`, а копия,
   снятая до этих Tx, совпадает с released.
2. *Счётчик не идентифицирует историю.* `commit_no` — номер, а не lineage. Расходящиеся копии дают любые
   соотношения. Случай `presented > released` в decision не определён. Реализация «≥ → active» пропускает
   копию, которая сделала свои денежные Tx. Реализация «≠ → отказ» без нормы ставит БД writer в quarantine или
   recovery после каждого rolling deploy (см. п. 1).
3. *Recovery не закреплён.* Не сказано, где хранится режим recovery: у authority или флагом в БД. Release из
   recovery не запрещён. Держатель в recovery, в том числе устаревшая копия после crash writer, проходит
   штатный перезапуск: `release(n)`, затем acquire с `n = released` и режим active. Активации оператором при
   этом нет.

SRC-005 § Rollback gates (строка 261): «Activation/dispatch admission должна проверяться вне клонированного
backup state; до reconciliation effect dispatch выключен». REQ-FAIL-15: «новые отправки … выключены до сверки
inventory провайдера и внешнего допуска активного writer».

**Сценарий отказа (п. 1).** `standalone-billium`, учётные данные authority есть у того же deployment (модель
угроз r2). Snapshot S снят в момент T0, `commit_no = n`, операция O в `admitted`, obligation B открыта. После
T0 dispatch не было. Rolling deploy: workers writer делают drain и `release(n)`. API writer продолжает работу:
хост отменяет O (`payment.cancel.v1`, `n+1`), клиент оплачивает B из `customer_credit` (`n+2`). В этом окне
стартует копия из S и вызывает acquire с `n = released`. Lease свободен, release был, поэтому копия получает
режим active. `writer_checkpoint` совпадает с authority. Tx B копии отправляет O, и деньги списываются за
отменённую операцию. Collection-job копии создаёт шаг и charge по B, которая уже оплачена (INV-02,
REQ-FAIL-19). Это тот же исход, что в P19R2-F01, только в окне штатного deploy, которое D39 считает
безопасным.

**Сценарий отказа (п. 3).** Writer A падает без release. Копия S (backup T0) первой делает acquire и получает
recovery, и это правильно. Затем у S штатный перезапуск: drain, `release(m)`, acquire с `m = released`. Копия
получает режим active без активации и отправляет O, которую A отменил после T0.

**Ожидаемое исправление.** Нужен D# с ≥ 2 альтернативами, который обеспечивает три вещи:

- после `release(n)` состояние БД больше не меняется до следующего acquire. Вариант: release — Tx под
  `installation_state FOR UPDATE` ставит released; каждая корневая денежная Tx уже берёт или должна брать
  `installation_state FOR SHARE` (порядок D27) и в состоянии released отказывает или ждёт. Альтернатива:
  каждая корневая денежная Tx требует локального действующего lease;
- active выдаётся только при точном совпадении идентичности истории, например (`commit_no`, digest или
  случайный id последней строки `money_commit_log`). Любое `presented ≠ released` в обе стороны ведёт в
  quarantine или recovery;
- recovery хранится у authority по (`installation_ref`, incarnation) и снимается только активацией
  оператором с audit. Release из recovery не меняет baseline.

Строку ACC-RST-13 (P1.7) нужно дополнить сценариями: «денежные Tx API после release workers, затем копия
берёт lease», «release и acquire из recovery», «presented > released». Исход каждого — 0 отправок и 0
collection step до активации.

### P19R3-F02 — момент сверки базы refund не задан

**Где.** Contracts § payment.observation.v1, правило 7: «Σ `capturedTotal` succeeded refund одного
`originalPaymentRef` должна равняться `refundedTotal` charge; расхождение — `discrepancy.recorded.v1`».
ACC-CON-26: «в любом порядке … расхождение … — discrepancy без изменения ledger».

**Дефект.** Каноническая база выбрана верно (D41 = SRC-023 § 2), но не сказано, когда проверяется равенство.
Пусть наблюдение refund (succeeded 30.00) применяется раньше revision charge с `refundedTotal` 30.00. По
буквальному правилу Σ = 30.00 ≠ 0.00, и durable discrepancy записывается с передачей решения оператору
(REQ-FAIL-10). Revision charge, которая придёт позже, его не снимет. Порядок источника контракт не задаёт,
поэтому каждый такой refund даёт ложное расхождение. Строка ACC-CON-26 не различает временный лаг и настоящее
расхождение, и fixtures PHP и Dart могут разойтись. Остался и вопрос r2: где получатель хранит state и
evidence refund-операции. Правило говорит «меняет лишь саму refund-операцию», но в словаре нет таблицы
получателя для чужой refund-операции, а `payment` UNIQUE (`seller_ref`, `operation_ref`).

**Ожидаемое исправление.** Задать точку сверки. Варианты: при применении revision charge, при reconcile или
после terminal обеих сторон. Отставание `refundedTotal` charge до этой точки — не discrepancy. Назвать
хранение наблюдения refund у получателя (строка `payment` с `operation_kind = refund` или отдельная таблица).
В ACC-CON-26 и векторе `payment/observation` развести два порядка: refund→charge — 0 discrepancy, настоящее
расхождение — 1.

### P19R3-F03 — режим recovery не отражён в контракте

**Где.** Contracts § Ошибки: `billium.installation.quarantined` — «restore, клон, иная incarnation, lease
занят». `data-model.md` § Retention и restore: в quarantine «команды допуска получают
`billium.installation.quarantined`»; в recovery «dispatch, replay, замены и новые collection step выключены,
lookup и применение исходов разрешены».

**Дефект.** Recovery (D39) — новое состояние инсталляции, но контракт его не описывает. Не сказано, как
поступать с `payment.execute.v1` и `payment.refund.v1`: принимать (Tx A без dispatch) или отказывать. Если
отказывать, то каким кодом. Хост не отличит crash-recovery, которая ждёт активации, от quarantine копии.
Fixtures `errors/codes` неполны.

**Ожидаемое исправление.** В § Ошибки: либо расширить причину `billium.installation.quarantined` до
«захват lease без release (recovery)», либо ввести отдельный код. Явно описать поведение admission в
recovery (принимается без отправки или отказ) и действие клиента.

### P19R3-F04 — обязанности WriterAdmission не записаны в требованиях к соседям

**Где.** `docs/requirements.md` § Требования к соседям (REQ-NBR-01…16): `WriterAdmission` не упомянут.
Порт описан только в `target-architecture.md` § Профили п. 4, `data-model.md`, roadmap § P5 и D34, D39.

**Дефект.** M1 § 3 п. 1 требует отдельно записать, что нужно от соседей. После D39 от хоста или deployment
требуется многое: authority вне БД, исключительный lease, ротация checkpoint, `release` при штатной
остановке, хранение `released_commit_no`, recovery и привилегированная активация. В r2 это было отмечено как
остаточный риск. Теперь без требования соседу embedded-хост (Memster, Flex) не увидит своей обязанности,
а execution без адаптера не стартует.

**Ожидаемое исправление.** Добавить строку требования к соседям или диспозицию в `data-model.md` § Диспозиции
новых требований (право P1.6 по D8). Строка — обязанности хоста или deployment по `WriterAdmission` с
источниками REQ-FAIL-15, SRC-005 § Rollback gates, D34, D39.

## Закрытие r2

| Finding r2 | Статус | Где исправлено | Проверка |
|:--|:--|:--|:--|
| P19R2-F01 | частично | D39 (новый), D34 consequences; `data-model.md` § Инварианты INV-02, § Retention и restore, § Операции (абзац порядка, Prune / restore, 3 пары), § Словарь `money_commit_log`, FS-26; `target-architecture.md` п. 4; `target-structure.md`; ACC-RST-13 | Утверждения «второго денежного эффекта нет» и «остаточное окно отсутствует» удалены: grep по `docs/` и `decisions/` находит их только в отклонённой опции C D39 и в self-check. Закрыты crash или kill writer (recovery) и копия, отстающая по линейной истории при штатном release без последующих Tx. Не закрыты денежные Tx после release, `presented > released` и выход из recovery через release — P19R3-F01 |
| P19R2-F02 | закрыт | D41; contracts § payment.observation.v1 (правило 7, строка totals); `data-model.md` § Словарь `payment.refunded_minor`; ACC-CON-26; fixtures `payment/observation`; roadmap § P3 | База — `refundedTotal` charge, наблюдение refund только сверяется: совпадает с SRC-023 § 2. Расчёт 100.00/30.00 даёт `refunded_minor` 30.00, а не 60.00, явно в правиле 7, векторе и ACC-CON-26. Момент сверки и хранение refund у получателя — P19R3-F02 (minor) |
| P19R2-F03 | закрыт | D40; `data-model.md` строки Dispatch, Cancel, пара dispatch × cancel, абзац Attempt; D27 и D37 consequences; ACC-CON-07 | Общий ряд `money_operation`: Tx B берёт FOR SHARE с условием «не cancelled» (при READ COMMITTED перепроверяется после ожидания), cancel — FOR UPDATE и читает attempt после lock. Порядок D27 (`money_operation` → `attempt`) соблюдён, цикла с cancel нет. DB proof в ACC-CON-07 |
| P19R2-F04 | закрыт | D35 (option A, decision, 2 новых consequences); `data-model.md` § Коррекции и строка Policy reallocation; contracts `allocation.reallocate.v1` `mode` | Пример: obligation 100.00, refund 30.00. Режим `credit_note`: reversal 30.00 + credit note 30.00 → allocated 70.00 + credited 30.00 = 100.00 ≤ due, payment 70.00 ≤ 100.00 − 30.00, флаг снят. Режим `reversal`: open 30.00, collection не стартует. Три места совпадают |
| P19R2-F05 | закрыт | contracts § Ошибки (`billium.observation.parked`, `billium.observation.conflict`, абзац Receipt policy) | Receipt наблюдений — по `eventId`, назван явно. `parked` → `none`, не терминальный: повторная доставка получает текущий статус custody или исход применения (SRC-023 § 4 п. 5). «Тем же ключом» у parked удалено |

## Закрытие r1

Регрессий нет. P19-F01 остаётся частичным (продолжение — P19R3-F01). P19-F02…F09 по-прежнему закрыты.
Корректировки P1.12 и P1.13 их не откатили. D36 (binding без seller) и `refundAuthorization` read-only не
менялись. D37 сохранён: D40 не вводит состояние attempt `cancelled`. Политика receipt D31 расширена только
для наблюдений (`parked` → `none`) и с M-45 согласована. ACC-CON-18…25 и ACC-RST-12 на месте. Ссылки D31,
D34–D37 на r1 перенаправлены на `reviews/P1-review-r1.md`. На `reviews/P1-review.md` ссылается только D38,
и это ссылка на текущий review (r3).

## Проверено

1. **Закрытие r2 (проверка 1).** Каждый P19R2-F01…F05 сверен с «Ожидаемым исправлением»
   `reviews/P1-review-r2.md` по диффам `b199162` (P1.12) и `c6441b2` (P1.13) и текущему тексту. Итог в таблице
   «Закрытие r2»: 4 закрыто, 1 частично.
2. **INV-02 после D39 (проверка 2).** Разобраны пути:
   - crash или kill writer, затем копия берёт lease — закрыт: recovery без отправок и collection step;
   - штатный release, копия старше по линейной истории — закрыт: quarantine;
   - клон рядом с живым writer, чужой deployment, restore по процедуре — закрыты D34 без регрессий;
   - денежные Tx без lease после release (rolling deploy) — не закрыт;
   - `presented > released` — не определён;
   - выход из recovery через release и acquire — не закрыт.

   Последние три — P19R3-F01. Утверждения «второго денежного эффекта нет» убраны. Collection step требует
   lease. В recovery новые шаги выключены, поэтому через recovery-путь шаг по оплаченной B не создаётся;
   через путь п. 1 F01 создаётся.
3. **Refund (проверка 3).** Единственная база `payment.refunded_minor` — `refundedTotal` charge: правило 7,
   словарь, D41. Наблюдение refund не меняет ledger, двойного вычитания нет. Charge 100.00 и refund 30.00 дают
   30.00 (правило 7, вектор `payment/observation`, ACC-CON-26), а не 60.00. CHECK
   `reallocation_required` при 30.00 даёт флаг на 30.00. Остаток — P19R3-F02 (minor, без денежного эффекта).
4. **Новые D# (проверка 4).** D39 — 4 альтернативы (A–D), D40 — 3, D41 — 3. Evidence разрешимо:
   `docs/requirements.md`, `research/P1-corpus.md` (SRC-005, SRC-023), `decisions/D27.json`, `D34.json`,
   `D35.json`, `D37.json`, `reviews/P1-review-r2.md`. Границы plan.md §2 соблюдены: D39 возлагает
   authority на хост или deployment через SPI Implement и чужого владения не берёт; D40 и D41 —
   внутренние нормы Billium. Пропуск в требованиях к соседям — P19R3-F04. D38 записан как решение
   владельца с цитатой.
5. **Регрессии (проверка 5).**
   - `check-p1.py pre-review` — P1.1…P1.8 GREEN.
   - Трассировка каталога полна: ACC-CON-26 внесён в REQ-CON-27, INV-06, INV-09, REQ-FAIL-18, REQ-ACC-10,
     REQ-ACC-11; ACC-RST-13 — в INV-02, REQ-FAIL-15, REQ-FAIL-19, REQ-FAIL-25, REQ-ACC-12.
   - Roadmap: D41 и ACC-CON-26 — в § P3, D39, D40 и ACC-RST-13 — в § P5.
   - Пилот: `git diff 342479f HEAD -- docs/contracts/billium-contracts-v1.md` не затрагивает строки
     `payment.execute.v1` и `payment.outcome.v1`, breaking нет. Изменены правило 7 наблюдения, `mode`
     reallocate, коды наблюдений и вектор fixtures; изменения аддитивны или уточняют семантику v1 до
     реализации.
   - Закрытие r1 не откатилось (§ Закрытие r1).
6. **Происхождение (проверка 6, 18 утверждений из изменённых разделов).**
   1. Правило 7, база — accepted totals charge ↔ SRC-023 § 2, строка 38 («одна canonical monetary basis
      (payment accepted totals), refund snapshots — детализация/сверка»): совпадает.
   2. Правило 7, запрет двойного вычитания ↔ SRC-023 § 2 («нельзя одновременно вычесть parent
      `refundedTotal` и те же individual refund sums»): совпадает.
   3. Discrepancy при расхождении ↔ REQ-FAIL-18: совпадает; момент сверки — P19R3-F02.
   4. `parked` не терминален, применение из custody ↔ SRC-023 § 4 пп. 3, 5: совпадает.
   5. Receipt наблюдения по `eventId` ↔ REQ-CON-27 (`eventId` — identity): совпадает.
   6. `conflict` → `stored` ↔ M-45 (сохраняются бизнес-отказы по состоянию): совпадает.
   7. D40: отправленное не отменяется ↔ REQ-CON-07: совпадает.
   8. `billium.operation.not_cancellable` при `dispatching|sent` ↔ REQ-CON-07: совпадает.
   9. D40: порядок `money_operation` → `attempt` ↔ D27: совпадает.
   10. D35, `credit_note` = reversal + credit note ↔ INV-09 (CHECK `allocated + credited ≤ due`, арифметика
       выше): совпадает.
   11. Reversal `cause = refund` без collection ↔ REQ-FAIL-19: совпадает.
   12. D39: recovery без отправок до активации ↔ SRC-005 § Rollback gates, строка 261: совпадает для пути
       crash.
   13. D39: штатный release без активации ↔ та же строка 261: нарушено для пути «денежные Tx после release»
       (P19R3-F01).
   14. D39: «скопированное состояние — не fence» ↔ REQ-FAIL-15 («Скопированная epoch — не fence»): совпадает
       по замыслу; `commit_no` сравнивается с authority.
   15. Активация оператором с audit со ссылкой REQ-FAIL-10 ↔ REQ-FAIL-10 (over-refund, «передаёт решение
       оператору»): ссылка по аналогии, прямая опора — REQ-FAIL-15 («внешний допуск активного writer»),
       указанная рядом. Несоответствием не считается.
   16. ACC-RST-13: отсутствие записи — не свежее намерение ↔ REQ-FAIL-25: совпадает.
   17. ACC-CON-26 ↔ REQ-ACC-10 («refund после расчёта»): совпадает.
   18. `money_commit_log` в platform, вставка модулями-владельцами ↔ INV-01: следует прецеденту
       `outbox_event` и `idempotency_receipt` (общая инфраструктура platform), не нарушает.

   Несоответствие источнику — 1 (п. 13 = P19R3-F01).
7. **Вопросы владельцу.** `owner-questions.md` корректировками не менялся. D39 оставляет адаптеры authority
   и автоматизацию активации на P5 и бизнес-фактов не подменяет.
8. **Эскалация.** RED r3 → P1.9 `blocked`. По D8 (`policy.corrections_max = 1`) и D38 (вторая
   корректировка уже использована) третья корректировка P1.6 и P1.7 по P19R3-F01…F04 требует нового решения
   владельца. Варианты для владельца: третья корректировка с повторным review r4 или явное принятие риска
   P19R3-F01 через D# с переносом в P5 (дизайн адаптера `WriterAdmission`).
