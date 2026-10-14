# P1 — карта внешних источников

Bundle Task принимает только пути внутри репозитория и плана, поэтому Brain и соседние репозитории
перечислены здесь абсолютными путями, сгруппированными по назначению. Item ссылается на нужный
раздел (`research/P1-sources.md` § N) и читает источники адресно, только нужными разделами.
Всё внешнее — evidence, а не нормы плана. Нормы плана: `plan.md`, phase/item spec и accepted D#.

`BRAIN` = `/home/vostrikov/Vaults/Brain`. Каталог `BRAIN/Development/` не читать и не использовать.
Пути проверены владельцем (71/71) и выборочно designer-ом 2026-10-14.

## 1. Канон и протокол

Границы из этих файлов нормативны. Изменить их можно только предложением CD-записи.

- `/home/vostrikov/Vaults/Brain/Infrastructure/Guides/Development-Start.md` — протокол старта разработки
- `/home/vostrikov/Vaults/Brain/Infrastructure/Canon/Ecosystem-Canon.md` — §1–§5
- `/home/vostrikov/Vaults/Brain/Infrastructure/Canon/Money-Domain-Canon.md` — весь; особенно §1, §3, §4, §5, §8, §9
- `/home/vostrikov/Vaults/Brain/Infrastructure/Canon/Canon-Decision-Log.md` — CD-02/03/04/05/08/12/14/15
- `/home/vostrikov/Vaults/Brain/Infrastructure/Canon/Bridge-Catalog.md`
- `/home/vostrikov/Vaults/Brain/Infrastructure/Canon/Engineering-Standards.md`
- `/home/vostrikov/Vaults/Brain/Infrastructure/Canon/Unit-Source-Map.md` — строка Billium
- `/home/vostrikov/Vaults/Brain/Infrastructure/Canon/Package-Status.md` — строка Billium (обновляет P1.8)

## 2. Досье Billium

- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Projects/Billium/Boundary-Billium.md`
- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Projects/Billium/Features-Billium.md` — матрица профилей; Dart = клиент контрактов
- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Projects/Billium/Materials-Billium.md`
- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Unit-Functional-Cards-2026-10-13.md` — раздел Billium
- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Domains/Billing/Billium-Boundaries.md`
- `/home/vostrikov/Vaults/Brain/05-Projects/03-Packages/Billium/Billium.md` — tower; ссылки на `Development/` не использовать

## 3. Аудит Perplexity и его разбор — главный вход выбора схемы

- `/home/vostrikov/Vaults/Brain/Infrastructure/Research/Reports/Billium-Perplexity-Volume-1-2026-10-12.md` — 84 КБ, читать целиком
- `/home/vostrikov/Vaults/Brain/Infrastructure/Research/Reports/Billium-Volume-1-Review-2026-10-12.md` — статусы доказанности, 10 поправок, gaps G-01…G-06
- `/home/vostrikov/Vaults/Brain/Infrastructure/Research/Evidence/Billium-Audit-Evidence-2026-10-12.md`
- `/home/vostrikov/Vaults/Brain/Infrastructure/Research/Prompts/Billium-Research.md` — исходное задание

## 4. Задания на исследования и первичная проверка

- `/home/vostrikov/Vaults/Brain/Infrastructure/Research/Prompts/DR-01-Billing-Sources-and-Schemas.md`
- `/home/vostrikov/Vaults/Brain/Infrastructure/Research/Prompts/DR-02-Russian-Payments-and-Fiscalization.md`
- `/home/vostrikov/Vaults/Brain/Infrastructure/Research/Prompts/DR-03-Rights-Quotas-and-Credits.md` — только граница Billium↔Tariffler
- `/home/vostrikov/Vaults/Brain/Infrastructure/Research/Prompts/DR-04-Financial-Contracts-and-Authority.md`
- `/home/vostrikov/Vaults/Brain/Infrastructure/Research/Evidence/Primary-Verification-2026-10-12.md`
- `/home/vostrikov/Vaults/Brain/Infrastructure/Research/Evidence/Payment-Provider-Primary-Review-2026-10-12.md`

## 5. Паспорта и контракты — только разделы о Billium

Оплата и исполнение:

- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Pilot-Commerz-Billium-Payment-Boundary.md` — envelopes `payment.execute.v1`/`payment.outcome.v1`, invariants, failure matrix, PB-01…24
- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Domains/Billing/Payment-Execution-Passport.md`
- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Domains/Billing/Payment-Implementation-Prerequisites.md` — PF-01…14
- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Domains/Billing/Payment-Provider-Binding-and-Recovery.md`
- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Domains/Billing/Payment-Intent-Retention-and-Restore.md`
- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Domains/Billing/Payment-Operation-Feed-and-Bootstrap.md`

Обязательства, тарифы, usage:

- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Domains/Billing/Agreement-Grants-Passport.md` — FC-06, occurrences
- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Domains/Tariffs/Tariffler-Boundaries.md`
- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Domains/Tariffs/Usage-Reservation-Frontier.md`

