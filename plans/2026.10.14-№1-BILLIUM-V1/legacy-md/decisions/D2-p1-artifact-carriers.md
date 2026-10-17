---
id: D2
date: 2026-10-14
status: accepted
item: P1.1
items: [P1.1, P1.2, P1.3, P1.4, P1.5, P1.6, P1.7, P1.8]
supersedes: []
superseded_by: null
---
# D2 — Где живут результаты P1

**Actor:** designer/frontier/high (claude-opus-5-5)
**Evidence:** RAG:— (repo-grounded: `brief/00-owner-message.md` §3; bundler Task 0.28.6 отклоняет внешние пути в Inputs/Required Reads как `PORTABILITY_EXTERNAL_INPUT`)

## Solution

- Нормативные документы, которые переживут план, лежат в `docs/`: `docs/requirements.md`,
  `docs/architecture/target-architecture.md`, `docs/architecture/data-model.md`,
  `docs/contracts/billium-contracts-v1.md`, `docs/acceptance-catalog.md`.
- Доказательная база и рабочие матрицы лежат в `plans/2026.10.14-№1-BILLIUM-V1/knowledge/`:
  `corpus-ledger.md`, `audit-matrix.md`, `research/DR-*.md`, `methods.json`, `target-structure.md`,
  `roadmap-proposal.md`, `owner-questions.md`, `brain-updates.md`.
- Внешние источники item читает через `research/P1-sources.md` § N. Абсолютные пути в Inputs и
  Required Reads не ставятся.
- `methods.json` остаётся JSON по требованию владельца (машинно проверяемый перечень практик).
  Остальное — Markdown: ERD и FSM в mermaid читаются людьми и рендерятся в Brain.

## Why

- Альтернатива A — всё в `knowledge/` в виде JSON, как в Chatom/AzGuard. Отклонена: требования,
  архитектура и контракты — постоянная документация репозитория, а не материал одного плана.
- Альтернатива B — всё в `docs/`. Отклонена: матрицы аудита и research-заметки — доказательства
  решений, а не документация продукта. Они остаются в плане как provenance.
- Альтернатива C — внешние пути прямо в Required Reads. Невозможна: bundler их отклоняет.

## Consequences

`artifacts/P1-design/check-p1.py` проверяет именно эти пути (D3). Перенос файла требует новой D-записи
и правки item spec.
