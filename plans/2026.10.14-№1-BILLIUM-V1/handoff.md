# HANDOFF — 2026-10-14 — after P1

**Next:** design-phase: task:plan-design 2026.10.14-№1-BILLIUM-V1 P1

| Parameter | Meaning |
|:--|:--|
| Batch | n/a |
| Model class | frontier |
| Effort | high — финализация design P1 (design-context → finalize-design), без изменения контрактов items |
| Capabilities | — |
| Context | continue-root |
| Essence | Специфика P1 (9 items) написана полностью. Остался `finalize-design --phase P1`: runtime требует точный вызов `/task:plan-design 2026.10.14-№1-BILLIUM-V1 P1` в сессии, а эта сессия запущена как `start` |

````
/task:plan-design 2026.10.14-№1-BILLIUM-V1 P1
````

После GREEN finalize первый запуск исполнения берётся из `roadmap.md`:
`task:plan-run 2026.10.14-№1-BILLIUM-V1 P1.1`. P1.5 можно запустить параллельно.

**Done:**
- план создан: `plan.md` (верхний уровень P1–P10, правила, Routing P1);
- P1 детализирована: 9 items;
- P2–P10 — скелеты с правилом детализации D7;
- D1–D7;
- полные инвентари источников (D6): `research/P1-corpus.md` — 222 документа Brain;
  `research/P1-audit-inventory.md` — 244 ID аудита и 38 пунктов DR;
- правило происхождения (D7);
- checker `artifacts/P1-design/check-p1.py` проверен на синтетических данных: полное покрытие → GREEN,
  12 дефектов → RED;
- `roadmap.md` с execution sheet.

**Remaining:** finalize-design P1, затем исполнение P1.1–P1.9. После GREEN P1.9 —
`task:plan-design 2026.10.14-№1-BILLIUM-V1 P2`.

**Sources of truth:** `plans/2026.10.14-№1-BILLIUM-V1/plan.md` · `phases/P1/P1.md` · `brief/00-owner-message.md` · `brief/01-refinements.md` · `research/P1-corpus.md` · `research/P1-audit-inventory.md` · `decisions/`

**Open risks:**
- MG/FD/SB/EC: точные ID сценариев в паспортах designer не перечислял. Если какого-то набора нет,
  P1.7 эскалирует по D3, а не ослабляет checker.
- Brain содержит чужие dirty-файлы. P1.8 коммитит только свои hunks (D4).

**Workarounds/Deferred/Open questions:**
- workarounds: внешние пути (Brain, соседние репо) недоступны bundler-у → `research/P1-sources.md` § N
- deferred: заполнение `repo:` в карточке Brain (вопрос `BRAIN-REPO`, P1.8); детализация P2–P10 (D1)
- open_questions: I-01, I-02, I-16, I-28, PSP первого запуска, валюты, первый платный потребитель — собираются в P1.8