Документы, расчёты, финансы:

- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Fiscal-Offline-and-Documents-Passport.md`
- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Settlements-and-Bundles-Passport.md`
- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Domains/Finance/Finance-Wire-and-Mapping.md`
- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Domains/Finance/Finance-Kernel-Options.md` — почему без GL
- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Domains/Finance/AiFina-Billium-Financial-Boundaries.md`

Потребители и миграции:

- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Projects/Memster/Memster-Subscription-Grants-Passport.md` — первый потребитель и миграция
- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Projects/Edufy/Edufy-Run-Credit-Settlement-Passport.md`

Индексы и решения:

- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Functional-Contract-Index-2026-10-13.md` — FC-02, FC-04, FC-05, FC-06, FC-10
- `/home/vostrikov/Vaults/Brain/Infrastructure/Workspace/Capability-Ownership.md` — C-04…C-12
- `/home/vostrikov/Vaults/Brain/Infrastructure/Decisions/Payment-Decision-Recommendations-2026-10-12.md`
- `/home/vostrikov/Vaults/Brain/Infrastructure/Decisions/Finance-Distribution-Recommendation-2026-10-12.md`
- `/home/vostrikov/Vaults/Brain/Infrastructure/Decisions/Boundary-Owner-Decision-Package-2026-10-13.md` — I-03, I-04, I-09, I-17, I-20, I-21

## 6. Методы разработки — код соседних репозиториев

AgenTools (PHP+Dart, spec-first; главный образец):

- `/home/vostrikov/projects/packages/agentools/README.md`
- `/home/vostrikov/projects/packages/agentools/spec/revisions/2026-09/` — `00_overview`, `09_idempotency-errors`, `11_conformance-harness`, `12_hardening-semantics`, `rules.json`, `schema/`
- `/home/vostrikov/projects/packages/agentools/fixtures/` — vectors, golden, README
- `/home/vostrikov/projects/packages/agentools/conformance/` — runner, adapters, `oracles/jcs`, README
- `/home/vostrikov/projects/packages/agentools/php/`
- `/home/vostrikov/projects/packages/agentools/dart/agentools/`
- `/home/vostrikov/projects/packages/agentools/docs/`

Chatom (модульное ядро, стабилизация API):

- `/home/vostrikov/projects/packages/chatom/docs/architecture/` — `philosophy`, `core-boundary`, `layering-rules`, `lifecycle`, `structure`, `adr/`
- `/home/vostrikov/projects/packages/chatom/plans/2026.10.10-№1-CHATOM-API-STABILIZATION/` — `plan.json`, `brief.json`, `decisions/`, `knowledge/` (architecture, api-spi, contracts, identities, type-map, target-structure, upgrade-map, persistence, p2-lock-model, release-gate)
- `/home/vostrikov/projects/packages/chatom/scripts/` — api manifest, bc-check, error codes, golden, quality gates

AzGuard (target structure, заморозка контрактов):

- `/home/vostrikov/projects/packages/azguard/plans/2026.10.10-№1-AZGUARD-CORE-STABILIZATION/` — `plan.json`, `brief.json` (B1–B22), `decisions/`, `knowledge/` (architecture, contracts, identities), `views/roadmap.md`
- `/home/vostrikov/projects/packages/azguard/docs/adr/0001-ecosystem-conventions.md`
- `/home/vostrikov/projects/packages/azguard/CHANGELOG.md` — 1.0.0

Денежные инварианты и idempotency:

- `/home/vostrikov/projects/packages/commerz/docs/architecture/` — `invariants.md`, `decisions.md` (D7, D8, D18, D33), `extension.md`, `packages.md`
- `/home/vostrikov/projects/packages/commerz/packages/sales/src/Payments/`
- `/home/vostrikov/projects/packages/commerz/packages/sales/src/Refunds/`
- `/home/vostrikov/projects/packages/vaulter/docs/adr/0029-idempotency.md`
- `/home/vostrikov/projects/packages/vaulter/docs/adr/0028-extension-slots-and-operation-pipeline.md`

## 7. Dart / ERP

- `/home/vostrikov/projects/packages/erp-sdk/contracts/axioma_contracts/` — Money
- `/home/vostrikov/projects/packages/erp-sdk/versions.yaml`

## 8. Записи вне репозитория (только P1.8, D4)

- `/home/vostrikov/Vaults/Brain/Infrastructure/Canon/Package-Status.md` — строка Billium
- `/home/vostrikov/Vaults/Brain/Infrastructure/Canon/Bridge-Catalog.md` — статусы мостов Billium
- `/home/vostrikov/Vaults/Brain/Infrastructure/Canon/Canon-Decision-Log.md` — только предложенные CD-записи в формате журнала; принятие — решение владельца
