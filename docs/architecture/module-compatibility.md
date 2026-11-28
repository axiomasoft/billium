# Billium — модули ядра: descriptor, композиция и совместимость схемы

Статус: PLAN1.P3.13. Нормативный источник — `spec/modules.json` (форма — `spec/schema/module-descriptor.json`);
пакетная копия `packages/core/resources/modules.json` генерируется `scripts/spec/export-modules.py` и проверяется
`scripts/spec/check-module-compatibility.py`. Решения: D17, D18, D21, D55, D60–D63. Границы канона не меняются;
модули остаются внутри `billium-core` (D17, D55), расширение до отдельного пакета — только через extraction gate
«потребитель + инвариант + D#».

## Что описывает descriptor

Один descriptor на собственный модуль Billium: `platform`, `ledger`, `usage`, `rating`, `schedule`, `documents`,
`execution`, `collection`. Произвольной регистрации внешних plugin-ов нет; Billium не регистрирует product-модули
хоста, не создаёт их БД и не запускает миграции ресурсов хоста (REQ-OUT-19, D55). В JSON нет секретов и исполняемых
callback.

| Поле | Смысл |
|---|---|
| `moduleId`, `schemaNamespace` | стабильный идентификатор и логическое пространство схемы (`billium.<moduleId>`) |
| `moduleApiVersion`, `coreApiRange` | версия API модуля и диапазон core API (`min` включительно, `maxExclusive` исключительно) |
| `migrationStream`, `currentSchemaVersion`, `supportedSchemaReadRange`, `supportedSchemaWriteRange` | поток SQL-миграций (= каталог `packages/core/database/migrations/<stream>/`), последняя поставляемая версия, диапазоны чтения и записи (write ⊆ read) |
| `migrationIdentities` | `{id (basename), version, checksum}`; basename и checksum исторической миграции не меняются |
| `requiresModules` | модуль и диапазон его API, под который собран зависимый |
| `requiresPorts`, `optionalPorts` | host-bindings или порты других модулей; реестр `ports[]` задаёт провайдера (`host` либо moduleId) |
| `emits`, `consumes` | версии wire-схем (зарегистрированные envelope identities) |
| `ownedTables`, `configKeys` | таблицы с префиксом `billium_` (D45) и ключи `config/billium.php`; один владелец на сущность (INV-01) |
| `disablePolicy`, `retirementPolicy`, `uninstallPolicy`, `retentionClass` | lifecycle, блокеры retired, правило binary uninstall, класс хранения |
| `publicEvents[]`, `internalCommands[]` | D63: публичные события и внутренние команды раздельно |

Реестр `compositions[]` — это матрица M/O/X из D55/D61: `embedded-commerz`, `standalone-billium`,
`remote-billium`, `ledger-only` (профиль acceptance `--profile ledger-only`). Delivery и authority независимы (D61):
в `ledger-only` execution и collection отсутствуют, `WriterAdmission` запрещён; `remote-billium` с effect-capable
исполнением требует execution и `WriterAdmission`. Столбец `server` в `embedded-commerz` — пример развёртывания,
не запрет.

Внутренний DAG: ledger→platform; usage→platform; rating→platform; schedule→platform+rating;
documents→platform+ledger; execution→platform (+ порты PaymentDrivers, WriterAdmission, PaymentObservationSink,
который связывает ledger); collection→platform+ledger+execution. Нельзя включить schedule без rating или collection
без execution; «просто выключить отправку» недостаточно (D55).

## Нулевые checkpoint-ы

Начальные descriptors перечисляют планируемые потоки с фактическим нулём: `currentSchemaVersion = 0`,
`ownedTables = []`, `migrationIdentities = []`, диапазоны `{0,0}`. SQL, которого ещё нет, не объявляется применённым.
Каждая фаза, добавляющая миграцию (P3.3+), одновременно дописывает таблицы, `migrationIdentities` (с checksum) и
`currentSchemaVersion`; проверка сверяет их с реальным каталогом миграций, поэтому расхождение краснеет.

## Boot-проверка (`ModuleRegistry::check`)

Сравниваются desired config (`billium.modules.composition`, `billium.modules.enabled`), набор descriptors,
installed checkpoints и port capabilities. Результат — `CompatibilityReport` с `findings[]` (doctor-идентичности
`module.*`) и `bootOrder`. Любой finding закрывает effect-capable boot: liveness может быть зелёным, readiness — нет;
read-only диагностика отдельна и не скрывает writers. Проверки:

- дубликат/неизвестный `moduleId`, цикл, отсутствующий модуль, диапазон API зависимости;
- неизвестный или не связанный порт, запрещённый порт композиции;
- core API вне `coreApiRange` включённого модуля;
- installed schema вне read range (`schema_read_unsupported`), вне write range (`schema_write_unsupported`),
  неприменённая миграция (`migration_unapplied`);
