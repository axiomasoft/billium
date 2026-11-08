# Billium — жизненный цикл модуля: disable, drain, retire, uninstall

Статус: PLAN1.P3.13. Контракты — `Billium\Core\Contracts\Modules\*` (`ModuleAdmissionGate`,
`ModuleTransitionPlanner`, `ModuleStateReader`), правила — `ModuleLifecycle`. Решения: D54, D55, D57, D60–D63.
Хранение состояния, переходы и root-Tx admission seal реализует P3.3; здесь описана семантика, а не гарантия
гонок (см. docs/architecture/module-compatibility.md § «Требования к P3.3»).

## Состояния и классы операций

`accepting → draining → recovery_only → retired`. Допустим возврат `draining → accepting` (resume); пропуск шагов и
выход из `retired` — нет. Решение об операции принимается по классу, а не одним Boolean (D55).

| Класс операции | accepting | draining | recovery_only | retired |
|---|---|---|---|---|
| `new_intent` — новый intent/occurrence/usage/charge | да | нет | нет | нет |
| `frozen_intent_completion` — шаг уже принятого intent со старым frozen binding | да | да | нет | нет |
| `original_owner_refund` — новая refund-команда по старому charge | да | да* | нет | нет |
| `receipt_replay` | да | да | да | да |
| `historical_read` | да | да | да | да |
| `unknown_resolution` — lookup/reconciliation | да | да | да | нет |
| `callback_custody` — inbox custody, проверка callback | да | да | да | нет |
| `observed_fact_recording` — запись подтверждённого внешнего факта | да | да | да | нет |

\* Только исходный owner, по refund lock/capacity и текущим правам; `ModuleAdmission.conditions` возвращает эти
проверки вызывающему. Refund — отдельная retention-capability, её нельзя потерять из-за остановки новых charge
(D55, D57). Допущение: в `recovery_only` новая refund-команда закрыта как новое денежное намерение; пока по модулю
есть обязательства refund, он не может стать `retired`.

Дополнительно descriptor сужает матрицу: модуль обслуживает вне `accepting` только классы из `disablePolicy.keeps`
(у `usage` это replay и read). `recovery_only` означает «нет новых намеренных денег», а не запрет сохранять
подтверждённый факт PSP. **Unknown никогда не отменяется по таймеру**: правила только блокируют ими `retired`;
неустранимый unknown остаётся в recovery/intervention.

## Частные правила

- Disable `collection` останавливает новые шаги и расписания, не recovery execution.
- Disable `execution` закрывает новые charge и каскадом — зависимые модули (`disablePolicy.dependents = cascade`);
  lookup, callback, refund и reconcile остаются у прежнего owner.
- Disable `usage` оставляет принятый корпус доступным; новый источник получает typed module-unavailable до custody.
- Disable renderer/delivery не блокирует денежные факты; legal numbers и frozen artifacts читаются; disable
  `documents` не превращает выпущенный invoice в draft.
- `ledger` нельзя остановить, пока host принимает payment observations (`host_observations_active`) или есть
  денежная история без reader.

## Preview (`ModuleTransitionPlanner`)

`previewTransition` и `previewUninstall` только читают и возвращают `ModuleTransitionPreview`: `allowed`, `blockers[]`
(код, число, детали), `cascade[]`, `stillServed[]`, `purgesData = false`. Факты (`ModuleTransitionFacts`) заполняет
владелец хранения.

| Переход | Блокеры |
|---|---|
| любой | `transition_invalid` (пропуск шага, выход из retired), `module_unknown` |
| → draining / recovery_only | зависимые модули (`dependent_module_active`, либо `cascade` у execution), `historical_reader_missing`, `host_observations_active` (ledger) |
| → recovery_only | дополнительно `pending_operations` (принятая работа завершается в draining) |
| → retired | всё из `retirementPolicy.blockers`: `pending_operations`, `unknown_effects`, `feed_gaps`, `unexported_facts`, `outbox_inbox_gaps`, `historical_reader_missing`, `dependent_module_active`; по модулю также `active_bindings`, `secret_recovery_dependency`, `retention_floor` |

## Uninstall binary ≠ purge

Удаление бинарника — отдельная операция и не удаляет схему или данные (D55). `previewUninstall`:

- при наличии истории модуль должен быть `retired` (`module_not_retired`) и иметь установленный совместимый
  read/recovery bundle (`read_bundle_missing`), иначе удаление запрещено;
- block-list строится до удаления: исторические DTO/schema/binding/driver readers, pending intents, legal documents,
  неэкспортированные факты, retention floors;
- модуль без истории (никогда не использовался) удаляется без bundle.

Purge — привилегированная retention operation владельца retention: legal hold, backup/restore proof, floors/tombstones,
audit receipt. В 1.0 автоматического purge денежной схемы нет (`uninstallPolicy.schemaPurge = never_automatic`), а
destructive `down()` для финансовых миграций не пишется. Отзыв секретов — отдельное действие с явным влиянием на
recovery (`secret_recovery_dependency`); revocation не «закрывает» unknown.

## Оптимальный footprint

Отключённая функциональность при первой установке не создаёт routes, workers, таблицы, строки реестра событий и
требований к сторонним сервисам; Composer footprint отделён от runtime optionality (D55). Никакого auto-install на
первом HTTP-запросе, миграций при boot сервера и uninstall product-ресурсов хоста.

## Процедура (до P3.3 — только preview)

1. `previewTransition(current, draining, facts)`; при `cascade` запланировать переходы зависимых.
2. Дождаться завершения admitted work, затем `recovery_only`; unknown остаются под lookup/intervention.
3. `retired` только при пустых блокерах; затем `previewUninstall` и сохранение read bundle перед удалением пакета.
4. Purge — отдельным решением владельца retention вне этой процедуры.
