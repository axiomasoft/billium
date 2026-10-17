# Billium — правки Brain по итогам P1 (PLAN1.P1.8, D4)

Brain: `/home/vostrikov/Vaults/Brain`. Перед правкой (2026-10-17) `git status --short`: целевые файлы
`Infrastructure/Canon/{Package-Status,Bridge-Catalog,Canon-Decision-Log}.md` чистые; dirty чужие файлы
(`.agents/`, `.claude/`, `.swissknifeman/`, `05-Projects/03-Packages/Billium/Billium.md`, неотслеживаемые
каталоги) не stage-ились. Один локальный коммит только своих hunks, без push. Формат строк повторяет
соседние; новых колонок нет.

## Package-Status

Строка Billium в «Без кода»: стадия S0 → S1.

```diff
-| Billium | репо `~/projects/packages/billium` (скелет, без коммитов, 2026-10-14); tower `05-Projects/03-Packages/Billium/Billium.md` | S0 | SKM PLAN1 по [[Billium-Plan-Prompt]] |
+| Billium | репо `~/projects/packages/billium` — PLAN1 P1: требования, целевая архитектура (build; документы — hybrid), контракты v1, каталог приёмки; кода нет (2026-10-17); tower `05-Projects/03-Packages/Billium/Billium.md` | S1 | P1.9 review; затем plan-design P2 (bootstrap, `billium-contracts` PHP+Dart) |
```

Brain commit: `22ee611fce7ac3a8bc3eb35558a8df0385746ec6`

## Bridge-Catalog

Статусы не меняются (`commerz-billium` — canon CD-02, `billium-finbridge` — proposed); уточнено содержание.

```diff
-| `commerz-billium` | … | Remote-исполнение оплат/возвратов, проекция исхода | canon (CD-02) |
+| `commerz-billium` | … | Remote-исполнение оплат/возвратов, проекция исхода; сторона Billium — `payment.execute.v1`/`payment.outcome.v1` без breaking (Billium PLAN1 P1.7) | canon (CD-02) |
-| `billium-finbridge` | … | sourceFact + revision | proposed |
+| `billium-finbridge` | … | sourceFact + revision; пакет создаётся после подтверждения FinBridge как потребителя (Billium PLAN1 D17, фаза P6) | proposed |
```

Brain commit: `22ee611fce7ac3a8bc3eb35558a8df0385746ec6`

## CD-предложения

Одна запись со статусом «предложено»; канон (`Money-Domain-Canon` B-16) не правится до решения владельца.

- **CD-16 (предложено)** — MoneyWire: `{"amount": "<decimal>", "currency": "<ISO 4217>"}`; amount —
  decimal-строка в major units ровно со scale валюты по версионированной таблице ISO 4217; JSON number,
  экспонента и лишние знаки запрещены; minor + scale (Finance-Wire FC-01) — только внутренняя форма мостов
  учёта. Основание: CONFLICT-03, D29. Применить после принятия: B-16, billium-contracts, commerz-payments,
  fixtures PaymentDrivers, Finance-Wire.

Другие CD не требуются — архитектура P1.6 не сдвигает границ канона (target-architecture § Границы).

Brain commit: `22ee611fce7ac3a8bc3eb35558a8df0385746ec6`

## Не применено — вне трёх разрешённых правок (D4)

Предложения текста для владельца; применяются только с его согласия (owner-questions):

| Файл Brain | Правка | Основание |
|:--|:--|:--|
| `05-Projects/03-Packages/Billium/Billium.md` | D15 «ControLLM → Tariffler → Billium», `uses: [Tariffler]` → вход по контракту Billium (CD-15); фискал — исполнитель (CD-04); provider adapter — PaymentDrivers (CD-03); поле `repo:` — вопрос BRAIN-REPO | CONFLICT-01, CONFLICT-02, CONFLICT-06, CONFLICT-07; файл сейчас dirty (чужие изменения) |
| `Infrastructure/Workspace/Domains/Billing/Billium-Boundaries.md` | «Billium driver adapter» → PaymentDrivers | CONFLICT-02 |
| `Infrastructure/Workspace/Projects/Billium/Features-Billium.md` | Light/Embedded/Server → `embedded`/`server` + authority-профили | CONFLICT-04 |
| towers FlexCRM, FlexStore | `corex/billing` и розница через Billium → канон | CONFLICT-11 |
| tower Memster | «монетизация авторов» → Settlement (I-15) | CONFLICT-12 |
| `Infrastructure/Workspace/Package-Boundary-Policy.md` | `commerz-billium-adapter` → `commerz-billium` | CONFLICT-13 |
