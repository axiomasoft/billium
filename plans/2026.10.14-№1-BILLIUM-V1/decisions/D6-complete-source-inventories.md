---
id: D6
date: 2026-10-14
status: accepted
item: P1.1
items: [P1.1, P1.2, P1.3, P1.4, P1.9]
supersedes: []
superseded_by: null
---
# D6 — Полный инвентарь источников: корпус Brain и аудит Perplexity

**Actor:** designer/frontier/high (claude-opus-5-5)
**Evidence:** RAG:— (repo-grounded: поиск designer-а 2026-10-14 `billium|billkit|billiot` по Brain вне `Development/` дал 217 документов; список владельца покрывает 40 из них; аудит — 93 раздела отчёта и 151 ID разбора/evidence; задания DR — 38 пунктов)

## Solution

На design-этапе снят snapshot двух инвентарей, их генерирует `artifacts/P1-design/build-inventories.py`:

- `research/P1-corpus.md` — 222 документа Brain (SRC-001…SRC-222). Уровень A — обязательный список
  владельца (45, разделы о Billium читаются полностью). B — ≥ 3 упоминаний (84). C — 1–2 упоминания
  (93).
- `research/P1-audit-inventory.md`:
  - 93 раздела отчёта тома 1 (AUD-001…AUD-093);
  - C-01…C-26, V3-01…V3-20, AC-01…AC-10, G-01…G-06, R-01…R-30;
  - FS-01…FS-26, E-01…E-08, L-01…L-15, Q-01…Q-10;
  - 38 пунктов заданий DR (DR01-T01…DR04-T09).

Каждый ID обязан получить диспозицию:
- корпус — в `knowledge/corpus-ledger.md` (P1.1 — уровень A, P1.2 — B и C);
- аудит — в `knowledge/audit-matrix.md` § «Покрытие аудита» (P1.3);
- задания DR — в `knowledge/research/DR-0N*.md` § «Покрытие задания» (P1.4).

Checker отклоняет пропуск любого ID. Изменение инвентаря после design — это design-delta: rerun
генератора, новая D-запись. Executor инвентарь не правит.

## Why

- Альтернатива A — только список владельца (40 документов). Отклонена: 177 документов Brain с
  упоминаниями Billium остались бы непрочитанными. Среди них Package-Boundaries (20 упоминаний),
  Package-Boundary-Deep-Audit, Shared-Layer-Draft (на него ссылается разбор тома 1), Integration-Map,
  Implementation-Order. Это противоречит требованию владельца проработать максимальный объём.
- Альтернатива B — «читать аудит целиком» как правило без инвентаря. Отклонена: такое правило нельзя
  проверить, а пропуск раздела или ID в отчёте на 86 КБ незаметен.
- Альтернатива C — инвентарь строит executor. Отклонена: executor сам определял бы границу полноты.

## Consequences

Задача P1.1 разделена на две: P1.1 (уровень A → требования) и P1.2 (сплошной проход B и C).
Последующие items сдвинуты на один номер (P1.3 аудит … P1.9 review). Routing — один item на сессию:
объём уровня A ≈ 850 КБ, B ≈ 1,1 МБ, пакет аудита ≈ 183 КБ.
