# Сообщение владельца — исходная постановка

<!-- REPOSITORY-OWNER-MESSAGE -->

| Поле | Значение |
|:--|:--|
| Дата | 2026-10-14 |
| Источник | `Brain:05-Projects/03-Packages/Billium/Billium-Plan-Prompt.md` |
| Brain commit | `fd0cc67d` (локальный, без push) |
| sha256 источника | `54c32461fbe0a5b2985373cda3431a06094e9698f34c58174dad8d1ffa99dc47` |
| Вызов | `task:plan-design start` → `new "Billium — целевая архитектура и реализация биллинга"` с `--message-file` на этот файл |

Ниже — дословная копия файла без frontmatter. Источник истины для плана — эта копия.

---


# Billium — постановка SKM-плана PLAN1

Репозиторий: `/home/vostrikov/projects/packages/billium` (пустой скелет, коммитов нет). Brain: `/home/vostrikov/Vaults/Brain` (ниже `BRAIN`). Пути даны абсолютные — читай адресно, нужными разделами.

## 1. Задача

Создай SKM-план (`task:plan-design new`) реализации **Billium** — биллинга продавца ПО экосистемы Axioma. В плане сразу записать **полный верхний уровень** (цель, границы, правила, все фазы с целями и выходами), а детально проработать только **P1 — большую подготовительную фазу**. Остальные фазы детализируются отдельными сессиями после решений P1.

P1 отвечает на два вопроса:

1. **Какая схема Billium идеальна.** Главный вход — аудит Perplexity:
   - отчёт: `/home/vostrikov/Vaults/Brain/Infrastructure/Research/Reports/Billium-Perplexity-Volume-1-2026-10-12.md` (84 КБ, читать целиком);
   - его разбор: `/home/vostrikov/Vaults/Brain/Infrastructure/Research/Reports/Billium-Volume-1-Review-2026-10-12.md` (статусы доказанности, 10 поправок, gaps G-01…G-06);
   - evidence: `/home/vostrikov/Vaults/Brain/Infrastructure/Research/Evidence/Billium-Audit-Evidence-2026-10-12.md`.

   В аудите разобраны разные схемы (Lago, Kill Bill, OpenMeter, PHP embedded path, invoice applications, минимальное payment storage, invoice-модуль, server storage, idempotency, usage, credits, outbox/inbox, конкуренция, расширяемость, эволюция схемы, PHP и Dart). Нужно сравнить их с нашими требованиями, проверить по первичным источникам и выбрать одну целевую схему: build / adopt / hybrid, модель данных, состав пакетов, контракты.
2. **Как разрабатывать.** Изучить и перенять методы **AgenTools** (spec-first, PHP+Dart SDK, общие fixtures и conformance — главный образец, т.к. Billium тоже PHP+Dart), **Chatom** и **AzGuard** (минимальное ядро + модули, заморозка API/SPI/схемы/идентичностей до 1.0, target-structure, type-map, identities, release gate). Итог — явный список практик, которые Billium принимает, с причиной.

Архитектурные решения принимай сам, проверяя по коду и первичным источникам (Perplexity — для поиска и синтеза), записывай D# с альтернативами и причиной. Я могу отменить любое. Бизнес-факты (продавцы, цены, налоговые режимы) не выдумывай — список вопросов ко мне.

## 2. Границы — нормативно, не пересматривать в плане

Источник: канон экосистемы `BRAIN/Infrastructure/Canon/`. Изменить границу можно только предложением CD-записи в `Canon-Decision-Log.md`, не тихо в плане.

**Billium владеет:** PriceVersion и rating, денежные occurrences, obligations, invoices / credit notes, allocation платежей, collection / dunning, юридическая нумерация документов, собственное исполнение платежей для своих операций (журнал попыток, unknown-recovery, callbacks, reconcile).

