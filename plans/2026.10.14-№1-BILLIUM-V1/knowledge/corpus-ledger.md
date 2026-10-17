# Леджер корпуса Brain — диспозиция SRC

Инвентарь — `research/P1-corpus.md` (snapshot design-этапа, D6; executor его не меняет). Требования —
`docs/requirements.md`. P1.1 заполнил уровень A (45 SRC), P1.2 — уровни B (84) и C (93).

Диспозиции: «использован» — документ является источником указанных требований; «без новых
требований» — прочитан, норм для Billium сверх уже записанных не содержит; «дубль» — повторяет
указанный SRC; «процесс/история» — исследовательский, процессный или исторический материал, не
источник требований (аудит Perplexity и задания DR разбирают P1.3 и P1.4); «вне границ» — относится к
чужому владельцу.

Сверка snapshot (2026-10-17): sha256[:12] 44 документов уровня A совпали с инвентарём. Расхождение одно:
SRC-004 (`05-Projects/03-Packages/Billium/Billium.md`) — snapshot `e2c40fe7ddec`, текущий файл
`dc22ac6bd5d4`. Разница — незакоммиченная строка frontmatter `repo: ~/projects/packages/billium` в рабочем
дереве Brain и неотслеживаемый каталог `docs/` рядом; тело карточки не менялось. ID SRC-004 сохранён,
требования взяты из тела; расхождение записано как CONFLICT-06.

Сверка snapshot уровней B и C (2026-10-17): sha256[:12] всех 177 документов совпали с инвентарём,
расхождений нет. Уровень B прочитан по разделам с упоминаниями Billium/BillKit/Billiot, уровень C — по
абзацам-упоминаниям с заголовком раздела. Приёмочные разделы SRC-165, SRC-208, SRC-215 и SRC-221
прочитаны полностью.

## Леджер

