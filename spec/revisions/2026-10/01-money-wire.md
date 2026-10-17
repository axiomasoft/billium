# 01 — MoneyWire и денежная арифметика

Форма суммы на границах billium-contracts и операции codec, которые PHP и Dart исполняют одинаково.
Схема — `schema/common/money-wire.json`; scale — `currencies.json`.

## Форма

[BC-MW-01] MoneyWire — JSON-объект ровно с членами `amount` и `currency`; иной член — нарушение формы MoneyWire. (REQ-CON-02, D29)

[BC-MW-02] `currency` — три заглавные латинские буквы, и код есть в `currencies.json` этой ревизии; иначе `billium.money.scale_invalid`. (REQ-CON-02, D19, D29)

[BC-MW-03] Строка amount содержит ровно scale знаков после точки; при scale 0 точки нет. (REQ-CON-02, D29)

[BC-MW-04] `amount` — JSON string; JSON number, `null`, boolean, объект или массив дают `billium.money.scale_invalid`. (REQ-CON-02, D29, M-15)

[BC-MW-05] Лексика amount при scale s: s = 0 — `^-?(0|[1-9][0-9]*)$`; s > 0 — `^-?(0|[1-9][0-9]*)\.[0-9]{s}$`; отрицательный ноль, ведущий `+`, пробелы, экспонента и ведущие нули дают `billium.money.scale_invalid`. (REQ-CON-02, D29)

[BC-MW-06] Scale валюты — `CcyMnrUnts` записи SIX ISO 4217 List One, зафиксированной в `currencies.json` с URL, датой `Pblshd` и sha256 XML; запись с `N.A.` в таблицу не входит. (D19, D29)

[BC-MW-07] Нарушение формы MoneyWire (BC-MW-01…BC-MW-05) при прочих валидных членах envelope даёт `billium.money.scale_invalid`. (REQ-CON-02, D29, D31)

## Parse и format

[BC-MW-08] Parse даёт `(minor, currency)`: minor — целое со знаком из цифр amount без точки. (D19, D29)

[BC-MW-09] minor вне `[-9223372036854775808, 9223372036854775807]` даёт `billium.validation.invalid`; переполнение не заворачивается. (D19, REQ-CON-30)

[BC-MW-10] Промежуточная арифметика — целые произвольной точности; float и double в денежном пути запрещены. (REQ-CON-02, REQ-CON-30, D19)

[BC-MW-11] Format `(minor, currency)` пишет amount по scale валюты; parse после format и format после parse возвращают исход байт-в-байт. (D29, REQ-CON-03)

[BC-MW-12] Положительность или неотрицательность суммы — правило поля envelope, не MoneyWire; её нарушение даёт `billium.validation.invalid`. (D29, REQ-CON-04)

## Операции

[BC-MW-13] `money.add(a, b)`: разные валюты дают `billium.money.currency_mismatch`; результат вне int64 — `billium.validation.invalid`. (REQ-CON-30, D19)

[BC-MW-14] `money.allocate(total, ratios)`: ratios — непустой массив строк неотрицательных целых (`0` или `[1-9][0-9]*`), не все нули; иначе `billium.validation.invalid`. (REQ-CON-30, REQ-ACC-13)

[BC-MW-15] Largest remainder: для T = |total| и R = Σ r_i части `base_i = floor(T·r_i / R)`, `rem_i = T·r_i mod R`; остаток `T − Σ base_i` раздаётся по одной minor unit в порядке убывания `rem_i`, при равенстве меньший индекс первым; `r_i = 0` остатка не получает. (REQ-CON-30, REQ-ACC-13)

[BC-MW-16] Отрицательный total распределяется по |total|, затем каждая часть меняет знак; части — в валюте total и в порядке ratios; Σ частей = total. (REQ-CON-30, REQ-ACC-13)

[BC-MW-17] `money.round(value, currency, mode)`: value — decimal string `^-?(0|[1-9][0-9]*)(\.[0-9]+)?$` с любым числом знаков; иная запись — `billium.validation.invalid`; результат — MoneyWire по scale валюты, ноль без знака. (REQ-CON-30, D29)

[BC-MW-18] Режимы округления по имени: `HALF_EVEN` (половина к чётному) и `HALF_UP` (половина от нуля); иной режим даёт `billium.validation.invalid`. (REQ-CON-30)

## Примеры (DR-04)

- allocate `1.00 USD` по `2, 3, 1` → `0.33`, `0.50`, `0.17`; `0.01 USD` по `1, 1, 1` → `0.01`, `0.00`, `0.00`;
  `-1.00 USD` по `1, 1, 1` → `-0.34`, `-0.33`, `-0.33`.
- round `0.005` RUB: HALF_EVEN `0.00`, HALF_UP `0.01`; `0.015` RUB: оба `0.02`; `-0.005` RUB HALF_UP `-0.01`;
  `2.5` JPY: HALF_EVEN `2`, HALF_UP `3`.
- scale: `1490.00` RUB, `1` JPY, `1.000` KWD; `1.0` JPY — `billium.money.scale_invalid`.
