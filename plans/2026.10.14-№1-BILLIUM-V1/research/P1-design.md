# P1 — досье исполнимого дизайна и модель инвариантов

Основание: постановка `brief/00-owner-message.md`, уточнения `brief/01-refinements.md` и
`brief/02-p1-design-refinements.md`, D1–D8. Это контракт работы, не результат исследований P1.

## Полное покрытие постановки

| Требование владельца | Owning item | Проверяемый выход |
|:--|:--|:--|
| §1 полный верхний уровень, детализация только P1 | design, P1.8 | P1–P10 в roadmap; P2+ SKELETON; proposal по результатам |
| §1 аудит целиком, выбор build/adopt/hybrid | P1.3, P1.4, P1.6 | 244 диспозиции, первичная проверка, 7 решений областей |
| §1 практики AgenTools, Chatom, AzGuard | P1.5 | 22 topic, все указанные источники, accept/adapt/reject |
| §2 PriceVersion/rating, occurrences, obligations, invoice/credit note, allocation | P1.1, P1.6 | REQ → writer → сущность/FSM → constraint/lock |
| §2 collection/dunning, numbering, attempts, unknown, callbacks, reconcile | P1.1, P1.6, P1.7 | требования, модель, contracts, приёмка |
| §2 все 7 исключённых владельцев | P1.1, P1.6, P1.9 | REQ-OUT/REQ-NBR; отсутствие чужого writer и GL |
| §2 CD-02/03/04/05/08/14/15 | P1.1, P1.6, P1.7 | лёгкий contracts; Commerz bridge снаружи; fiscal by executor; MoneyWire; миграции; один код; независимость Tariffler |
| §2 Dart — только contracts/SDK | P1.5, P1.6, P1.7, P1.8 | conformance и клиентская роль в архитектуре/README |
| §2 все потребители | P1.1, P1.6, P1.8 | матрица потребитель → профиль/контракт → вопрос/фаза |
| §2 все 8 обязательных инвариантов | P1.1, P1.6, P1.7, P1.9 | INV-01…08, enforcement/bypass, сценарии |
| §3.1 требования и соседние обязательства | P1.1, P1.2 | 222 SRC с диспозицией, неизменяемые REQ/INV-ID |
| §3.2 все схемы, ownership-поправки, G-01…06 | P1.3 | матрица 16×8, CL, AC/G/FS mapping |
| §3.3 DR-01/02/04 и граница DR-03 | P1.4 | 38 пунктов, CL/C → primary facts/open, URL/дата/версия |
| §3.4 все перечисленные практики | P1.5 | methods.json, источники и применение в P2–P10 |
| §3.5 модель, решения и ≥2 альтернатив | P1.6 | ERD + типы/ключи, обе FSM, коррекции, restore, D# |
| §3.6 стек, packages, embedded/server, extraction gate | P1.6 | версии с проверкой, зависимость пакетов, потребитель/инвариант |
| §3.7 все 5 семейств контрактов + ошибки/fixtures | P1.7 | wire-поля, семантика/retries, совместимость пилота, PHP/Dart oracle |
| §3.8 PB/PF/MG/FD/SB/EC, concurrency, restore | P1.7 | все 82 исходных ID, ACC-CON/ACC-RST, покрытие каждого REQ/INV |
| §3.9 target-structure, roadmap, вопросы | P1.6, P1.8 | структура и freeze; все цели/выходы/зависимости и вопросы |
| §3 spikes только для решения, production-кода нет | P1.4, P1.6 | D5, isolated evidence; bootstrap только P2 |
| §4 полный путь до release 1.0 | P1.8 | P2–P10 proposal без потери денежных областей/миграций/release gate |
| §5 весь явный read-set | P1.1–P1.5 | P1-sources, corpus и audit inventories, sources_reviewed |
| §6 канон и запрет Development | все | SRC вне Development; D# + proposed CD при конфликте |
| §6 первичные внешние факты | P1.4, P1.6 | verified с URL/датой/версией; [UNVERIFIED] с влиянием |
| §6 дефолт PHP 8.4/Laravel 13/PostgreSQL ≥16 | P1.6 | целевое ограничение из постановки; совместимость подтверждает P1.4/P1.6 |
| §6 затронутые тесты, мутации/нагрузка отдельно | P1.5, P1.8 | принятая практика + отдельные выходы последующих фаз |
| §6 local commits; push/publish/tag по запросу | все | Conventional Commit + PLAN1; AI-служебные файлы вне коммита |
| §6 три обновления Brain | P1.8 | точные patches + собственные hunks + SHA/объяснённый остаток |
| §7 все бизнес-вопросы и BRAIN-REPO | P1.8 | owner-questions, рекомендация и конкретный блокируемый scope |

## Обработка источников и передача результатов

1. Сопоставить SRC snapshot с текущим файлом. При drift записать обе версии/хеши и раздел;
   исходный ID сохранить. Недоступность обязательного источника — конкретный непокрытый ID,
   а не «без новых требований». Не подменять чтение поисковой выдачей или памятью.
