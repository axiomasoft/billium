# DR-03 — граница Billium ↔ Tariffler (usage, overage, grants)

Задание: `BRAIN/Infrastructure/Research/Prompts/DR-03-Rights-Quotas-and-Credits.md` (пункты DR03-T01…T09).
По `research/P1-sources.md` § 4 из DR-03 берётся только граница Billium↔Tariffler; Tariffler здесь не
проектируется (REQ-OUT-01, REQ-OUT-10). Проверка 2026-10-17: канон и досье Brain (supplied), исходники
`revoltify/subscriptionify` 1.0.2 и OpenMeter v1.0.0-beta.236 на commit tag (static, тесты не запускались).
Репозитории Memster и Edufy на машине отсутствуют — их факты взяты из evidence L-10/L-12 (supplied).

## Покрытие задания

| ID | Диспозиция | Ссылки | Примечание |
|:--|:--|:--|:--|
| DR03-T01 | ответ | DR03-04, DR03-05, DR03-06, DR03-07 | Только baseline subscriptionify, на который опираются миграции Billium; прочие кандидаты — Tariffler |
| DR03-T02 | вне границ | REQ-OUT-01 | Capability matrix прав и квот — Tariffler |
| DR03-T03 | ответ | DR03-01, DR03-05, DR03-12 | Деньги — Billium, права — Tariffler, сага — хост; риск второго subscription state |
| DR03-T04 | ответ | DR03-01, DR03-13, DR03-14 | Billing occurrence ≠ grant occurrence; supersession — P1.6 |
| DR03-T05 | вне границ | REQ-OUT-10 | Strict reservation — Tariffler; Billium получает только итоговые факты |
| DR03-T06 | ответ | DR03-06, DR03-08, DR03-15 | Service units не маскируются под Money |
| DR03-T07 | ответ | DR03-04, DR03-05, DR03-09, DR03-10 | Денежная сторона миграции; точная deployed версия — в «Открыто» |
| DR03-T08 | вне границ | REQ-OUT-01, REQ-OUT-13 | PDP, entitlement verdict, module gate — хост и Tariffler |
| DR03-T09 | вне границ | REQ-OUT-01, REQ-OUT-14 | Reuse verdict по units Tariffler; сторона Billium — P1.6 |

## Утверждения

| ID | Утверждение | Источник | Дата | Версия | Статус |
|:--|:--|:--|:--|:--|:--|
| DR03-01 | CD-15: Billium и Tariffler не импортируют друг друга; право выдаёт хост по оплаченному agreement occurrence; usage/overage-факты Billium принимает по своему входному контракту, не завися от пакета-источника | `BRAIN/Infrastructure/Canon/Canon-Decision-Log.md` | 2026-10-17 | CD-15 от 2026-10-14 | supplied |
| DR03-02 | AC-01 (разбор тома 1): usage facts, money и quota — три owners: коллекторы измеряют, billing rating создаёт начисление, Tariffler или delegated authority резервирует service units | `BRAIN/Infrastructure/Research/Reports/Billium-Volume-1-Review-2026-10-12.md` | 2026-10-17 | разбор от 2026-10-12 | supplied |
| DR03-03 | AC-04: commit, release и expiry резерва — альтернативные исходы; partial commit требует сохранения количества и идемпотентных переходов | `BRAIN/Infrastructure/Research/Reports/Billium-Volume-1-Review-2026-10-12.md` | 2026-10-17 | разбор от 2026-10-12 | supplied |
| DR03-04 | subscriptionify 1.0.2 (Packagist 2026-09-06): MIT, PHP ^8.2, `illuminate/support` ^11.0, ^12.0 или ^13.0 — совместим с Laravel 13 по manifest | https://github.com/revoltify/subscriptionify/blob/049ec4fc85cf8af68bc3240e0f303c7039e9b4bb/composer.json | 2026-10-17 | 1.0.2 @049ec4f | verified-static |
| DR03-05 | subscriptionify: таблица plans хранит billing_period, billing_interval, trial_days, grace_days, is_free, но не цену и не валюту; subscriptions — bigint id, morph subscribable, status, starts/ends/trial/cancelled/renewed_at | https://github.com/revoltify/subscriptionify/blob/049ec4fc85cf8af68bc3240e0f303c7039e9b4bb/database/migrations/0001_01_01_000004_create_subscriptions_table.php | 2026-10-17 | 1.0.2 @049ec4f | verified-static |
| DR03-06 | subscriptionify: значение feature и `feature_usages.used` хранятся строкой (`string`, default `'0'`) | https://github.com/revoltify/subscriptionify/blob/049ec4fc85cf8af68bc3240e0f303c7039e9b4bb/database/migrations/0001_01_01_000005_create_feature_usages_table.php | 2026-10-17 | 1.0.2 @049ec4f | verified-static |
| DR03-07 | subscriptionify: потребление идёт под `lockForUpdate` (`src/Services/FeatureService.php:366`); reserve/reservation в `src/` нет | https://github.com/revoltify/subscriptionify/blob/049ec4fc85cf8af68bc3240e0f303c7039e9b4bb/src/Services/FeatureService.php#L366 | 2026-10-17 | 1.0.2 @049ec4f | verified-static |
| DR03-08 | OpenMeter: grant amount — Go float при PostgreSQL numeric, в credit/entitlement нет reserve/commit/release API | https://github.com/openmeterio/openmeter/blob/e00163c4b8a0726198c95a9ccb158d38ea8c05b8/openmeter/ent/schema/grant.go#L38-L40 | 2026-10-17 | v1.0.0-beta.236 @e00163c | verified-static |
| DR03-09 | Memster: `revoltify/subscriptionify:^1.0`, User — Subscribable (evidence L-10) | `BRAIN/Infrastructure/Research/Evidence/Billium-Audit-Evidence-2026-10-12.md` L-10 | 2026-10-17 | memster @1c05c49 | supplied |
| DR03-10 | Edufy DP-07 (draft): baseline subscriptionify, string usage, bigint subscription IDs, независимый период grant (evidence L-12) | `BRAIN/Infrastructure/Research/Evidence/Billium-Audit-Evidence-2026-10-12.md` L-12 | 2026-10-17 | edufy @60ac46b | supplied |
| DR03-11 | REQ-OUT-10: измерение usage и резервирование квот — коллекторы и Tariffler; Billium не резервирует единицы | `docs/requirements.md` | 2026-10-17 | P1.1 | supplied |
| DR03-12 | REQ-OUT-02: сага покупки agreement принадлежит хосту (ProductCoordinator) | `docs/requirements.md` | 2026-10-17 | P1.1 | supplied |
| DR03-13 | REQ-OWN-02: Billium владеет денежным BillingSchedule и billing/collection occurrences; grant occurrence и PlanVersion — Tariffler | `docs/requirements.md` | 2026-10-17 | P1.1 | supplied |
| DR03-14 | AC-03: UNIQUE с schedule revision не запрещает двум revisions создать начисление за тот же бизнес-период; нужна supersession policy | `BRAIN/Infrastructure/Research/Reports/Billium-Volume-1-Review-2026-10-12.md` | 2026-10-17 | разбор от 2026-10-12 | supplied |
| DR03-15 | REQ-CON-15…17: входной usage-факт Billium несёт стабильный ID и явную семантику метрики (additive, gauge); `OverageEvent` v1 не расширяется молча | `docs/requirements.md` | 2026-10-17 | P1.1 | supplied |

