---
id: D5
date: 2026-10-14
status: accepted
item: P1.4
items: [P1.4, P1.6]
supersedes: []
superseded_by: null
---
# D5 — В P1 нет production-кода; spikes изолированы

**Actor:** designer/frontier/high (claude-opus-5-5)
**Evidence:** RAG:— (repo-grounded: `brief/00-owner-message.md` §3 «Spikes»)

## Solution

P1 не создаёт `packages/`, `composer.json`, `pubspec.yaml` и CI. Минимальные spikes нужны только для
проверки решения, например поднять схему кандидата и прогнать инвариант. Они живут в
`plans/2026.10.14-№1-BILLIUM-V1/artifacts/spikes/<slug>/` вместе с `README.md`: цель, гипотеза,
команда, результат, вывод. Сторонний код в git не коммитится, коммитятся только README и
минимальные скрипты. Docker-контейнеры spike после прогона останавливаются.

## Why

- Альтернатива A — spikes в будущих `packages/`. Отклонена: это преждевременно задаёт раскладку,
  которую выбирает P1.6.
- Альтернатива B — spikes вне репозитория. Отклонена: пропадает evidence решения.

## Consequences

Bootstrap репозитория, CI и пакеты — это P2. Spike не считается реализацией и не переносится в
`packages/` копированием.
