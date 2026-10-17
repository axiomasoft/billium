# 02 — Идемпотентность, канонизация и digest

Ключ команды, его scope, каноническая форма envelope и digest (D32). Эталон канонизации — вендоренный
JCS-оракул `conformance/oracles/jcs/` (M-15).

## Ключ и scope

[BC-ID-01] Каждая входящая команда, меняющая деньги или денежные основания, несёт `commandKey` вызывающего в текстовой форме UUID. (INV-05, REQ-CON-21)

[BC-ID-02] Scope ключа платёжной команды — `(installationRef, environment, sellerRef, operationKind, commandKey)`; у `payment.refund.v1` operationKind = `refund`. (INV-05, INV-14, D30)

[BC-ID-03] Seller входит в scope каждого ключа идемпотентности и в каждый бизнес-ключ уникальности. (INV-14)

[BC-ID-04] Business binding `(source.context, source.intentRef, operationKind)` уникален без `sellerRef`: совпадение с операцией того же продавца возвращает её, с операцией иного продавца — `billium.idempotency.conflict` без раскрытия чужой операции. (D36, INV-14)

[BC-ID-05] Ключ claim-ится в корневой транзакции мутации вместе с digest команды. (REQ-CON-32, INV-05)

[BC-ID-06] Повтор с тем же ключом и тем же digest возвращает сохранённый receipt без нового эффекта. (INV-05, REQ-CON-32)

[BC-ID-07] Тот же ключ в том же scope с иным digest даёт `billium.idempotency.conflict`; исходный receipt не меняется. (INV-05, REQ-CON-32, REQ-FAIL-06)

[BC-ID-08] Receipt ключа сохраняется для принятой команды и для кода с `receipt` = `stored`; при `none` claim откатывается, и тот же ключ можно повторить, в том числе с исправленным envelope. (M-45, D31)

[BC-ID-09] Timeout и транспортная ошибка не означают отказ: команда повторяется тем же ключом и тем же envelope, неизвестный исход разрешается `payment.find.v1`, а не новым ключом. (INV-04, REQ-CON-05)

[BC-ID-10] `payment.observation.v1` не несёт `commandKey`: его identity — `eventId`, экономическая identity — `(sellerRef, operationRef, operationRevision)`. (REQ-CON-27, REQ-CON-13)

[BC-ID-11] Identity факта usage — `(sellerRef, source, factId, revision)`: тот же digest даёт `duplicate`, иной digest — `conflict` с кодом `billium.usage.conflict`. (REQ-CON-15, D13)

## Канонизация и digest

[BC-ID-12] Каноническая форма — RFC 8785 (JCS) над envelope без члена digest верхнего уровня (`digest`; у `payment.observation.v1` — `contentDigest`); вложенные объекты входят целиком. (D32, M-15)

[BC-ID-13] Digest = `sha256:` + 64 строчных hex SHA-256 от UTF-8 байт канонической формы. (D32)

[BC-ID-14] Транспортные данные (HTTP-заголовки, metadata очереди, ID доставки) в envelope не входят и в digest не участвуют. (D32, REQ-CON-21)

[BC-ID-15] JCS сортирует ключи объекта по UTF-16 code units, экранирует строки по RFC 8785 без экранирования `/` и пишет числа по ECMAScript. (D32, M-15)

[BC-ID-16] Целые члены envelope (`revision`, `routingEpoch`, `sourceSequence` и подобные) лежат в `[0, 9007199254740991]`; иное значение даёт `billium.validation.invalid`. (D32, M-15, D29)

[BC-ID-17] Если входящая команда несёт `digest`, он равен digest, вычисленному получателем по BC-ID-12; иначе `billium.validation.invalid`. (D30, D32, D43)

## Пример (D32)

Envelope:

```json
{
  "schema": "payment.execute.v1",
  "commandKey": "0b6f2c1e-6d1a-4a44-9a51-3f0c6f2f7a10",
  "operationRef": "op-1",
  "operationKind": "charge",
  "source": {"context": "commerz", "orderRef": "ord-1", "intentRef": "int-1"},
  "installationRef": "inst-1",
  "environment": "test",
  "executionProfile": "remote-billium",
  "routingEpoch": 1,
  "sellerRef": "seller-1",
  "merchantAccountRef": "ma-1",
  "money": {"amount": "1490.00", "currency": "RUB"},
  "bindingRevision": "sha256:00",
  "requestedAt": "2026-10-17T12:00:00Z"
}
```

Каноническая форма (ASCII, только строки и целые — JCS сводится к сортировке ключей без пробелов):

`{"bindingRevision":"sha256:00","commandKey":"0b6f2c1e-6d1a-4a44-9a51-3f0c6f2f7a10","environment":"test","executionProfile":"remote-billium","installationRef":"inst-1","merchantAccountRef":"ma-1","money":{"amount":"1490.00","currency":"RUB"},"operationKind":"charge","operationRef":"op-1","requestedAt":"2026-10-17T12:00:00Z","routingEpoch":1,"schema":"payment.execute.v1","sellerRef":"seller-1","source":{"context":"commerz","intentRef":"int-1","orderRef":"ord-1"}}`

Digest: `sha256:4e51ca0273f40946bfc6ada7f414e9fd8d6228786142c0391070fa51132f4b52`.
