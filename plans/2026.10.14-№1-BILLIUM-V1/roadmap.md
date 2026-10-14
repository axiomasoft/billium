# Execution Roadmap — 2026.10.14-№1-BILLIUM-V1

<!-- execution-sheet/v1 -->

**Обновлён:** 2026-10-14 · **Соответствует plan.md:** design pass 2 (P1 детализирована, 9 items; D1–D8)

Roadmap не переопределяет Routing §3 и owner gates. Он группирует только готовые соседние items P1.
P2–P10 — скелеты: их строки появятся после `task:plan-design 2026.10.14-№1-BILLIUM-V1 Pn`.

## Execution sheet — единственный источник команд запуска

| Command | Unified route | Review |
|:--|:--|:--|
| `task:plan-run 2026.10.14-№1-BILLIUM-V1 P1.1` | `frontier/high` | `none` |
| `task:plan-run 2026.10.14-№1-BILLIUM-V1 P1.2` | `frontier/high` | `none` |
| `task:plan-run 2026.10.14-№1-BILLIUM-V1 P1.3` | `frontier/high` | `none` |
| `task:plan-run 2026.10.14-№1-BILLIUM-V1 P1.4` | `frontier/high` | `none` |
| `task:plan-run 2026.10.14-№1-BILLIUM-V1 P1.5` | `frontier/high` | `none` |
| `task:plan-run 2026.10.14-№1-BILLIUM-V1 P1.6 P1.7 P1.8` | `frontier/high` | `none` |
| `task:plan-run 2026.10.14-№1-BILLIUM-V1 P1.9` | `frontier/high` | `none` |

## Execution card

| Item | Batch | Launch | Model class/Effort | Owner's Gate | Note |
|---|---|---|---|---|---|
| P1.1 | solo | plan-run | frontier/high | — | корпус A (45) → `docs/requirements.md`, леджер A |
| P1.2 | solo | plan-run | frontier/high | — | корпус B/C (177) → леджер 222, новые REQ/INV; после P1.1 |
| P1.3 | solo | plan-run | frontier/high | — | 244 ID аудита; после P1.2 |
| P1.4 | solo | plan-run | frontier/high | — | capability: web/primary-source research; после P1.3 |
| P1.5 | solo | plan-run | frontier/high | — | независим; можно параллельно с P1.1–P1.4 |
| P1.6 | B-P1-DESIGN | plan-run series | frontier/high | — | после P1.4 и P1.5 |
| P1.7 | B-P1-DESIGN | ↑ | frontier/high | — | контракты и приёмка на модели P1.6 |
| P1.8 | B-P1-DESIGN | ↑ | frontier/high | — | правки Brain по D4 (локальный commit, без push) |
| P1.9 | solo | plan-run | frontier/high | — | свежая сессия, не авторская; read-only verdict |

## Ready launch-block groups

### P1.1 — требования по корпусу A

| Parameter | Meaning |
|:--|:--|
| Batch | solo |
| Model class | frontier |
| Effort | high — трассировка 45 документов канона/досье/паспортов |
| Capabilities | — |
| Context | continue-root |
| Essence | `docs/requirements.md` + леджер уровня A |

```
run-items: task:plan-run 2026.10.14-№1-BILLIUM-V1 P1.1
```

### P1.2 — расширенный корпус B/C

| Parameter | Meaning |
|:--|:--|
| Batch | solo |
| Model class | frontier |
| Effort | high — сплошной проход 177 документов без пропусков |
| Capabilities | — |
| Context | continue-root |
| Essence | леджер всех 222 SRC + новые REQ/INV |

```
run-items: task:plan-run 2026.10.14-№1-BILLIUM-V1 P1.2
```

### P1.3 — полный разбор аудита

| Parameter | Meaning |
|:--|:--|
| Batch | solo |
| Model class | frontier |
| Effort | high — 244 ID аудита, оценка доказанности синтеза |
| Capabilities | — |
| Context | continue-root |
| Essence | `knowledge/audit-matrix.md`: покрытие, CL, матрица, FS, кандидаты |

```
run-items: task:plan-run 2026.10.14-№1-BILLIUM-V1 P1.3
```

### P1.4 — первичные исследования

| Parameter | Meaning |
|:--|:--|
| Batch | solo |
| Model class | frontier |
| Effort | high — первичные источники, лицензии, 54-ФЗ |
| Capabilities | web search / fetch (первичные URL); Perplexity — только поиск |
| Context | continue-root |
| Essence | DR-01, DR-02, DR-03-граница, DR-04 с покрытием 38 пунктов |

```
run-items: task:plan-run 2026.10.14-№1-BILLIUM-V1 P1.4
```

### P1.5 — методы разработки

| Parameter | Meaning |
|:--|:--|
| Batch | solo |
| Model class | frontier |
| Effort | high — вердикты accept/adapt/reject по практикам соседей |
| Capabilities | — |
| Context | continue-root |
| Essence | `knowledge/methods.json` с sources_reviewed |

```
run-items: task:plan-run 2026.10.14-№1-BILLIUM-V1 P1.5
```

### B-P1-DESIGN — целевая схема, контракты, итог

| Parameter | Meaning |
|:--|:--|
| Batch | B-P1-DESIGN |
| Model class | frontier |
| Effort | high — необратимые к 1.0 архитектурные решения денежного домена |
| Capabilities | — |
| Context | continue-root |
| Essence | P1.6 → P1.7 → P1.8; при нехватке контекста допустима готовая подгруппа |

```
run-items: task:plan-run 2026.10.14-№1-BILLIUM-V1 P1.6 P1.7 P1.8
```

### P1.9 — независимый review

| Parameter | Meaning |
|:--|:--|
| Batch | solo |
| Model class | frontier |
| Effort | high — adversarial проверка инвариантов, границ, происхождения и полноты |
| Capabilities | — |
| Context | isolated-child-return — свежий reviewer-контекст, не автор P1.1–P1.8; root владеет closure |
| Essence | read-only verdict `reviews/P1-review.md` |

```
run-items: task:plan-run 2026.10.14-№1-BILLIUM-V1 P1.9
```

## Owner gates (summary)

| Where | What does | Blocks |
|---|---|---|
| P1.8 | вопросы владельцу (`knowledge/owner-questions.md`), включая `BRAIN-REPO`, `CONFLICT-NN` и CD-предложения | детализацию затронутых фаз P2+ (не саму P1) |
