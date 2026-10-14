# 2026.10.14-№1-BILLIUM-V1 — Plan №1 — Billium: целевая архитектура и реализация биллинга

## 0. Meta

| Field | Meaning |
|:--|:--|
| Plan ID | 2026.10.14-№1-BILLIUM-V1 |
| Short ID | PLAN1 |
| Title | Plan №1 — Billium: целевая архитектура и реализация биллинга |
| Layout | v2 |
| Document Type | Executable Master Plan |
| Authoring Model | frontier/high |
| Repository | billium |
| Related Packages | `axiomasoft/billium-*` (PHP) · Dart-клиент контрактов · PaymentDrivers contracts · `commerz-billium` (живёт в Commerz) |
| Execution Mode | phase-first |
| Target Operator Classes | frontier (design/research/review) · implementation (exec) |
| Approval Owner | Dmitry Vostrikov |
| Home | repo:billium |
| Design Passes | 2 — D8: полное покрытие постановки, 82 сценария, исполнимость и регрессии приёмки; 1 — верхний уровень P1–P10 записан; детализирована только P1 (9 items); P2–P10 — скелеты, провизорные до P1.8 (D1); полнота источников и происхождение — D6, D7 |

## 1. Context

Billium — биллинг продавца ПО экосистемы Axioma. Он владеет ценами (PriceVersion, rating), денежными
occurrences, obligations, invoices и credit notes, allocation платежей, collection/dunning,
юридической нумерацией документов и исполнением платежей для своих операций: журнал попыток,
unknown-recovery, callbacks, reconcile. Репозиторий пока пустой скелет: код, стек и раскладка пакетов
не выбраны.

План записывает весь путь до 1.0 (P1–P10), но детально проработана только P1. P1 —
подготовительная фаза, она отвечает на два вопроса. Первый: какая схема Billium целевая. Ответ ищется
через разбор аудита Perplexity, сверку с требованиями канона и проверку по первичным источникам. Итог —
решение build/adopt/hybrid по каждой области, модель данных, раскладка, контракты v1 и каталог
приёмки. Второй: как разрабатывать. Источник — практики AgenTools, Chatom и AzGuard, инварианты
Commerz и idempotency Vaulter. Production-кода в P1 нет. Фазы P2–P10 детализируются отдельными
design-сессиями после решений P1.

Исходная постановка — `brief/00-owner-message.md`, уточнения — `brief/01-refinements.md`. Внешние
источники перечислены в `research/P1-sources.md`. Полный корпус Brain о Billium (222 документа) —
в `research/P1-corpus.md`. Инвентарь аудита Perplexity и заданий DR — в
`research/P1-audit-inventory.md`.

## 2. Execution Rules

- **Границы нормативны** (`brief/00-owner-message.md` §2). Billium не владеет правами, квотами и
  планами-как-правом (Tariffler), agreement-сагой (хост), розничным заказом (Commerz), книгой компании
  и двойной записью (AiFina, I-20: GL внутри не делаем), кредитами и наградами продуктов,
  PSP/касс-интеграциями (PaymentDrivers; Billium зависит только от их contracts), лицензиями
  (Licensing). Конфликт с каноном оформляется как D# плюс предложение CD-записи. Тихо в плане границы
  не меняются.
- **Канон:** CD-02 (`billium-contracts` лёгкий; мост `commerz-billium` пишется в репозитории Commerz,
  не здесь), CD-03/04, CD-05 (MoneyWire: decimal string + ISO 4217, без float), CD-08, CD-14
  (профили `embedded` и `server` на одном коде; обязательны только contracts + core), CD-15 (Billium и
  Tariffler друг друга не импортируют).
- **Dart** — клиент контрактов и SDK. Вторым денежным writer он не становится.
- **Инварианты денег обязательны:** один writer на сущность; один исполнитель на денежную операцию на
  всю её жизнь; сетевой вызов вне транзакции; `unknown` разрешается lookup-ом, а не новым ключом;
  idempotency key + digest, тот же ключ с другим payload = conflict; cumulative totals не складываются
  как дельты; refund идёт к исполнителю исходного charge; транспортный ACK — не бизнес-receipt.