| SRC | Диспозиция | Требования | Примечание |
|:--|:--|:--|:--|
| SRC-001 | использован | REQ-OWN-12, REQ-PROF-06, REQ-PROF-10, REQ-PROF-11, REQ-ACC-10, INV-09, CONFLICT-02, CONFLICT-08 | Рекомендация дистрибуции не принята владельцем; из неё взяты различие осей и примеры сохранения денег; модель D2/B-B — hypothesis до I-02 |
| SRC-002 | использован | REQ-OWN-01, REQ-OWN-03, REQ-OWN-08, REQ-PROF-01, REQ-PROF-04, REQ-CON-02, REQ-CON-23, REQ-CON-24, REQ-OUT-01, INV-07 | Канон денег: §1 контуры, §2 профиль в операции, §3 PaymentDrivers, §4 Billium, §5 фискал, §8 анти-дубли, §9 мосты, §10 inputs |
| SRC-003 | использован | REQ-OWN-03, REQ-CON-11, REQ-CON-14, REQ-PROF-17, REQ-FAIL-19 | Прочитаны раздел Billium и строки Billium в карточках ChatomIo, Commerz, ProductCoordinator, Tariffler, UsageMeter, AiFina |
| SRC-004 | использован | REQ-PROF-11, REQ-PROF-19, CONFLICT-01, CONFLICT-02, CONFLICT-06, CONFLICT-07 | Tower; исторические решения конфликтуют с CD-03/04/15; ссылки на `Development/` не использовались; drift frontmatter — см. шапку |
| SRC-005 | использован | REQ-CON-04, REQ-CON-09, REQ-CON-10, REQ-PROF-04, REQ-FAIL-01, REQ-ACC-01, REQ-NBR-02, INV-02, INV-10 | Пилот: envelopes, identities, admission, receipts, FSM, failure matrix, PB-01…24, gates миграции и rollback |
| SRC-006 | использован | REQ-OWN-11, REQ-PROF-07, REQ-CON-08, REQ-FAIL-08, REQ-FAIL-10, REQ-ACC-03, INV-04 | Паспорт P-02: authority, binding, атомарность, strict absence, partial refunds, PE-01…09 |
| SRC-007 | дубль | SRC-008 | Разделы R5, R7, W-01, W-02/03, Memster, Edufy и Payment receiver совпадают с SRC-008; уникальна только навигация (source locator Memster) |
| SRC-008 | использован | INV-06, CONFLICT-06 | Карточка границы: cumulative totals, parent refunded total не вычитается дважды, inbox custody ≠ ACK; остальное покрыто паспортами |
| SRC-009 | использован | REQ-PROF-01, REQ-CON-01, REQ-CON-02, REQ-CON-15, REQ-OWN-15, REQ-OUT-04, REQ-OUT-06, REQ-OUT-08, CONFLICT-01 | CD-02/03/04/05/07/08/09/12/14/15 и открытые inputs I-01/02/16/18/28 |
| SRC-010 | без новых требований | — | Сгенерированная карта источников; строка Billium только перечисляет документы, все они в корпусе |
| SRC-011 | процесс/история | — | Протокол старта разработки; шаблон требований отражён в структуре docs/requirements.md, порядок фаз — вход P1.8 |
| SRC-012 | использован | REQ-OWN-02, REQ-CON-11, REQ-CON-12, REQ-CON-13, REQ-CON-28, REQ-ACC-08, INV-11, INV-12 | Паспорт P-03: owners, ports, occurrence identity, атомарность, cancellation barrier, альтернативы |
| SRC-013 | использован | REQ-CON-01, REQ-CON-22, REQ-ACC-17, REQ-NBR-07, REQ-OUT-07 | Правила мостов, реестр (`commerz-billium`, `billium-finbridge`), пары без моста |
| SRC-014 | использован | REQ-PROF-02, REQ-PROF-08, REQ-PROF-16, REQ-OWN-06, CONFLICT-02, CONFLICT-05 | Требования первой общей модели (собственная нумерация B-xx — омоним канона) и потребители |
| SRC-015 | использован | REQ-PROF-14, REQ-CON-28, REQ-ACC-04, INV-11 | Паспорт P-04 Memster: owners, ensureBillingOccurrence, fingerprint, миграция legacy ID |
| SRC-016 | использован | INV-01, INV-03, INV-05, INV-08, REQ-OWN-16, REQ-PROF-09, REQ-OUT-13 | Канон экосистемы §1–§5: принципы, B-01…B-16, реестр юнитов, маршрутизатор |
| SRC-017 | процесс/история | — | Разбор тома 1 Perplexity: статусы доказанности, C-01…26, 10 поправок, gaps G-01…06 — вход P1.3, не источник требований |
| SRC-018 | использован | REQ-CON-29, REQ-ACC-02, REQ-ACC-14, REQ-NBR-04, REQ-FAIL-12 | PF-01…14, interfaces по владельцам, preconditions, trace chain |
| SRC-019 | использован | REQ-CON-03, REQ-NBR-09, REQ-NBR-12 | Разделение книг vendor billing / учёт компании; приёмка fixtures PHP/Dart |
| SRC-020 | использован | INV-09, REQ-OUT-04, REQ-OUT-14, REQ-ACC-10 | Subledger без GL, пример сохранения, условия появления double-entry |
| SRC-021 | использован | REQ-PROF-05, REQ-CON-16, REQ-FAIL-21, INV-11 | Рекомендации I-03/04/09/10/17/21/28/32/34; принятые CD-12 части — базовые границы |
| SRC-022 | процесс/история | REQ-PROF-06 | Отчёт Perplexity тома 1 — исследовательский вход P1.3; из него взято только определение профилей A–D как оси матрицы |
| SRC-023 | использован | REQ-CON-27, REQ-FAIL-22, REQ-FAIL-23, REQ-NBR-03, REQ-OWN-14 | Feed и bootstrap: конверт, денежная семантика, committed cursor, шаги получателя |
| SRC-024 | использован | REQ-OWN-04, REQ-OWN-07, REQ-CON-25, REQ-ACC-05, REQ-NBR-13, REQ-FAIL-24 | R5: legal number/status, рендер, доставка, фискальный intent, FD-G01…07 |
| SRC-025 | использован | REQ-OWN-18, REQ-PROF-03, REQ-CON-26, REQ-OUT-03, CONFLICT-04 | Матрица функций по профилям (словарь Light/Embedded/Server заменён CD-14) |
| SRC-026 | без новых требований | CONFLICT-06 | Строка Billium: S0, «скелет без коммитов»; строку обновляет P1.8; расхождение состояния репозитория — CONFLICT-06 |
| SRC-027 | использован | REQ-OWN-01, REQ-OWN-05, REQ-OWN-08 | Capabilities: SaaS/usage PriceVersion, invoice/allocation/collection, attempt journal; остальные строки — соседи |
| SRC-028 | процесс/история | — | Исходное задание исследования v3 для Perplexity; результаты в SRC-022, разбор SRC-017 |
| SRC-029 | использован | REQ-CON-22, REQ-ACC-13, REQ-NBR-09, CONFLICT-03 | Finance wire: portable amount, fixtures, operational import passport (Billium как источник) |
| SRC-030 | использован | REQ-OWN-13, REQ-ACC-06, REQ-OUT-09 | R7: bundle components, сохранение allocation, payables вне Billium |
| SRC-031 | использован | REQ-OUT-15 | Пакет решений владельцу: статусы I-01…I-37 (I-15/16/23/28 — unknown input, I-18 — выбор владельца) |
| SRC-032 | процесс/история | — | Evidence аудита E-01…E-08 (SHA, manifests, LICENSE, Lago/Kill Bill/OpenMeter, PSP) — вход P1.3/P1.4 |
| SRC-033 | использован | REQ-OWN-12, REQ-CON-17 | Границы Tariffler: деньги и credits, OverageEvent v1, раздельные версии цены и плана |
| SRC-034 | использован | REQ-CON-15, REQ-CON-16, REQ-FAIL-21, REQ-OUT-10, REQ-ACC-09 | Usage frontier: Billium оценивает принятые факты, семантика метрик, поздний usage |
| SRC-035 | процесс/история | — | Задание DR-01 (исходники и схемы billing engines), статус ready-not-run — исполняет P1.4 |
| SRC-036 | процесс/история | — | Задание DR-02 (RU-платежи и фискализация), статус ready-not-run — исполняет P1.4 |
| SRC-037 | использован | REQ-OWN-09, REQ-CON-29, REQ-FAIL-05, REQ-FAIL-17, REQ-NBR-04 | Provider binding, query против replay, certificate отсутствия, custody ответа |
| SRC-038 | использован | REQ-PROF-15, REQ-ACC-07, REQ-NBR-11 | Паспорт P-05 Edufy: credit ledger остаётся у Edufy, Billium — только деньги |
| SRC-039 | процесс/история | — | Первичная проверка PSP (YooKassa, CloudPayments: окна idempotency, lookup, callbacks) — внешние факты для P1.4, не требования |
| SRC-040 | процесс/история | — | Задание DR-04 (финансовые контракты и authority), статус ready-not-run — исполняет P1.4 |
| SRC-041 | использован | REQ-CON-19, REQ-CON-30, REQ-CON-31, REQ-ACC-11, REQ-ACC-15, REQ-ACC-16 | Engineering Standards §1–§6; стековые нормы (PHP 8.4, PostgreSQL ≥ 16) — вход P1.6 |
| SRC-042 | процесс/история | — | Точечная первичная проверка 2026-10-12 (TigerBeetle, outbox, brick/money, moneyphp, OpenMeter) — evidence для P1.4 |
| SRC-043 | процесс/история | — | Задание DR-03 (права, квоты, credits); в P1.4 — только граница Billium↔Tariffler |
| SRC-044 | использован | REQ-OWN-10, REQ-FAIL-07, REQ-FAIL-16, REQ-FAIL-25 | Реестр денежных намерений, retention, restore и единственный send authority |
| SRC-045 | использован | REQ-CON-18, REQ-CON-21, REQ-ACC-13, CONFLICT-03 | Общая квалификация контрактов и FC-01/02/04/05/06/10/17 |
| SRC-046 | процесс/история | — | Промпт постановки PLAN1 — тот же документ, что brief M1 (sha256 `54c32461`); границы уже записаны из канона в P1.1, новых норм нет |
| SRC-047 | процесс/история | — | Журнал переноса структуры 2026-10-12: переименование BillKit → Billium, `code: billkit` и alias BillKit сохранены как ключ истории/sync; вход вопроса `repo:` в P1.8 (CONFLICT-06) |
| SRC-048 | процесс/история | — | Session handoff итераций W-01…W-03: сводки паспортов уровня A (исполнитель Commerz Light, gaps pruning receipts, Memster legacy-факты); нормы взяты из первоисточников SRC-005/006/012/015 |
| SRC-049 | использован | REQ-CON-33, CONFLICT-09 | Владение подпиской и ценой: начисление не из изменяемого поля плана, preview/фиксация/commit; «старые frozen схемы = v1», зависимость от `corex/payments` и модуль Fiscal — к CONFLICT-09 |
| SRC-050 | процесс/история | CONFLICT-02, CONFLICT-07 | Радар (BillKit-эпоха): Billium «Lago/OpenMeter/своё + payment-адаптеры ЮKassa/СБП + 54-ФЗ», ссылка на план в Development/; адаптеры и фискал заменены CD-03/04; предупреждение AGPL Lago — вход P1.3/P1.4 |
| SRC-051 | без новых требований | — | Бриф владельца: Billium — общие денежные механизмы, лёгкое ядро + серверная часть (= REQ-PROF-01); модуль оплаты — пакет монорепо Commerz, мост к биллингу пишется в Commerz (= REQ-CON-01, REQ-OUT-08); I-01 «не blocker»; Diabox — возможный потребитель (REQ-PROF-19) |
| SRC-052 | без новых требований | — | Диспозиции 48 контактов: строки Billium X-01/02/03/05/25/43 покрыты паспортами уровня A (REQ-PROF-04, REQ-CON-22, REQ-CON-25); X-06 POS и X-48 бандл — conditional (REQ-OUT-12, REQ-OWN-13); X-39 Diabox — только нужные модули; X-45 — общие имена wire/outbox не общий store |
| SRC-053 | использован | REQ-PROF-20, REQ-PROF-21 | Deep audit границ: P0-2 один writer на OperationRef (= INV-02), раскладка contracts/domain/execution, O-06 и gate Wave 4 для runtime execution |
| SRC-054 | использован | REQ-FAIL-26, REQ-OUT-19 | CONTEXT-MAP: envelope «execution → coordinator» (повторный callback ≠ повторный fulfilment); W-04 — денежный факт Billium не прямой DROP |
| SRC-055 | без новых требований | — | Screening-матрица пересечений: контакты Billium с AiFina, AiResto, CallKit, ChatomIo, Diabox, Flex, FlexCRM, FlexStore, Memster; контуры W-01/W-02/W-03/W-08; не исчерпывающий аудит, потребители — в REQ-PROF-11…19 |
| SRC-056 | процесс/история | — | Исторический промпт тяжёлой модели (W-01): Billium vendor obligations и книга AiFina — разные книги; проверить subledger против double-entry (закрыто SRC-020 / REQ-OUT-04); I-01 не blocker |
| SRC-057 | использован | REQ-OUT-16 | Integration-Map №5 (налоговые snapshots по seller/product, не глобальный tax engine), №25 (нумерация у billing, не у редактора) |
| SRC-058 | процесс/история | — | Регистрация отчёта Perplexity тома 1: SHA-256 `e333ac2a…`, locator, статус «разобран, gaps открыты» — provenance для P1.3 |
| SRC-059 | процесс/история | — | Отчёт итерации границ 2026-10-12: каталог 52 карточек, rename Billium, `code: billkit` стабилен; приоритетный seam Commerz↔Billium execution (раскрыт SRC-005/006) |
| SRC-060 | без новых требований | — | Обзор экосистемы: позиция владельца — небольшой устанавливаемый пакет Billium + серверная композиция, внешний магазин платит без центрального сервиса (= REQ-PROF-01/02), одна операция — один исполнитель (= INV-02); несколько юридических продавцов — вопрос I-01 (REQ-OUT-15) |
| SRC-061 | использован | REQ-PROF-22, CONFLICT-10 | Distribution options: свободная база без paywall-инвариантов, B-A/B-B/B-C — вопрос I-02; тезис tower «REST исключает AGPL risk» не принят |
| SRC-062 | без новых требований | — | Реестр 48 контактов: строки X-01/02/03/05/06/25/39/43/45/48 с Billium повторяют SRC-052 и паспорта уровня A; статус «catalogued» не означает API |
| SRC-063 | без новых требований | — | Индекс досье Billium: навигация к Boundary/Features/Materials, R5 (фискал, документы) и R7 (bundles) — все документы уровня A или C в корпусе |
| SRC-064 | без новых требований | — | Порядок реализации: slices S02 (Billium не нужен рознице), S08 (Memster без нового Billium server), S13 (Diabox — только нужные модули), S15 (bundle после SB-G04) — согласуется с REQ-PROF-03, REQ-PROF-07 |
| SRC-065 | без новых требований | — | Атлас взаимодействий W-01…W-03: invoice, платёж PSP, фискальный чек, PDF и печать — разные состояния (= REQ-CON-25); collector не считает invoice (= REQ-OUT-10); деньги, rewards, credits и payable — разные writers (= REQ-OUT-05) |
| SRC-066 | процесс/история | CONFLICT-01 | Tower Tariffler (BillKit-эпоха): цепочка ControLLM → Tariffler → `OverageEvent` → Billium «рейтит/инвойсит/платит»; ссылка на план в Development/; канон CD-15 — свой входной контракт Billium |
| SRC-067 | процесс/история | — | Ledger идей W-01…W-03: сохранённые альтернативы (FinancialKernel, relay/CDC, B-B) и return conditions; нормы взяты из паспортов SRC-005/006/012/020/044 |
| SRC-068 | процесс/история | — | Аудит полноты 2026-10-12: охват проектов и места синтеза (один payment executor, light/server — разные композиции, разные книги, bundle сохраняет независимые права); источники — документы уровня A |
| SRC-069 | без новых требований | — | Готовность пакетов: Billium — design-no-implementation-confirmed, блокирующие входы I-03/I-04/I-17/I-20 (= REQ-PROF-04/05, REQ-OUT-04); кода и repo locator нет (CONFLICT-06) |
| SRC-070 | использован | REQ-CON-34 | Boundary-Commerz W-01: allocation Billium опциональна и не re-charge розничного платежа; статусы и allocations одного capture — не несколько фактов выручки |
| SRC-071 | дубль | SRC-070 | Materials-Commerz: разделы R8, «Delegation» и W-01 совпадают с Boundary-Commerz (SRC-070) дословно |
| SRC-072 | процесс/история | — | Журнал 30 вызовов Perplexity подготовки: PR-12 (Billium) без body; основной материал — оригинал тома 1 и review (SRC-017/022, P1.3) |
| SRC-073 | процесс/история | — | Bounded-ответ Perplexity DM-01 о моделях дистрибуции (permissive, source available, copyleft + dual) — вход I-02/P1.4, не источник требований |
| SRC-074 | процесс/история | — | Индекс отчётов Research: статусы тома 1 (imported-original), регистрации и review — навигация |
| SRC-075 | процесс/история | — | Очередь исследований: DR-01…DR-04 submitted с bounded retrieval; правила zero-context запросов — вход P1.4 |
| SRC-076 | без новых требований | — | Индекс домена Billing: Billium-Boundaries, Billium-Research, том 1, review, DR-01/02 — все в корпусе уровня A |
| SRC-077 | использован | REQ-OWN-19 | Materials-ProductCoordinator W-03: refund компонента от исходной оплаты/цены/условий, не доля прав; coordinator не повторяет charge при сбое provisioning |
| SRC-078 | без новых требований | — | Materials-Tariffler: Billium — денежный schedule/price, бесплатные grants без invoice; collector владеет фактом, Billium рейтит неизменяемые факты, без charge внутри consume (= REQ-OWN-02, REQ-OUT-10, REQ-NBR-07) |
| SRC-079 | процесс/история | — | Shared-Layer-Draft итерации 2 (draft-not-approved): owners по областям и reuse gate — Commerz execution use-package, server billing — выбор vendor (Lago AGPL) открыт; вход P1.3 |
| SRC-080 | использован | REQ-FAIL-27 | System-Architecture: годовая оплата и месячная квота — разные occurrences; refund ≠ revoke; отмена соглашения ≠ rollback операции |
| SRC-081 | без новых требований | — | Tower Commerz: Billium — опциональное удалённое исполнение оплат и инвойсы через мост `commerz-billium`, PSP — PaymentDrivers (= REQ-PROF-18, REQ-CON-01, REQ-OUT-06) |
| SRC-082 | процесс/история | CONFLICT-02, CONFLICT-07 | Индекс пакетов (BillKit-эпоха): Billium — «оркестратор поверх ledger + payment-адаптеров + 54-ФЗ», план в Development/; заменено CD-03/04 и I-20 |
| SRC-083 | без новых требований | — | Очередь решений I-01…I-37: I-01 «не важно» (deferred), I-03/04/17/20/28 — рекомендации, не owner-accepted; связь Billium↔Tenant не подтверждена; совпадает с REQ-PROF-04/05, REQ-OUT-04, REQ-OUT-15 |
| SRC-084 | дубль | SRC-083 | Open-Questions: таблица I-01…I-37 повторяет SRC-083; отличие — формулировка I-18 (кандидаты Commerz lightweight / Memster / ERP issuer против рекомендации Memster), выбор остаётся за владельцем (REQ-OUT-15) |
| SRC-085 | процесс/история | — | Проверка итерации финансов: commit `96411421`, Billium GL need unproved, FinancialKernel не зарегистрирован; persistence-проверки, не runtime acceptance |
| SRC-086 | процесс/история | — | Evidence Commerz `ba05983a`: 52 pure unit cases passed, DB/PSP/concurrency не запускались; контракты Billium documented/proposed — вход P1.5 (Commerz invariants) |
| SRC-087 | процесс/история | — | Отчёт итерации платежей W-02/03: перечень завершённых паспортов (SRC-006/012/034) и gaps Commerz (pruning, неполный feed, discrepancy, refund DTO); нормы взяты из паспортов |
| SRC-088 | использован | REQ-PROF-22 | Finance prerequisites: строка Billium — D2-база обязана включать invariants/recovery/export; GL mandate Billium — вопрос владельцу (I-20) |
| SRC-089 | использован | REQ-PROF-20 | Матрица диспозиций: Billium M1 DEFER — сначала obligations/invoice core и profiles, не заменять Commerz execution |
| SRC-090 | без новых требований | — | Boundary-AiFina: Billium ведёт обязательства продавца ПО — другая книга; invoice продавца для AiFina — внешний source fact через FinBridge, prepared command и recoverable receipt (= REQ-NBR-09, REQ-OUT-04) |
| SRC-091 | дубль | SRC-077 | Boundary-ProductCoordinator: разделы «Разделение ответственности», W-03 и Memster refinement совпадают с Materials-ProductCoordinator (SRC-077) |
| SRC-092 | дубль | SRC-078 | Boundary-Tariffler: разделы «Разделение ответственности» и W-03 совпадают с Materials-Tariffler (SRC-078) |
| SRC-093 | без новых требований | — | Tower ControLLM: метеринг AI, не тарифы и не биллинг; D15 — три пакета (ControLLM / Tariffler / Billium = rating + invoicing + payments); append-only usage с финальным значением после стриминга (= REQ-NBR-08, REQ-OUT-10) |
| SRC-094 | процесс/история | — | Boundary-Review: приоритеты детализации (Billium↔Commerz I-04/I-17, Billium↔Tariffler↔coordinator I-03/09/21/28, ERP↔Billium I-05/20/26) — история итераций, результаты в паспортах уровня A |
| SRC-095 | процесс/история | — | Статическая проверка корпуса подготовки: 52 карточки, 48 контактов, 30 вызовов Perplexity, SHA оригинала тома 1 неизменён; runtime-тесты не запускались |
| SRC-096 | процесс/история | — | Отчёт итерации подготовки: Materials для 52 единиц, I-01 deferred, I-02 — 3 варианта Billium (B-A/B-B/B-C); результаты в SRC-061 и паспортах |
| SRC-097 | процесс/история | — | Синтез подготовки по контурам W-01…W-10 из bounded-ответов Perplexity (один execution owner, reservation ledger); рекомендации не primary-verified — вход P1.3/P1.4 |
| SRC-098 | без новых требований | — | Каталог границ: функция Billium — обязательства, invoice/credit note/allocation, rating, collection (= REQ-OWN-01…06); seller FlexStore отделён от продавца SaaS; Diabox подключает только нужные модули |
| SRC-099 | использован | REQ-FAIL-26, REQ-FAIL-27, REQ-ACC-19 | Boundary scenarios S-01/S-02/S-04/S-05/S-14 — аналитические кейсы, не выполненные тесты |
| SRC-100 | без новых требований | — | Реестр компонентов: `edufy/billing` не второй Billium по имени (= REQ-PROF-15); `corex/billing`/`corex/payments` — только integration targets; support floor ядра Billium не выбран — вход P1.6; webhooks CoreX — транспорт без денежной authority |
| SRC-101 | без новых требований | — | Сводный функциональный план: строка state→writer «SaaS price/obligation/allocation/legal invoice — Billium или явно делегированный engine», provider spend, GL и retail quote отдельно (= REQ-OWN-01…07, REQ-OWN-17) |
| SRC-102 | без новых требований | — | Карта проектов: Billium — старый полный дизайн без подтверждённой реализации; vendor billing AiResto отдельно от гостевых денег (= REQ-OUT-12); Diabox — модули Billium опциональны; реквизиты и цены не угадываются (= REQ-OUT-15) |
| SRC-103 | дубль | SRC-090 | Materials-AiFina: разделы «Delegation» и W-01 совпадают с Boundary-AiFina (SRC-090) |
| SRC-104 | использован | REQ-CON-34, REQ-CON-35 | Boundary-FinBridge: `finbridge.fact/1`, mapping version — metadata; capture/refund и allocation Billium — не два cash effects |
| SRC-105 | дубль | SRC-104 | Materials-FinBridge: разделы W-01 и «Payment feed import requirements» совпадают с Boundary-FinBridge (SRC-104) |
| SRC-106 | без новых требований | — | Materials-Memster: legacy integer subscription ID, UUID subject/morph, таблицы `billing_*`, сырые счётчики и история наград сохраняются; annual + monthly — сценарий, не доказанное предложение (= REQ-PROF-14, REQ-NBR-11) |
| SRC-107 | без новых требований | — | Boundary-UsageMeter: измеряет domain owner, резервирует Tariffler, деньги рейтит Billium; gauge не суммируется; общая таблица денег/usage и charge внутри consume отвергнуты (= REQ-CON-16, REQ-OUT-10) |
| SRC-108 | дубль | SRC-107 | Materials-UsageMeter: разделы «Delegation» и W-03 совпадают с Boundary-UsageMeter (SRC-107) |
| SRC-109 | использован | REQ-CON-36, REQ-OUT-17, CONFLICT-09 | Purchase-and-Activation: поток FlexCRM, две разные продажи FlexCMS + Commerz, независимые FSM invoice/payment/resource («не замена frozen Billium v1») |
| SRC-110 | использован | REQ-NBR-16 | Рекомендации Memster I-03/I-21/I-09/I-18/I-28/I-32: изменяемый plan не money version; Memster — compatibility consumer без нового payment executor |
| SRC-111 | использован | REQ-OWN-20, REQ-CON-34 | Payment functional recommendations: I-17 исходный account неизменяем, ротация credentials в том же account; I-26 allocation не повторный cash receipt |
| SRC-112 | процесс/история | — | Программа границ: исторический порядок проходов W-01…W-10 (завершён R9); I-01 не blocker, вопросы не превращать в опрос владельца |
| SRC-113 | процесс/история | — | Design workflow: последовательность исследований (Billium + Commerz первыми), кандидаты пилота (Memster, Commerz, Edufy, ERP SDK); правило «старые планы и схемы сохраняют версию» — к CONFLICT-09 |
| SRC-114 | процесс/история | — | Source review R5 (FD-S01…S07): налоговый resolver и fiscal SDK, ATOL query всегда unknown, цепочка Vintera; для Billium — repo пуст, renderer неизвестен; evidence для P1.4 (DR-02) |
| SRC-115 | процесс/история | — | Структурная проверка итерации W-02/03: owners согласованы на обеих сторонах, PE-01…09 и P-03 cases not-run; persistence-проверки |
| SRC-116 | процесс/история | — | Промпт исследования финансовых границ PHP/Dart (8 вопросов: общие primitives, subledger против GL, экспорт фактов Billium в AiFina) — вход P1.4 (DR-04) |
| SRC-117 | процесс/история | — | Финальный review репозитория Brain: Package-Boundaries исправлен — Billium опционален в Light/Embedded retail, native driver Commerz не зависит от ядра Billium (= REQ-PROF-07) |
| SRC-118 | процесс/история | — | Отчёт итерации финансовых границ W-01: subledger Billium первым, GL need unproved, B-B рекомендован, license не выбрана; нормы — в SRC-001/019/020/029 |
| SRC-119 | без новых требований | — | Словарь контекста: Billium (ранее BillKit, ключ `billkit`), CommercialAgreement/CommercialRevision, Authority против Projection, SourceBook, additive/gauge/distinct — термины согласуются с REQ-CON-16, REQ-CON-28 |
| SRC-120 | без новых требований | — | Boundary-AiResto: гостевой продавец ресторана и продавец ПО Billium — разные refs и книги; refund ресторана — факт продукта, не Ledger.reverse (= REQ-OUT-12) |
| SRC-121 | дубль | SRC-120 | Materials-AiResto: раздел W-01 совпадает с Boundary-AiResto (SRC-120) |
| SRC-122 | без новых требований | — | Boundary-AxiomaErpSdk: Billium только в списке пересечений (Money, FinBridge); норм для Billium нет, Money VO — REQ-NBR-12 |
| SRC-123 | без новых требований | — | Boundary-ControLLM: provenance и нормализация usage отдельно от quota Tariffler и customer charge Billium (= REQ-NBR-08, REQ-OUT-10) |
| SRC-124 | дубль | SRC-123 | Materials-ControLLM: раздел R1/R2 совпадает с Boundary-ControLLM (SRC-123) |
| SRC-125 | без новых требований | — | Boundary-Diabox: магазин с исполнением Commerz, подключать только требуемые модули Billium (I-04/I-18) — согласуется с REQ-PROF-03, REQ-PROF-19 |
| SRC-126 | дубль | SRC-125 | Materials-Diabox: разделы «Участники» и «Следующий проход» повторяют Boundary-Diabox (SRC-125) |
| SRC-127 | дубль | SRC-106 | Boundary-Memster: раздел Memster consumer pass совпадает с Materials-Memster (SRC-106) |
| SRC-128 | без новых требований | — | Индекс карточек проектов: Billium — Draft, design-no-implementation-confirmed; фаза 3 R5…R8 завершена, паспорта уровня A |
| SRC-129 | использован | REQ-CON-37 | Runtime-Map: Billium — PHP core + Dart HTTP client; service API + thin SDK не обещает offline write; dart2js финансовая арифметика запрещена |
| SRC-130 | без новых требований | — | Tower AiFina: Billium — подписки и лицензии AiFina как продукта через PHP-облако, не книга клиента; факты Billium попадают в AiFina только через FinBridge (= REQ-PROF-12, REQ-NBR-09) |
| SRC-131 | без новых требований | — | Рекомендации Edufy: credit ledger Edufy ≠ денежный collection Billium, замена Billing из-за имени отклонена (= REQ-PROF-15); измеренные токены отделены от quoted charge (= REQ-OUT-10) |
| SRC-132 | процесс/история | — | Вход агента Infrastructure: охват 52 единиц, оригинальный отчёт Billium не повторять, `billkit` — стабильный ключ истории |
| SRC-133 | процесс/история | — | История итераций: ссылки на пилот Commerz↔Billium (SRC-005) и документы W-01; статусы, без норм |
| SRC-134 | процесс/история | — | Первичная проверка 1C: inference «settlement/allocation balance — subledger, не план счетов компании» подкрепляет REQ-OUT-04; evidence для P1.4 |
| SRC-135 | процесс/история | — | Evidence Memster: проверен consumer foundation subscriptionify 1.0.0; runtime Billium/Tariffler и MG-сценарии not-run |
| SRC-136 | процесс/история | — | Журнал запросов Perplexity (BillKit-эпоха): P-03 (учёт против billing subledger), P-08 (AI runtimes, BillKit rating) — тексты запросов, не требования |
| SRC-137 | процесс/история | — | Индекс Evidence: Billium-Audit-Evidence (SRC-032) и др. — навигация |
| SRC-138 | процесс/история | — | Промпт исследования Tariffler: гипотеза «Tariffler — access lifecycle и PlanVersion, денежная цена/schedule у Billium» (= REQ-OWN-01/02) для проверки |
| SRC-139 | процесс/история | — | Индекс промптов: Billium-Research ready-for-research, DR-01…04 ready-not-run — вход P1.4 |
| SRC-140 | использован | REQ-NBR-16 | Итерация Memster: миграция не восстанавливает per-effect facts и оплаченные условия из Active/current plan |
| SRC-141 | процесс/история | — | Ответ Perplexity PR-12 (Billium-Research): no-answer, 0 символов body — gap retrieval, не источник |
| SRC-142 | процесс/история | — | Bounded-ответ Perplexity RR-11: книги/writers/экспорт (Billium — invoices, receivables, allocations, subledger; envelope экспорта) — рекомендация без primary-проверки, вход P1.3/P1.4 |
| SRC-143 | использован | REQ-OUT-18 | AI quality passport: customer price/invoice — Billium; eval workload не выставляется по умолчанию; float provider cost — telemetry, не денежная запись |
| SRC-144 | использован | REQ-CON-38 | Boundary prerequisites: FD-G04 (номер/статус, renderer/шаблоны/шрифты/версия/digest, renderer locator отсутствует); SB-G04 «15–20 %» — направление, не frozen (I-28) |
| SRC-145 | без новых требований | — | Паспорт клиентов и хабов: магазин Diabox — дополнительные модули Billium опциональны, subscription/server billing не утверждены; бренд AxiomaSoft ≠ продавец (= REQ-PROF-08, REQ-PROF-19) |
| SRC-146 | использован | REQ-OUT-18 | AI execution boundaries: Billium — customer price/rating/charges; provider cost и customer price — разные величины |
| SRC-147 | процесс/история | — | Рабочая копия Mermaid-схемы payment lifecycle из отчёта Perplexity — иллюстрация proposal, не факт реализации |
| SRC-148 | процесс/история | — | Индекс домена Finance: DR-04 ready-not-run, AiFina-Billium-Financial-Boundaries (SRC-019) — навигация |
| SRC-149 | без новых требований | — | Review Tenant universal core: прямой зависимости Tenant↔Billium нет, связь через mapping/coordinator хоста; в FlexCRM вызовов Billing/Billium не найдено (= REQ-PROF-09) |
| SRC-150 | без новых требований | — | Materials-AxiomaErpSdk: Billium упомянут только в списке пересечений/partners; связь — область интеграции, не зависимость; норм для Billium нет |
| SRC-151 | без новых требований | — | Boundary-AxiomaSoft: Billium упомянут только в списке пересечений/partners; связь — область интеграции, не зависимость; норм для Billium нет |
| SRC-152 | дубль | SRC-151 | Materials-AxiomaSoft: строка «Заявленные partners» с Billium повторяет список пересечений Boundary-AxiomaSoft (SRC-151) |
| SRC-153 | без новых требований | — | Boundary-CallKit: Billium упомянут только в списке пересечений/partners; связь — область интеграции, не зависимость; норм для Billium нет |
| SRC-154 | дубль | SRC-153 | Materials-CallKit: строка «Заявленные partners» с Billium повторяет список пересечений Boundary-CallKit (SRC-153) |
| SRC-155 | без новых требований | — | Boundary-ChatomIo: Billium упомянут только в списке пересечений/partners; связь — область интеграции, не зависимость; норм для Billium нет |
| SRC-156 | дубль | SRC-155 | Materials-ChatomIo: строка «Заявленные partners» с Billium повторяет список пересечений Boundary-ChatomIo (SRC-155) |
| SRC-157 | без новых требований | — | Boundary-CreditAuthority: Billium упомянут только в списке пересечений/partners; связь — область интеграции, не зависимость; норм для Billium нет |
| SRC-158 | дубль | SRC-157 | Materials-CreditAuthority: строка «Заявленные partners» с Billium повторяет список пересечений Boundary-CreditAuthority (SRC-157) |
| SRC-159 | без новых требований | — | Boundary-Flex: Billium упомянут только в списке пересечений/partners; связь — область интеграции, не зависимость; норм для Billium нет |
| SRC-160 | дубль | SRC-159 | Materials-Flex: строка «Заявленные partners» с Billium повторяет список пересечений Boundary-Flex (SRC-159) |
| SRC-161 | без новых требований | — | Boundary-FlexCRM: Billium упомянут только в списке пересечений/partners; связь — область интеграции, не зависимость; норм для Billium нет |
| SRC-162 | дубль | SRC-161 | Materials-FlexCRM: строка «Заявленные partners» с Billium повторяет список пересечений Boundary-FlexCRM (SRC-161) |
| SRC-163 | без новых требований | — | Boundary-FlexStore: Billium упомянут только в списке пересечений/partners; связь — область интеграции, не зависимость; норм для Billium нет |
| SRC-164 | дубль | SRC-163 | Materials-FlexStore: строка «Заявленные partners» с Billium повторяет список пересечений Boundary-FlexStore (SRC-163) |
| SRC-165 | использован | REQ-ACC-20, REQ-NBR-16, REQ-FAIL-28 | Memster prerequisites прочитаны полностью: interfaces, compatibility, импорт и смена writer, MG-01…12 |
| SRC-166 | без новых требований | — | Boundary-RegHub: Billium упомянут только в списке пересечений; связь — область интеграции, не зависимость; норм для Billium нет |
| SRC-167 | дубль | SRC-166 | Materials-RegHub: строка «Заявленные partners» с Billium повторяет список пересечений Boundary-RegHub (SRC-166) |
| SRC-168 | без новых требований | — | Boundary-Settlement: Billium упомянут только в списке пересечений; связь — область интеграции, не зависимость; норм для Billium нет |
| SRC-169 | дубль | SRC-168 | Materials-Settlement: строка «Заявленные partners» с Billium повторяет список пересечений Boundary-Settlement (SRC-168) |
| SRC-170 | без новых требований | — | Boundary-StateMachine: Billium упомянут только в списке пересечений; связь — область интеграции, не зависимость; норм для Billium нет |
| SRC-171 | дубль | SRC-170 | Materials-StateMachine: строка «Заявленные partners» с Billium повторяет список пересечений Boundary-StateMachine (SRC-170) |
| SRC-172 | без новых требований | — | Boundary-Vintera: Billium упомянут только в списке пересечений; связь — область интеграции, не зависимость; норм для Billium нет |
| SRC-173 | дубль | SRC-172 | Materials-Vintera: строка «Заявленные partners» с Billium повторяет список пересечений Boundary-Vintera (SRC-172) |
| SRC-174 | без новых требований | — | Корневой индекс Infrastructure: домен Billing «Billium: границы, требования, задание Perplexity», Finance «AiFina, ERP SDK, Billium» — навигация к документам корпуса |
| SRC-175 | без новых требований | — | Архитектура AiResto: биллинг тарифа Network — management plane хранит привязку сети к подписке, тарифы/квоты у Tariffler, деньги у Billium (CD-07) (= REQ-PROF-13) |
| SRC-176 | без новых требований | — | Management plane AiResto: `rst_` хранит только ссылки subscription/grant, цены/инвойсы/оплата — Billium, паттерн `corex/billing` superseded (= REQ-PROF-13) |
| SRC-177 | без новых требований | — | Tower ChatomIo: Billium — биллинг чат-продукта, потенциальный потребитель (вердикт P3.1) (= REQ-PROF-17) |
| SRC-178 | процесс/история | CONFLICT-11 | Tower FlexCRM (вердикт P3.1): Billium — биллинг SaaS-подписок (= REQ-PROF-11), но тарифный биллинг аккаунта «остаётся в corex/billing» — устарело относительно канона |
| SRC-179 | процесс/история | CONFLICT-11 | Tower FlexStore (вердикт P3.1): Billium — «разовые и подписочные платежи e-commerce», фундамент `corex/billing`; розница по канону — Commerz |
| SRC-180 | процесс/история | CONFLICT-12 | Tower Memster (вердикт P3.1): Billium — «монетизация авторов»; payouts правообладателям вне Billium (REQ-OUT-09, I-15) |
| SRC-181 | без новых требований | CONFLICT-11 | Tower CoreX: legacy-реестр, `corex/billing` вынесен в standalone Billium — основание CONFLICT-11 |
| SRC-182 | без новых требований | — | Reference CoreX: по D12 биллинг — standalone-репозиторий Billium, не `corex/*`; CoreX — только Flex building blocks |
| SRC-183 | без новых требований | — | Индекс канона: Money-Domain-Canon (платежи, биллинг, Billium), Bridge-Catalog, Engineering-Standards, Package-Status — все документы канона в уровне A |
| SRC-184 | без новых требований | CONFLICT-06 | Расхождения канона: строка Billium/Tariffler — «concept + design-complete + not-started», repo кода нет; дизайн есть, реализация не подтверждена (к CONFLICT-06) |
| SRC-185 | без новых требований | — | Рекомендации Loqum: Billium и Tariffler — условные владельцы rating и grants, Loqum им напрямую не пишет (= REQ-NBR-08) |
| SRC-186 | без новых требований | — | Рекомендации Tenant: прямая зависимость Tenant↔Billium не обоснована, связь через mapping/coordinator хоста; платёж сам не вызывает DROP ресурсов (= REQ-PROF-09, REQ-OUT-11) |
| SRC-187 | процесс/история | — | Индекс Decisions: Finance-Distribution-Recommendation (SRC-001) и Detailing-Frontier (SRC-067) — навигация |
| SRC-188 | процесс/история | — | Гид review репозитория Brain: R1…R9 завершены, разделы «next frontier» — исторические; рабочая копия схемы платежа (SRC-147) |
| SRC-189 | процесс/история | — | Индекс Guides: Structure-Migration (SRC-047) — Billium compatibility; навигация |
| SRC-190 | процесс/история | — | Boundary evidence 2026-10-12: выводы перенесены в карточки, факты vendor/PSP — в Billium-Audit-Evidence (SRC-032) |
| SRC-191 | процесс/история | — | Проверка итерации Edufy: Billium money и Tariffler rights — условные; credit ledger Billing Edufy сохраняется (см. REQ-PROF-15) |
| SRC-192 | без новых требований | — | Проверка инфраструктуры: цепочка usage → rating Billium → settlement Tariffler не обязательна; денежный rating подключается только там, где нужен charge (= REQ-OUT-10, REQ-CON-15) |
| SRC-193 | процесс/история | — | Проверка итерации Memster: legacy-поверхности отделены от предлагаемых Billium money / Tariffler rights; 12 read-only cases, MG not-run |
| SRC-194 | процесс/история | — | Проверка итерации payment functional: hashes, индексы, preservation; оригинал отчёта Billium в неизменённом наборе |
| SRC-195 | процесс/история | — | Source review R7: маршрут чтения участников (в т. ч. Billium) для rewards/payable/bundle; нормы — в SRC-030 |
| SRC-196 | без новых требований | — | Source review Tenant: прямой caller/manifest-зависимости на Billium/Tariffler не найдено; legacy `disable_reason` и features/limits — не коммерческая authority (= REQ-PROF-09) |
| SRC-197 | процесс/история | — | Промпт AI-Boundaries: Billium — customer rating в составе AI-композиции; вход исследования, не требование |
| SRC-198 | процесс/история | — | Отчёт итерации Edufy: Billium/Tariffler — условные money/rights, Edufy Billing — credit authority; нормы — в SRC-038 |
| SRC-199 | процесс/история | — | Отчёт итерации Loqum P-06: Edufy Billing остаётся credit authority, предложения Billium/Tariffler не приняты |
| SRC-200 | процесс/история | — | Отчёт итерации payment functional: Billium получил receive/allocation/query/bootstrap и role-qualified limits — результаты в SRC-023/037/044 |
| SRC-201 | процесс/история | — | Bounded-ответ Perplexity PR-11: Billium, Commerz, AiResto — источники фактов, AiFina не повторяет charge; рекомендация, вход P1.4 |
| SRC-202 | без новых требований | — | Паспорт публикации финансового журнала W-01: Billium и FinBridge используют другие seams и не владеют публикацией журнала книги (= REQ-NBR-09) |
| SRC-203 | использован | REQ-OUT-19 | Module loader: enable получает право от host gate; Tariffler/Billium не регистрируют providers и не запускают миграции |
| SRC-204 | процесс/история | — | Индекс домена Tariffs: DR-03 ready-not-run, gaps тома 1 в review (SRC-017) — навигация |
| SRC-205 | использован | REQ-OUT-19 | Tenant lifecycle passport: host/Billium не создаёт вторую БД; платёжное состояние не выбирает DROP |
| SRC-206 | без новых требований | — | Выделение Tenant: на целевой схеме финансовые факты Billium идут продуктовому координатору, не в Tenant runtime (= REQ-PROF-09, REQ-OUT-13) |
| SRC-207 | без новых требований | — | Дизайн Tenant universal core: в FlexCRM нет callers Billium/Tariffler; `disable_reason='billing'` — не интеграция (= REQ-PROF-09) |
| SRC-208 | использован | REQ-ACC-21, REQ-CON-38 | Fiscal/documents prerequisites прочитаны полностью: FD-G01…G07 и FD-01…10 |
| SRC-209 | без новых требований | — | Implementation blueprint: единица реализации — законченный переход; одна retail-оплата не требует всего Billium/Tariffler (= REQ-PROF-03) |
| SRC-210 | без новых требований | — | Паспорт issuer/distribution: Billium commercial mapping — опционален, loader не зависит обязательно от billing-сервера (= REQ-PROF-03) |
| SRC-211 | без новых требований | CONFLICT-13 | Policy границ пакетов: adapter хранит только mapping/cursor/inbox/outbox/recovery (= REQ-NBR-01); имя `commerz-billium-adapter` расходится с мостом канона `commerz-billium` |
| SRC-212 | дубль | SRC-123 | Индекс ControLLM: раздел R1/R2 совпадает с Boundary-ControLLM (SRC-123) |
| SRC-213 | без новых требований | — | Boundary-CoreX W-01: Billium владеет vendor obligations, Commerz — исполнением Light; generic Tenant — не SellerBook и не финансовая authority (= REQ-PROF-07, REQ-PROF-09) |
| SRC-214 | дубль | SRC-213 | Materials-CoreX: раздел W-01 совпадает с Boundary-CoreX (SRC-213) |
| SRC-215 | использован | REQ-ACC-22, REQ-NBR-16 | Edufy prerequisites прочитаны полностью: EC-P1…P9, compatibility, EC-01…12; Billium — условная интеграция при конкретном offer/funding |
| SRC-216 | без новых требований | — | Boundary-FlexCMS R8: storefront владеет editorial state, розница — Commerz, Billium опционален (= REQ-PROF-03) |
| SRC-217 | дубль | SRC-216 | Materials-FlexCMS: раздел R8 совпадает с Boundary-FlexCMS (SRC-216) |
| SRC-218 | без новых требований | — | Boundary-Licensing: Billium упомянут только ссылкой на AiFina-Billium-Financial-Boundaries (SRC-019) в «Основаниях»; lease выдаёт хост (= REQ-NBR-10) |
| SRC-219 | использован | REQ-OUT-18 | Memster generation frontier: Billium рейтит только принятый billable fact по исходной PriceVersion; «генерации в плане» — не плата за каждую provider-попытку |
| SRC-220 | без новых требований | — | Паспорт reference catalog: subscription PriceVersion — Billium commercial owner, розничные цены/налоги — Commerz; наблюдаемая рыночная цена не quote/invoice (= REQ-OWN-01, REQ-OUT-03) |
| SRC-221 | использован | REQ-ACC-23 | Settlements/bundles prerequisites прочитаны полностью: SB-G01…G06 и SB-01…10 |
| SRC-222 | без новых требований | — | Индекс Workspace: пилот Commerz↔Billium (SRC-005) и матрица диспозиций пакетов (SRC-089) — навигация |