**Billium не владеет:**
- права, квоты, планы-как-право — Tariffler;
- agreement-сага покупки — хост (ProductCoordinator);
- розничный заказ — Commerz;
- книга компании и двойная запись — AiFina / `axioma_finance`, GL внутри Billium не делаем (I-20);
- кредиты Edufy и награды Memster — продукты;
- PSP- и касс-интеграции — семейство PaymentDrivers (stateless, отдельный репо; Billium зависит только от его contracts);
- лицензии — Licensing.

**Решения канона:**
- **CD-02** — `billium-contracts` лёгкий; Commerz подключается своим мостом `commerz-billium`, мост живёт в репо Commerz, не здесь.
- **CD-03/04** — PaymentDrivers; фискал запускает исполнитель оплаты.
- **CD-05** — на границах MoneyWire: decimal string + ISO 4217, без float.
- **CD-08** — миграция денежных частей Memster billing и Edufy Billing.
- **CD-14** — профили `embedded` (Laravel-пакет в хосте) и `server` (сервис с API) — один код. Обязательны только contracts + core.
- **CD-15** — Billium и Tariffler друг друга не импортируют; usage/overage Billium принимает по своему входному контракту.

**Dart:** клиент контрактов и SDK, **не второй денежный writer** (`BRAIN/Infrastructure/Workspace/Projects/Billium/Features-Billium.md`).

**Потребители:** Flex/FlexCRM (SaaS), FlexCMS/AiFina/AiResto (лицензии и подписки через их PHP-облака), AiResto MP (тарифы сети), Memster, Edufy, Agelto, ChatomIo, Commerz через мост.

**Обязательные инварианты:**
- один writer на сущность;
- один исполнитель на денежную операцию на всю её жизнь;
- сетевой вызов вне транзакции;
- `unknown` разрешается lookup-ом, а не новым ключом;
- idempotency key + digest, тот же ключ с другим payload = conflict;
- cumulative totals не складываются как дельты;
- refund идёт к исполнителю исходного charge;
- транспортный ACK — не бизнес-receipt.

## 3. P1 — подготовительная фаза (детализировать полностью)

Пункты — ориентир; состав, порядок и батчи выбери сам. У каждого пункта: reads, deliverable в `plans/…/knowledge/` или `docs/`, проверяемый критерий.

1. **Требования и трассировка.** Собрать требования из канона, досье и паспортов (раздел 5) в `docs/requirements.md` репо: state → writer, профили, контракты, инварианты, отказы, ID приёмочных сценариев. Каждая строка — со ссылкой на источник. Отдельно — что нужно от соседей.
2. **Разбор аудита Perplexity.** Все схемы тома 1 и поправки review (G-01…G-06, 10 поправок к ownership). Матрица: схема × требование (профили A–D из отчёта, embedded без сервера, RU-платежи/54-ФЗ, PHP+Dart, single writer, лицензия/edition, эволюция схемы, операционная цена). Отмечать, что подтверждено, а что inference.
3. **Исследования по первичным источникам.** Выполнить задания DR-01 (исходники и реальные схемы: Lago / Kill Bill / OpenMeter / лёгкие PHP-основы; лицензии AGPL и edition-gates, migrations, extension API), DR-02 (RU-платежи и фискализация — только то, что нужно интерфейсу исполнения и ports), DR-04 (Money / PHP–Dart / fixtures). DR-03 — только граница Billium↔Tariffler, без проектирования Tariffler. Результаты — с URL, датой, версией; без проверки — `[UNVERIFIED]`.
4. **Методы разработки.** Изучить AgenTools, Chatom, AzGuard (+ Commerz invariants, Vaulter idempotency ADR) и записать `knowledge/methods.json`:
   - spec-revisions и JSON Schema;
   - language-neutral vectors/golden, conformance runner, JCS-oracle, PHP и Dart SDK;
   - минимальное ядро и модули только через контракты ядра;
   - `#[Api]`/`#[Spi]`, type-map, identities, upgrade-map, release gate;
   - lock model, outbox/inbox, problem-codes;
   - стратегия тестов: только затронутое, мутации и нагрузка отдельно.

   Для каждой практики — принять / адаптировать / отклонить, с причиной.
