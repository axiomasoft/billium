# Леджер корпуса Brain — диспозиция SRC

Инвентарь — `research/P1-corpus.md` (snapshot design-этапа, D6; executor его не меняет). Требования —
`docs/requirements.md`. P1.1 заполняет уровень A (45 SRC); уровни B и C добавляет P1.2.

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
