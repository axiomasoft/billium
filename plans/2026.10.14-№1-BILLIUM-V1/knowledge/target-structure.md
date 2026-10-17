# Billium — target structure (PLAN1.P1.6)

Нормативный эталон раскладки для P2+ (M-36). Изменение — только поправка плана с D#. Основа: D9, D16,
D17, D18; поверхность модулей — M-38, REQ-CON-31; identities — M-27, M-28.

## Каталоги

```text
billium/
├── spec/revisions/<YYYY-MM>/        # нормативная спека billium-contracts: документы, schema/, rules.json (генерируется), revision.lock.json
├── fixtures/                        # language-neutral vectors/ и golden/ (MoneyWire, envelopes, ошибки, digest)
├── conformance/                     # runner, adapters/php, adapters/dart, oracles/jcs (пин upstream)
├── packages/
│   ├── contracts/                   # axiomasoft/billium-contracts (PHP)
│   ├── contracts-dart/              # billium_contracts (Dart, publish_to: none до решения)
│   ├── core/                        # axiomasoft/billium-core
│   │   ├── src/                     # Billium\Core\ — OperationRunner, конфиг, ServiceProvider
│   │   ├── modules/<m>/src/         # Billium\<M>\ — platform, usage, rating, schedule, ledger, documents, collection, execution
│   │   └── database/migrations/<m>/ # миграции по модулям; basenames — identities
│   ├── server/                      # axiomasoft/billium-server (HTTP API, problem+json, auth verdict port)
│   ├── testkit/                     # axiomasoft/billium-testkit (dev-only: тест-драйвер, doubles, conformance adapter)
│   ├── sdk-dart/                    # billium_sdk — создаётся только после gate потребителя (P8)
│   └── finbridge/                   # axiomasoft/billium-finbridge — только после gate (P6)
├── docs/                            # requirements, architecture/, contracts/, acceptance-catalog
└── scripts/                         # один носитель CI-гейта (M-33), генераторы type-map/identities/codes
```

## Namespaces и публичная поверхность

| Пакет / модуль | PSR-4 / library | Публичные категории | Internal |
|:--|:--|:--|:--|
| billium-contracts | `Billium\Contracts\` | `Contracts`, `Data`, `Enums`, `Events`, `Exceptions` | codec internals |
| billium-core | `Billium\Core\` | `Contracts` (OperationRunner, порты хоста), `Data`, `Enums`, `Events`, `Exceptions` | `Internal/` |
| модуль `<m>` | `Billium\<M>\` | то же; Eloquent только в `Internal/Models` | `Internal/` |
| billium-server | `Billium\Server\` | `Contracts` (auth verdict, seller resolver), `Data` | HTTP-слой |
| billium-testkit | `Billium\Testkit\` | `#[Api]` doubles для тестов | — |
| billium_contracts (Dart) | `package:billium_contracts/billium_contracts.dart` | MoneyWire, envelopes, ошибки | `src/` |

- Маркеры `#[Api]` и `#[Spi(kind: Call|Implement)]` — собственные атрибуты Billium (M-24).
- SPI Implement: порты драйвера (через `payment-drivers/contracts`), renderer, storage, auth verdict,
  источник seller/actor хоста, `WriterAdmission` (внешний допуск writer для execution: lease, ticket, acquire/renew/release с `commit_no` журнала `money_commit_log`, D34, D39). SPI Call: `applyPaymentObservation`, `prepareObligation`,
  `authorizeCollection`, приём usage-фактов.
- Модуль не импортирует `Internal` соседа и не пишет его таблицы; ссылки на хост, Tariffler, Commerz —
  строковые `*_ref` (M-23).
- Публичные сигнатуры не содержат Eloquent и float (REQ-CON-31, M-48).

## Identities

Реестр identities (M-27) с P2 содержит: envelopes `*.v1` и их поля; problem-codes
`billium.<category>.<slug>`; event types; persisted статусы FSM операции и invoice; номерные серии;
config-ключи; routes server API; basenames миграций; ID ревизии спеки; doctor finding ids. Каждая
запись имеет статус frozen/changed/new/retired и снапшот-fixture.

## Что замораживается к 1.0

| Что | Когда фиксируется | Изменение после 1.0 |
|:--|:--|:--|
| Ревизия спеки billium-contracts и envelopes `.v1` | P2 (draft), freeze в P10 | только новая ревизия / `.v2` |
| MoneyWire и problem-codes | P2 | аддитивно (REQ-CON-20) |
| Схема ядра (таблицы, статусы, constraints) | P3–P7, freeze в P10 | expand/contract (REQ-FAIL-29) |
| `#[Api]`/`#[Spi]` типы и type-map | каталог до кода фазы, freeze в P10 | major-версия |
| Identities (миграции, коды, events, routes) | с первого появления | major-версия (M-28) |

## Владение тестами

- Тесты пакета живут в пакете; N-процессные PostgreSQL-тесты пар из data-model — в модуле-владельце
  пары; conformance — в `conformance/`.
- Карта зон `tests/zones.json` (M-58) с P2; изменение схемы, lock или journal включает race-тесты
  затронутого (M-59).