5. **Выбор целевой схемы.** Решение build / adopt / hybrid по каждой области (contracts, execution journal, obligations/invoicing, rating/schedule, usage-приём, документы, collection). Модель данных: ERD и таблица инвариантов с constraint/lock, FSM операций и инвойса, правила коррекций и retention/restore. Минимум две альтернативы на каждое ключевое решение.
6. **Стек и раскладка.** PHP/Laravel/PostgreSQL-версии, роль Dart, структура монорепо `packages/<name>/` (`axiomasoft/billium-*`), что входит в embedded и что в server. Новый пакет — только с потребителем и инвариантом (extraction gate).
7. **Контракты v1.** `billium-contracts`:
   - команды и исходы оплат/возвратов — совместимые с envelopes пилота `payment.execute.v1` / `payment.outcome.v1`;
   - приём usage/overage-фактов;
   - obligation/invoice queries;
   - события и receipts;
   - ошибки.

   План fixtures для PHP и Dart.
8. **Каталог приёмки.** Сторона Billium из PB-01…24, PF-01…14, MG, FD, SB, EC; N-процессные concurrency-тесты; restore/clone/lost-response.
9. **Целевая структура и roadmap.** Зафиксировать target-structure (как у AzGuard/Chatom), цели и выходы P2…Pn и вопросы ко мне.

**Spikes:** разрешены только минимальные, для проверки решения (например, поднять схему кандидата и прогнать инвариант). Production-кода в P1 нет.

## 4. Верхний уровень P2+ (ориентир, финал — по итогам P1)

| Фаза | Содержание |
|:--|:--|
| P2 | Bootstrap репо, CI, `billium-contracts` PHP+Dart, conformance fixtures |
| P3 | Core: obligations, invoices / credit notes, allocations, conservation-инварианты |
| P4 | PriceVersion, rating, occurrences, schedule / proration |
| P5 | Execution journal: попытки, unknown, callbacks, refunds, reconcile; PaymentDrivers только через contracts, тест-драйвер |
| P6 | Документы и нумерация, fiscal port, accounting export (FinBridge-факты) |
| P7 | Collection / dunning, recurring |
| P8 | Server-профиль: API, seller partitioning; Dart SDK |
| P9 | Адаптеры миграции Memster/Edufy; контракт для `commerz-billium` |
| P10 | Release gate 1.0 |

## 5. Что читать

### Канон и протокол

- `BRAIN/Infrastructure/Guides/Development-Start.md` — протокол
- `BRAIN/Infrastructure/Canon/Ecosystem-Canon.md` — §1–§5
- `BRAIN/Infrastructure/Canon/Money-Domain-Canon.md` — весь, особенно §1, §3, §4, §5, §8, §9
- `BRAIN/Infrastructure/Canon/Canon-Decision-Log.md`
- `BRAIN/Infrastructure/Canon/Bridge-Catalog.md`
- `BRAIN/Infrastructure/Canon/Engineering-Standards.md`
- `BRAIN/Infrastructure/Canon/Unit-Source-Map.md` — строка Billium

### Досье Billium

- `BRAIN/Infrastructure/Workspace/Projects/Billium/Boundary-Billium.md`
- `BRAIN/Infrastructure/Workspace/Projects/Billium/Features-Billium.md` — матрица профилей
- `BRAIN/Infrastructure/Workspace/Projects/Billium/Materials-Billium.md`
- `BRAIN/Infrastructure/Workspace/Unit-Functional-Cards-2026-10-13.md` — раздел Billium
- `BRAIN/Infrastructure/Workspace/Domains/Billing/Billium-Boundaries.md`
- `BRAIN/05-Projects/03-Packages/Billium/Billium.md` — tower; ссылки на `Development/` не использовать

### Аудит Perplexity и его разбор — главный вход выбора схемы