- **Доработка дизайна (D8):** `research/P1-design.md` — полная матрица постановки и модель инвариантов; `research/P1-acceptance-inventory.md` — 82 исходных сценария.
- **Правило происхождения (D7) — без отсебятины.**
  - Каждое нормативное утверждение в артефактах P1 и при детализации P2+ ссылается на ID с владельцем:
    REQ/INV, SRC, единица аудита (AUD/C/V3/AC/G/R/FS/E/L/Q), CL, DR0N, M, D#.
  - Вывод исполнителя оформляется как D# с альтернативами или как `hypothesis` с основаниями.
  - Scope фаз P2+ берётся только из `knowledge/roadmap-proposal.md`, D#, REQ/INV и каталога приёмки.
    Новый scope вводится только новой D-записью.
- **Полнота источников (D6).** Каждый из 222 документов корпуса (`research/P1-corpus.md`), 244 ID
  аудита и 38 пунктов DR (`research/P1-audit-inventory.md`) получает диспозицию. Пропуск — RED.
- Архитектурные решения агент принимает сам: по коду и первичным источникам, с D#, минимум двумя
  альтернативами и причиной. Владелец может отменить любое решение. Бизнес-факты (продавцы, цены,
  налоговые режимы, PSP) не выдумываются, а уходят в вопросы владельцу.
- Внешние факты (версии, лицензии, API провайдеров) подтверждаются первичными источниками с URL, датой
  и версией. Без проверки ставится `[UNVERIFIED]`. Perplexity служит для поиска и синтеза, но не
  является доказательством.
- `BRAIN/Development/` не читать и не использовать.
- Стек по умолчанию — как в экосистеме: PHP 8.4, Laravel 13, PostgreSQL ≥ 16, PHPStan max, Pint,
  Rector. Отклонение допускается только с D# и причиной.
- Тесты запускаются только для затронутого. Мутационные и нагрузочные — отдельными пунктами.
  Проваленная или пропущенная проверка не считается зелёной.
- Коммиты — локальные, Conventional Commits с ID: `docs(plan): … [PLAN1.P1.4]`. Push, публикация и
  релиз-тег — только по запросу владельца. Чужие изменения в worktree (в том числе dirty-файлы в
  Brain) не трогать: в commit попадают только свои hunks.
- После item: journal → state/status/decision/bundle views → validation → handoff.

## 3. Routing

| Batch | Items | Model class/effort | Exec | Review | Why |
|:--|:--|:--|:--|:--|:--|
| solo | P1.1 | frontier/high | run | none | recommended: уровень A корпуса ≈ 850 КБ читается разделами; весь бюджет контекста — под требования и леджер A |
| solo | P1.2 | frontier/high | run | none | recommended: сплошной проход 177 документов B/C (≈ 1,8 МБ) — отдельный бюджет контекста; зависит от REQ-ID P1.1 |
| solo | P1.3 | frontier/high | run | none | recommended: пакет аудита ≈ 183 КБ читается полностью и покрывается по 244 ID; не совмещается с корпусом |
| solo | P1.4 | frontier/high | run | none | recommended: нужна web/primary-source research capability и отдельный бюджет под 38 пунктов DR |
| solo | P1.5 | frontier/high | run | none | recommended: read-set — только соседние репозитории; от P1.1–P1.4 не зависит и может идти параллельно с ними |
| B-P1-DESIGN | P1.6 | frontier/high | run | none | recommended: целевая схема — прямой вход контрактов P1.7; D# и модель данных читаются один раз |
| B-P1-DESIGN | P1.7 | frontier/high | run | none | recommended: контракты и каталог приёмки ссылаются на сущности и FSM из P1.6 |
| B-P1-DESIGN | P1.8 | frontier/high | run | none | recommended: roadmap P2–P10 и вопросы владельцу сводят P1.6–P1.7; выполняемая подгруппа допустима при нехватке контекста |
| solo | P1.9 | frontier/high | run | none | required: независимый read-only verdict по всей P1 в свежей сессии, не авторской; совмещать с исполнением нельзя |

P2–P10 — скелеты (`> SKELETON`), их Routing появится при детализации фазы.

## 5. Decision Log

Каталог решений: `decisions/`. Собрать и проверить:
`python3 <task>/scripts/plan-views.py decisions --plan-dir plans/2026.10.14-№1-BILLIUM-V1 [--check]`.