## Выводы для Billium

1. Billium принимает от Tariffler и коллекторов только факты (usage, overage) по своему входному контракту и не импортирует Tariffler (DR03-01, DR03-15).
2. Резервирование единиц, его исходы и late usage после expiry — ответственность Tariffler (DR03-11, DR03-03); Billium видит поздний факт как adjustment (REQ-FAIL-21).
3. Три owners по AC-01 (DR03-02) закрепляются в P1.6 как граница пакетов: rating и денежное начисление — Billium, quota — Tariffler.
4. Billing occurrence и grant occurrence — разные идентичности (DR03-13); годовая оплата с месячными grants связывается ссылкой, не общей строкой (CL-56).
5. Уникальность денежного occurrence не может включать revision расписания как достаточный ключ (DR03-14; INV-11).
6. subscriptionify не хранит цену (DR03-05): миграция Memster/Edufy не выводит деньги из плана (REQ-NBR-16), но `billing_period`/`renewed_at` — второе состояние продления, которое после миграции не должно управлять оплатой.
7. Строковые значения usage в subscriptionify (DR03-06) и float grant OpenMeter (DR03-08) — не денежные величины; Billium принимает количества как decimal string с явной семантикой.
8. Ни subscriptionify, ни OpenMeter не дают reserve API (DR03-07, DR03-08): если Tariffler выберет strict reservation, это его build-new, не Billium.
9. subscriptionify 1.0.2 совместим с Laravel 13 по manifest (DR03-04) — адаптер миграции на стороне хоста возможен без форка.

## Проверка утверждений аудита

| ID | Диспозиция | Ссылки | Примечание |
|:--|:--|:--|:--|
| CL-26 | опровергнуто | DR03-08 | Reserve API нет; grant — float |
| CL-56 | подтверждено | DR03-13 | Канон разделяет billing и grant occurrences |
| CL-57 | подтверждено | DR03-04, DR03-09, DR03-10 | — |
| CL-60 | уточнено | DR03-03, DR03-11 | FSM резерва — у Tariffler |
| CL-61 | уточнено | DR03-07 | consume под lock есть, reserve нет |
| CL-80 | уточнено | DR03-01, DR03-12 | Сага — хост; один writer на lifecycle сохраняется |
| CL-95 | подтверждено | DR03-05, DR03-09 | Цены в subscriptionify нет |
| CL-109 | уточнено | DR03-07, DR03-08 | Reserve отсутствует у обоих; proration не изучалась |
| CL-114 | уточнено | DR03-01, DR03-11, DR03-12 | Quota — Tariffler, сага — хост; миграция — в «Открыто» |
| C-15 | опровергнуто | DR03-08 | — |
| C-23 | подтверждено | DR03-09, DR03-10 | — |

## Открыто

- DR03-T07 (остаток): точная установленная версия subscriptionify в Memster (lock-файл) и маппинг legacy IDs в денежные occurrences не проверены — репозитории Memster/Edufy на машине отсутствуют.
- DR03-T04 (остаток): supersession policy revisions расписания и окна месячных grants по часовому поясу — P1.6.
- Proration и renewal у subscriptionify (`renewed_at`) не трассированы.