- `BRAIN/Infrastructure/Research/Reports/Billium-Perplexity-Volume-1-2026-10-12.md` — схемы Lago / OpenMeter / Kill Bill, payment storage, invoice, idempotency, usage, credits, outbox, конкуренция, расширяемость, PHP и Dart
- `BRAIN/Infrastructure/Research/Reports/Billium-Volume-1-Review-2026-10-12.md` — статусы доказанности, поправки, gaps G-01…G-06
- `BRAIN/Infrastructure/Research/Evidence/Billium-Audit-Evidence-2026-10-12.md`
- `BRAIN/Infrastructure/Research/Prompts/Billium-Research.md` — исходное задание
- `BRAIN/Infrastructure/Research/Prompts/DR-01-Billing-Sources-and-Schemas.md`
- `BRAIN/Infrastructure/Research/Prompts/DR-02-Russian-Payments-and-Fiscalization.md`
- `BRAIN/Infrastructure/Research/Prompts/DR-03-Rights-Quotas-and-Credits.md`
- `BRAIN/Infrastructure/Research/Prompts/DR-04-Financial-Contracts-and-Authority.md`
- `BRAIN/Infrastructure/Research/Evidence/Primary-Verification-2026-10-12.md`
- `BRAIN/Infrastructure/Research/Evidence/Payment-Provider-Primary-Review-2026-10-12.md`

### Паспорта и контракты — только разделы о Billium

- `BRAIN/Infrastructure/Workspace/Pilot-Commerz-Billium-Payment-Boundary.md` — envelopes, invariants, failure matrix, PB-01…24
- `BRAIN/Infrastructure/Workspace/Domains/Billing/Payment-Execution-Passport.md`
- `BRAIN/Infrastructure/Workspace/Domains/Billing/Payment-Implementation-Prerequisites.md` — PF-01…14
- `BRAIN/Infrastructure/Workspace/Domains/Billing/Payment-Provider-Binding-and-Recovery.md`
- `BRAIN/Infrastructure/Workspace/Domains/Billing/Payment-Intent-Retention-and-Restore.md`
- `BRAIN/Infrastructure/Workspace/Domains/Billing/Payment-Operation-Feed-and-Bootstrap.md`
- `BRAIN/Infrastructure/Workspace/Domains/Billing/Agreement-Grants-Passport.md` — FC-06, occurrences
- `BRAIN/Infrastructure/Workspace/Domains/Tariffs/Tariffler-Boundaries.md`
- `BRAIN/Infrastructure/Workspace/Domains/Tariffs/Usage-Reservation-Frontier.md`
- `BRAIN/Infrastructure/Workspace/Fiscal-Offline-and-Documents-Passport.md`
- `BRAIN/Infrastructure/Workspace/Settlements-and-Bundles-Passport.md`
- `BRAIN/Infrastructure/Workspace/Domains/Finance/Finance-Wire-and-Mapping.md`
- `BRAIN/Infrastructure/Workspace/Domains/Finance/Finance-Kernel-Options.md` — почему без GL
- `BRAIN/Infrastructure/Workspace/Domains/Finance/AiFina-Billium-Financial-Boundaries.md`
- `BRAIN/Infrastructure/Workspace/Projects/Memster/Memster-Subscription-Grants-Passport.md` — первый потребитель и миграция
- `BRAIN/Infrastructure/Workspace/Projects/Edufy/Edufy-Run-Credit-Settlement-Passport.md`
- `BRAIN/Infrastructure/Workspace/Functional-Contract-Index-2026-10-13.md` — FC-02, FC-04, FC-05, FC-06, FC-10
- `BRAIN/Infrastructure/Workspace/Capability-Ownership.md` — C-04…C-12
- `BRAIN/Infrastructure/Decisions/Payment-Decision-Recommendations-2026-10-12.md`
- `BRAIN/Infrastructure/Decisions/Finance-Distribution-Recommendation-2026-10-12.md`
- `BRAIN/Infrastructure/Decisions/Boundary-Owner-Decision-Package-2026-10-13.md` — I-03, I-04, I-09, I-17, I-20, I-21

### Методы разработки — код соседних репо

