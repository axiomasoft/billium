---
id: D4
date: 2026-10-14
status: accepted
item: P1.8
items: [P1.8]
supersedes: []
superseded_by: null
---
# D4 — Записи в Brain в конце P1

**Actor:** designer/frontier/high (claude-opus-5-5)
**Evidence:** RAG:— (repo-grounded: `brief/00-owner-message.md` §6; `brief/01-refinements.md` п. 2)

## Solution

P1.8 вносит в Brain ровно три правки, их явно разрешает постановка §6:

1. строка Billium в `Infrastructure/Canon/Package-Status.md`;
2. статусы мостов Billium в `Infrastructure/Canon/Bridge-Catalog.md`;
3. предложенные CD-записи в `Infrastructure/Canon/Canon-Decision-Log.md` со статусом «предложено»
   в формате этого журнала. Принимает их владелец.

Точный текст каждой правки и SHA локального commit в Brain записываются в
`knowledge/brain-updates.md`. Commit в Brain — локальный, только свои hunks, без push. Frontmatter
`repo:` карточки Billium без ответа владельца не меняется: это включает brain sync, то есть даёт
внешний эффект. Вопрос и рекомендация уходят в `knowledge/owner-questions.md`.

## Why

- Альтернатива A — не писать в Brain, только предложить текст. Отклонена: владелец прямо поручил
  обновить строки в конце P1.
- Альтернатива B — заодно заполнить `repo:`. Отклонена: побочный эффект sync описан неполно (часть
  текста владельца повреждена), а необратимость и масштаб неизвестны.

## Consequences

Brain — отдельный git-репозиторий. Bundler не допускает внешние пути, поэтому Files в P1.8 перечисляет
только пути репозитория. Brain-правки ограничены Scope Included и этой записью. Доказательство —
SHA в `knowledge/brain-updates.md`.
