# axiomasoft/billium-core

Laravel 13 composition root of Billium. It owns the operation runner that wraps every money mutation in one
root transaction, the module catalog and lifecycle rules, runtime caller/authority contracts, configuration
(`BilliumOptions`), schema-set registration and the doctor check. The business modules live in
[`modules/`](modules) and are composed here; core ships no business tables of its own.

## Entry points

- `src/BilliumCoreServiceProvider.php` and `src/Laravel/Registrars/BilliumCoreRegistrar.php` — host boot and bindings.
- `src/Plugins/` — one Packman plugin per module (`PlatformPlugin`, `LedgerPlugin`, `UsagePlugin`,
  `SchedulePlugin`, `RatingPlugin`, `ExecutionPlugin`).
- `src/Contracts/Runner/OperationRunner.php` (implemented by `src/Internal/Runner/DatabaseOperationRunner.php`) —
  root pipeline: admission, idempotency claim, planned locks (`LockSet`), seal.
- `src/Contracts/Modules/` and `src/Internal/Modules/` — module registry, admission gate and transition preview.
- `src/Contracts/Runtime/` — caller context, financial authority/session admission, event publishing.
- `src/Configuration/` — closed-schema options; schema in `resources/schemas/config.schema.json`.
- `src/Database/` — schema-set providers that register the module migrations.

## Layout

- `config/billium.php` — published host configuration.
- `database/migrations/<module>/` — forward-only migrations per module (raw SQL inside PHP migrations).
- `resources/` — generated `php-api.json` / `error-codes.json` contracts, `modules.json` registry, config schema.
- `docs/transaction-boundaries.md` — root transaction rules.
- `tests/` — composition-level tests: `Arch` and `Architecture` (module boundaries, table ownership),
  `Feature`, `Integration` (multi-module compositions), `Drill` (restore/upgrade drills), `Unit`, `Support`.

## Checks

```bash
vendor/bin/pest packages/core/tests --exclude-group=pg
PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 vendor/bin/pest packages/core/tests --group=pg
```

Type index: [docs/source-index/billium-core.md](../../docs/source-index/billium-core.md).
Repository overview: [CODEBASE-MAP](../../docs/architecture/CODEBASE-MAP.md).