2. P1.1 извлекает нормативные REQ/INV из канона и паспортов. Аудит/DR внутри уровня A получает
   диспозицию как исследовательский материал; его гипотезы не превращаются в требования.
3. P1.2 обрабатывает B/C, полностью читает сценарии источников MG/FD/SB/EC из нового snapshot.
   Наследие BillKit/Billiot сопоставляется с текущим каноном; конфликт фиксируется, ID не стирается.
4. P1.3 извлекает CL и заявки на недостающие требования. Внешнее утверждение остаётся гипотезой
   до первичной проверки, даже если Perplexity пометил его FACT. В каждой ячейке матрицы — CL.
5. P1.4 сохраняет disposition каждого задания и каждого направленного CL/C. Открытая позиция
   содержит причину, влияние, следующий способ проверки и область решения, которую блокирует.
   Непроверенная лицензия/edition или single-writer premise не допускает adopt по предположению.
6. P1.6 закрывает каждую заявку новым REQ/INV через D# либо объяснённым отказом/вопросом.
   Ссылка на аудиторский SRC — происхождение заявки; истинность опирается на первичный DR/D#.
7. P1.7 строит двустороннюю трассу: требование → сценарий, сценарий → требование и контракт/модель.
   ID соседнего сценария не означает, что Billium владеет его правами, квотами или accounting.
8. P1.8 переносит все открытые исследовательские риски/CONFLICT/CD и бизнес-вопросы с точным
   влиянием на дальнейшую фазу. Пока неизвестен продавец, нейтральный контракт можно описать;
   реальные налоговые режимы и PSP за владельца не выбирать.

## Модель денежных инвариантов для P1.6–P1.7

Риск: data-integrity, включая concurrency, transaction и seller isolation. Это полный набор
операций, который исполнитель P1.6 обязан конкретизировать по REQ/INV/FS/PB/PF. Таблицы и
механизмы пока не выбраны. Разделы ниже — обязательные выходы, не предположение об их реализации.

| Группа операций | Пересекающиеся ресурсы | Обязательная проверка взаимодействий |
|:--|:--|:--|
| create/replay/changed-body, schedule, rating | semantic intent, price/period/seller, idempotency receipt | UNIQUE admission, original binding, digest conflict, first row race |
| charge/refund/cancel/reconcile | operation, attempt, frozen executor/account, refund capacity | один исполнитель; unknown удерживает ёмкость; refund к исходному charge |
| callback/lookup/late-response/worker | provider evidence, revision, fence/epoch, operation | stale worker не перезаписывает новое; факт не теряется; terminal conflict quarantined |
| allocate/credit-note/adjustment/invoice-close | obligation, invoice lines, allocations, invoice revision | conservation, no double subtraction, immutable corrections, period lineage |
| usage/overage/dunning/recurring | source fact revision, occurrence, price version, collection operation | cumulative ≠ delta; late fact; повтор scheduler не создаёт второй эффект |
| outbox/inbox/feed/bootstrap | own DB connection, committed cursor, projection, receipt | атомарный commit; reorder/duplicate; refund before payment; ACK ≠ applied |
| prune/restore/clone/cutover/import | tombstones, retained history, installation lineage, external admission | старый ключ не новый intent; clone не dispatch; original executor after cutover |
| numbering/render/fiscal/export | seller/legal series, document version, executor receipt, export fact | immutable legal number, отдельные outcomes, no GL ownership |
| seller-scoped command/query/callback | authenticated caller, seller/account namespace, secret binding | cross-seller deny/quarantine до утечки или изменения |

Для каждой операции P1.6 фиксирует read/write set, явные и неявные locks (включая FK/UNIQUE),
порядок захвата, transaction/connection, commit point, внешние вызовы и recovery/query path.
Составить матрицу всех пар с общим изменяемым ресурсом, включая self-pairs. У каждой пары —
допустимое interleaving либо причина отсутствия конфликта с REQ/INV/D#. Новый lock в одном
обработчике проверяется против callback, refund, reconcile, cleanup и restore, а не только себя.

Каждый INV имеет enforcement, bypass-path и сценарий, который должен его опровергнуть при
неверной модели. Self-check записывает рассмотренные пары, counterexamples, исправления,
неразрешённые риски и ссылки в модель; это не доказательство реальной DB concurrency.
Фактические N-process PostgreSQL proofs выполняются в фазах реализации, назначенных P1.7/P1.8.

## Граница приёмки

- Структурный checker проверяет перечисленные формы, enum, ID/покрытие/разрешимость и статус review.
- Семантика (истинность первоисточника, достаточность lock/enforcement, качество альтернатив,
  законность нумерации/портов, соответствие fixture oracle) — self-check и независимый P1.9.
- GREEN design не означает GREEN P1. Все девять item-state остаются Not started.
- P1.9 pre-review запускает P1.1–P1.8; после verdict запускает all. RED не закрывает фазу.
- Рецензент записывает выборку ID/seed и URL, чтобы проверка была воспроизводимой; локально
  найденный дефект возвращается owning item. Корректировка и проверка findings ограничены D3.