**AgenTools** — PHP+Dart, spec-first:
- `/home/vostrikov/projects/packages/agentools/README.md`
- `/home/vostrikov/projects/packages/agentools/spec/revisions/2026-09/` — `00_overview`, `09_idempotency-errors`, `11_conformance-harness`, `12_hardening-semantics`, `rules.json`, `schema/`
- `/home/vostrikov/projects/packages/agentools/fixtures/` — vectors, golden, README
- `/home/vostrikov/projects/packages/agentools/conformance/` — runner, adapters, `oracles/jcs`, README
- `/home/vostrikov/projects/packages/agentools/php/`
- `/home/vostrikov/projects/packages/agentools/dart/agentools/`
- `/home/vostrikov/projects/packages/agentools/docs/`

**Chatom** — модульное ядро и стабилизация API:
- `/home/vostrikov/projects/packages/chatom/docs/architecture/` — `philosophy`, `core-boundary`, `layering-rules`, `lifecycle`, `structure`, `adr/`
- `/home/vostrikov/projects/packages/chatom/plans/2026.10.10-№1-CHATOM-API-STABILIZATION/` — `plan.json`, `brief.json`, `decisions/`, `knowledge/` (architecture, api-spi, contracts, identities, type-map, target-structure, upgrade-map, persistence, p2-lock-model, release-gate)
- `/home/vostrikov/projects/packages/chatom/scripts/` — api manifest, bc-check, error codes, golden, quality gates

**AzGuard** — target structure и заморозка контрактов:
- `/home/vostrikov/projects/packages/azguard/plans/2026.10.10-№1-AZGUARD-CORE-STABILIZATION/` — `plan.json`, `brief.json` (B1–B22), `decisions/`, `knowledge/` (architecture, contracts, identities), `views/roadmap.md`
- `/home/vostrikov/projects/packages/azguard/docs/adr/0001-ecosystem-conventions.md`
- `/home/vostrikov/projects/packages/azguard/CHANGELOG.md` — 1.0.0

**Денежные инварианты и idempotency:**
- `/home/vostrikov/projects/packages/commerz/docs/architecture/` — `invariants.md`, `decisions.md` (D7, D8, D18, D33), `extension.md`, `packages.md`
- `/home/vostrikov/projects/packages/commerz/packages/sales/src/Payments/`
- `/home/vostrikov/projects/packages/commerz/packages/sales/src/Refunds/`
- `/home/vostrikov/projects/packages/vaulter/docs/adr/0029-idempotency.md`
- `/home/vostrikov/projects/packages/vaulter/docs/adr/0028-extension-slots-and-operation-pipeline.md`

**Dart / ERP:**
- `/home/vostrikov/projects/packages/erp-sdk/contracts/axioma_contracts/` — Money
- `/home/vostrikov/projects/packages/erp-sdk/versions.yaml`

## 6. Правила

- Не выходить за раздел 2. Конфликт с каноном — D# в плане + предложение CD-записи для Brain.
- Не читать и не использовать `BRAIN/Development/`.
- Внешние факты (версии, лицензии, API провайдеров) — по первичным источникам, с URL и датой.
- Stack по умолчанию — как в экосистеме (PHP 8.4, Laravel 13, PostgreSQL ≥ 16, PHPStan max, Pint, Rector). Отклонение — с причиной.
- Тесты — только затронутое; мутации и нагрузка — отдельные пункты; проваленная проверка не зелёная.
- Push, публикация, релиз-тег — только по моему запросу. Коммиты локально, Conventional Commits с ID плана.
- В конце P1: обновить строку Billium в `BRAIN/Infrastructure/Canon/Package-Status.md`, статусы мостов в `Bridge-Catalog.md`, предложенные CD-записи.

## 7. Открытые вопросы ко мне (собрать, не решать)

- Юридические продавцы и их реквизиты (I-01).
- Какие пакеты бесплатные, а какие платные (I-02).
- Налоговые режимы и фискальные профили (I-16).
- Первый платный сценарий-потребитель — Memster или FlexCRM.
- Список PSP первого запуска.
- Бандл AiFina+AiResto (I-28).
- Валюты и мультиюрисдикция.