## Итог прохода

- Охват: 222 из 222 SRC имеют диспозицию. Уровень A (P1.1): 33 «использован», 1 «дубль», 2 «без новых
  требований», 9 «процесс/история». Уровень B: 16 / 11 / 27 / 30. Уровень C: 11 / 14 / 39 / 29.
  «Вне границ» не понадобилось: чужие владельцы записаны строками REQ-OUT, а не отдельной диспозицией.
- Новые ID в `docs/requirements.md` (29, свободные номера, старые строки не менялись): REQ-OWN-19/20,
  REQ-PROF-20…22, REQ-CON-33…38, REQ-FAIL-26…28, REQ-ACC-19…23, REQ-NBR-16, REQ-OUT-16…19,
  CONFLICT-09…13. Новых INV нет: найденные в B/C инварианты повторяют INV-01…12.
- Главные добавки: сценарии приёмки стороны Billium из MG, FD, EC и SB (REQ-ACC-20…23) и аналитические
  S-01/02/04/05/14 (REQ-ACC-19); запрет выводить деньги из legacy-планов при миграции и порядок смены
  writer (REQ-NBR-16, REQ-FAIL-28); экспорт в учёт без двойной выручки (REQ-CON-34/35); границы Billium
  с ресурсами хоста, налогом, себестоимостью провайдера и деньгами магазина (REQ-OUT-16…19).
- Рекомендации источников, а не решения: первый профиль — библиотека obligations/allocation
  (REQ-PROF-20), gate для runtime execution (REQ-PROF-21), FSM invoice из Purchase-and-Activation
  (REQ-CON-36). Их подтверждает или заменяет D# в P1.6.
- Конфликты для P1.6–P1.8: CONFLICT-09 (старые «frozen» схемы BillKit v1 из `Development/`) — вопрос
  владельцу, опубликованы ли они потребителям; CONFLICT-10 (AGPL Lago по REST) — проверка в P1.4;
  CONFLICT-11/12 (устаревшие строки towers FlexCRM, FlexStore, Memster) и CONFLICT-13 (имя адаптера) —
  в brain-updates P1.8.
- Дубли: Materials-* повторяют Boundary-* того же юнита; 23 карточки уровня C (SRC-150…164, SRC-166…173)
  упоминают Billium только в списке partners. Отчёты Perplexity (DM-01, PR-11/12, RR-11) и промпты — «процесс/история», вход P1.3/P1.4.