- модуль с историей без исторического reader (`historical_reader_missing`);
- конфликт владения таблицей/ключом/потоком и конфликт контракта события между модулями.

Отключённый optional-модуль не требует внешних зависимостей: порты и зависимости проверяются только у включённых.
Ничего из этого не выполняет миграции и не создаёт таблицы при boot (D21, D55); Billium даёт status/doctor/check
primitives, а штатные Laravel migrations вызывает host.

## Окно N / N−1

Пакетный SemVer, API модуля, wire schema, ревизия спеки, SQL-поток, контракт драйвера и `bindingRevision` — разные
оси (D21, D55). Окно rolling deploy: N и N−1 читают расширенную схему; писать расширенную форму может только код,
чей write range её включает. Пример проверки: read `{1,2}`, write `{2,2}` — установленная версия 1 читается, но даёт
`schema_write_unsupported`. Новый персистентный статус не пишется раньше расширения читателя и SQL CHECK.

## События и команды (D63)

`publicEvents[]` — `{eventType, envelopeSchema, eventContractRevision, payloadSchemaRef, payloadSchemaDigest,
bootstrapMode, snapshotSourceKey?, retentionPolicyRef}`. Правила:

- `billium.event.v1` строгий: допустимы только семь типов BC-EVT-03; новый публичный тип требует согласованной
  ревизии каталога/envelope (`billium.event.v2`, `events-YYYY-MM-v2`), старый enum и фикстура unknown-event не меняются.
- Digest — `sha256` канонического JSON (sorted keys, без пробелов) узла схемы по `payloadSchemaRef`;
  `check-module-compatibility.py` пересчитывает его.
- Каждый публичный поток объявляет либо `bootstrapMode=snapshot` с `snapshotSourceKey` вида `<moduleId>.<source>`,
  либо явный `replay_only`/`non_bootstrappable` без ключа.
- `internalCommands[]` — отдельная полоса (`billium.command.vN`), не события, не попадают в публичный feed/каталог/
  SnapshotSource; тип команды не совпадает с типом события.
- `EventContractRegistrar` (`Billium\Platform\Contracts`) — единственный путь регистрации; он возвращает
  `EventContractRegistrationPlan` и пишет только в явной install/upgrade-команде. Свежий отключённый модуль не
  создаёт строк реестра; использованный отключённый сохраняет исторические. Все таблицы реестра и command-lane и
  writer — P3.4; модуль не делает ALTER/INSERT в таблицы платформы.
- Начальные descriptors объявляют семь событий v1 за `ledger` (эмиттер P3.3); `ledger.*` snapshot-ключи — обязательство
  реализовать SnapshotSource в P3.4/ledger-фазах (имена зафиксированы здесь, реализаций пока нет).

## Статическая проверка (CI)

`check-module-compatibility.py` проверяет: JSON-схему, DAG и диапазоны, `ownedTables` = таблицам, созданным
миграциями потока, отсутствие незадекларированных таблиц и каталогов миграций, basename/checksum миграций,
`configKeys` = листьям `config/billium.php`, декларации событий (strict v1, digest, владелец snapshot-ключа),
равенство пакетного ресурса источнику, равенство enum `CompatibilityFindingCode` и `findingCodes`, регистрацию
identities (`config-key`, `migration`, `doctor-finding`). Arch-тесты `ModuleBoundariesTest` добавляют: публичные
типы core несут `#[Api]`/`#[Spi]`, не выставляют `Internal` в сигнатурах, контракты модулей не содержат
персистентности, каждому каталогу модуля соответствует descriptor и PSR-4 root.

## Требования к P3.3 (интеграция вместо дублирования)

P3.13 даёт статические контракты и **не заявляет race-safety**. P3.3 обязан:

1. Создать `billium_module_state` (installation_ref, module_id, state, config_revision, schema_version,
   last_transition_ref) и append-only `billium_module_transitions` (actor, evidence, time, reason) и реализовать
   `ModuleStateReader`; добавить таблицы и миграции в descriptor `platform` (см. «Нулевые checkpoint-ы»).
2. Проверять `ModuleAdmissionGate::admit` для денежных handlers **в той же корневой Tx** после idempotency claim и
   installation lock (D27), используя `TransactionContext` runner-а P3.3; кэш Boolean и состояние вне Tx недостаточны.
3. Выполнять переход (disable против принятой команды — сериализуемая граница) под installation lock, заново считая
   `ModuleTransitionFacts`; preview в core — только чтение.
4. Долгие workers перечитывают generation/fence перед dispatch (D54, D62); release D54 относится к активации
   execution и не вводит глобальный lease ledger.
5. Подключить `ModuleRegistry::check` к readiness/doctor и передать реальные checkpoints и порты; core намеренно не
   делает этого при boot.
6. Не переопределять в `platform`/`ledger` ограничения реестра событий: это P3.4.
