---
id: D3
date: 2026-10-14
status: accepted
item: P1.9
items: [P1.1, P1.2, P1.3, P1.4, P1.5, P1.6, P1.7, P1.8, P1.9]
supersedes: []
superseded_by: null
---
# D3 — Приёмка P1: структурный checker и независимый review

**Actor:** designer/frontier/high (claude-opus-5-5)
**Evidence:** RAG:— (repo-grounded: тулинга PHP/Dart в репозитории ещё нет; `docs/development-environment.md`)

## Solution

Каждый item P1 закрывается командой `python3 plans/2026.10.14-№1-BILLIUM-V1/artifacts/P1-design/check-p1.py P1.m`
(stdlib Python, детерминированный). Checker проверяет наличие файлов, обязательные разделы,
покрытие ID (REQ, INV, PB, PF, G, AC), метки доказанности, enum-ы вердиктов и трассировку. Смысловое
качество решений проверяет отдельный read-only item P1.9: независимый review в свежей сессии с
verdict-файлом. Item-ы P1.1–P1.8 используют Review `none`: self-check плюс checker.

## Why

- Альтернатива A — только review. Отклонена: нет детерминированного критерия, и полноту покрытия
  (24 PB, 14 PF, 8 инвариантов) человек или модель надёжно не пересчитает.
- Альтернатива B — только checker. Отклонена: структура не доказывает, что выбор build/adopt/hybrid
  обоснован.
- Альтернатива C — review в каждом item. Отклонена: дорого, и протокол Task прямо запрещает ритуальный
  review на каждый item.

## Consequences

Checker — design-owned артефакт. Executor его не ослабляет. Если требование checker-а противоречит
реальному источнику (например, в паспорте нет PB-17), executor фиксирует это в Known Deviations и
эскалирует, а не правит checker.
