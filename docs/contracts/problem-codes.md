# Индекс problem-кодов Billium

Генерируется `scripts/spec/build-codes-index.py` из `spec/revisions/2026-10/problem-codes.json`;
ручная правка запрещена (M-46). Нормы — `04-errors.md` ревизии; receipt policy — D31.

| Код | Категория | Повторяемо | Тот же ключ | Receipt | Производитель | Когда | Действие клиента | Основание |
|:--|:--|:--|:--|:--|:--|:--|:--|:--|
| `billium.validation.invalid` | validation | нет | да | none | contracts | нарушена схема или поле envelope | исправить envelope; тот же ключ допустим | REQ-CON-05, REQ-CON-20, M-45, D31 |
| `billium.schema.unsupported` | schema | нет | да | none | contracts | неизвестная обязательная схема или major-версия | обновить клиента | REQ-CON-20, M-45, D31 |
| `billium.money.scale_invalid` | money | нет | да | none | contracts | строка суммы не по scale валюты, валюта вне currencies.json или JSON number | исправить сумму; тот же ключ допустим | REQ-CON-02, D29, D31 |
| `billium.money.currency_mismatch` | money | нет | да | none | contracts | валюта не совпадает с операцией или obligation | исправить; тот же ключ допустим | REQ-CON-30, D31 |
| `billium.auth.denied` | auth | нет | да | none | platform | caller, seller или environment не совпадают с контекстом или scope partition | не повторять новым ключом | REQ-CON-05, REQ-CON-21, INV-14, M-45 |
| `billium.idempotency.conflict` | idempotency | нет | нет | none | platform | тот же ключ с другим digest; тот же business binding у иного seller | найти исходную команду; её receipt не меняется | INV-05, REQ-FAIL-06, D36 |
| `billium.idempotency.in_flight` | idempotency | да | да | none | platform | ключ claim-нут, команда выполняется | повторить тот же envelope позже | INV-05, M-53, M-45 |
| `billium.key.expired` | key | нет | нет | none | platform | ключ старше горизонта namespace | payment.find.v1; нового эффекта нет | REQ-FAIL-07, REQ-FAIL-16 |
| `billium.recovery.required` | recovery | нет | да | none | platform | restore, clone или пробел retention; источник feed ниже применённой revision или регресс sourceSequence | ждать сверки оператора | REQ-FAIL-15, REQ-FAIL-23, REQ-FAIL-25 |
| `billium.lookup.unavailable` | lookup | да | да | none | execution | lookup неполон (реплика, доступ) | повторить find тем же запросом | REQ-CON-08, REQ-FAIL-08, M-45 |
| `billium.refund.capacity_exceeded` | refund | нет | нет | stored | execution | сумма refund больше доступной ёмкости | уменьшить сумму или ждать исходов, новым ключом | INV-10, D31 |
| `billium.operation.not_cancellable` | operation | нет | нет | stored | execution | запрос уже отправлен или исход terminal | ждать исхода; refund отдельно | REQ-CON-07, D31 |
| `billium.observation.parked` | observation | да | да | none | ledger | refund раньше платежа, нет основания или пропуск sourceSequence | дослать зависимость или пропуск; повторная доставка того же eventId | REQ-FAIL-12, REQ-FAIL-13, REQ-CON-27 |
| `billium.observation.conflict` | observation | нет | нет | stored | ledger | та же revision или тот же eventId с другим digest | reconcile или оператор; наблюдение в quarantine | REQ-FAIL-04, REQ-CON-27 |
| `billium.usage.conflict` | usage | нет | нет | none | usage | та же identity и revision факта, иной digest | новая revision факта | REQ-CON-15, D31 |
| `billium.policy.unknown` | policy | нет | нет | stored | ledger | нет policy для денежного эффекта | задать policy | REQ-ACC-23, D31 |
| `billium.invoice.frozen` | invoice | нет | нет | stored | ledger | изменение issued invoice вне allow-list | credit note или void/reissue | INV-13, D31 |
| `billium.allocation.reallocation_required` | allocation | нет | нет | stored | ledger | новая allocation из платежа с reallocation_required после refund | выполнить allocation.reallocate.v1, затем новый ключ | INV-09, D35, D31 |
| `billium.installation.quarantined` | installation | нет | да | none | platform | нет допуска writer WriterAdmission: restore, клон, иная incarnation, lease занят | ждать допуска writer | REQ-FAIL-15, INV-02, D34 |
| `billium.transport.unavailable` | transport | да | да | none | contracts | сервер недоступен или timeout | повторить тот же envelope | REQ-CON-05, INV-04, M-45 |
| `billium.allocation.exceeds_open` | allocation | нет | нет | stored | ledger | сумма allocation или credit note больше открытой суммы obligation; уменьшение obligation ниже распределённого | уменьшить сумму или пересмотреть распределения; новый ключ | REQ-CON-20, D31, D47 |
| `billium.allocation.exceeds_available` | allocation | нет | нет | stored | ledger | сумма allocation больше доступного остатка платежа или customer credit | уменьшить сумму или дождаться доступного остатка; новый ключ | REQ-CON-20, D31, D47 |
| `billium.invoice.transition_invalid` | invoice | нет | нет | stored | ledger | команда недопустима в текущем статусе invoice или allocation | выбрать допустимую для текущего статуса команду; новый ключ | REQ-CON-20, D31, D47 |
| `billium.reference.unknown` | reference | да | да | none | ledger | ссылка на obligation, invoice, payment, credit или allocation не найдена в scope seller (чужой seller не раскрывается) | проверить ссылку в scope seller или дождаться зависимости; повторить тем же ключом | REQ-CON-20, INV-14, D31, D47 |
