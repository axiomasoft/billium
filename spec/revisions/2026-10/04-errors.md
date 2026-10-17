# 04 — Ошибки и problem-коды

Реестр кодов — `problem-codes.json`; индекс для чтения — `docs/contracts/problem-codes.md`; объект ошибки —
`schema/problem/problem.json`.

[BC-ERR-01] Ошибка — problem object с членами `code` = `billium.<category>.<slug>`, `category`, `retryable`, `sameKey`, `receipt`, `title` и необязательным `detail`; на HTTP — `application/problem+json`. (REQ-CON-19, M-44)

[BC-ERR-02] `problem-codes.json` — единственный носитель реестра кодов; перечисление `problemCode` в `common/defs.json` и индекс `docs/contracts/problem-codes.md` совпадают с ним. (M-44, M-45, M-46)

[BC-ERR-03] `category` кода равна второму сегменту `code`. (M-44)

[BC-ERR-04] `retryable` = true — тот же envelope можно повторить; `sameKey` = true — следующая попытка (повтор или исправленный envelope) использует ту же identity (`commandKey` или `eventId`). (M-44, REQ-CON-05)

[BC-ERR-05] `receipt` = `stored` — отказ по бизнес-состоянию сохраняется как receipt identity: повтор того же envelope возвращает его, иной digest — `billium.idempotency.conflict`. (M-45, D31)

[BC-ERR-06] `receipt` = `none` — claim откатывается и receipt не сохраняется; это коды категорий validation, schema, money, auth, transport, lookup, installation и `billium.idempotency.in_flight`. (M-45, D31)

[BC-ERR-07] Отказы `invalid`, `denied`, `expired` и `recovery-required` не являются исходами провайдера и не запускают автоматический retry новым ключом. (REQ-CON-05)

[BC-ERR-08] У каждого кода назван производитель `producer`; код объявлен ровно одной записью реестра. (M-44, M-46)

[BC-ERR-09] Новый код объявляет `receipt` при добавлении и появляется только в новой ревизии. (M-45, M-01)

[BC-ERR-10] `billium.observation.conflict` сохраняется по `eventId`; `billium.observation.parked` — состояние custody: повторная доставка получает текущий статус custody или исход применения. (REQ-CON-27, REQ-FAIL-12, D31)

[BC-ERR-11] Problem object с кодом вне реестра ревизии при decode даёт `billium.validation.invalid`. (REQ-CON-19, REQ-CON-20)
