# axiomasoft/billium-execution

Payment execution journal: qualified provider dispatch, authorization, capture, void, refunds, callback custody, reconciliation and recovery. Provider adapters plug in through `PaymentDriver`.

## Entry points

Paths are relative to `src/`. Public types carry `#[Api]`, host extension points `#[Spi]`.

- `Contracts/RootPaymentCommands.php` — root payment commands
- `Contracts/AuthorizationCommands.php, RefundCommands.php` — authorization/capture/void and refunds
- `Contracts/PaymentDriver.php, PaymentDrivers.php` — provider adapter SPI
- `Contracts/CallbackCustody.php` — inbound provider callbacks
- `Contracts/ReconciliationCommands.php, RepairCommands.php` — reconciliation and repair
- `Contracts/ExecutionQueries.php` — read side

## Layout

- `src/Contracts`, `src/Data`, `src/Enums` — public surface; other modules may import only these.
- `src/Internal` — implementation; never imported from another module (enforced by `packages/core/tests/Arch/ModuleBoundariesTest.php`).
- `src/Laravel` — service provider and capability declaration; core composes it through `Billium\Core\Plugins`.
- Migrations: `packages/core/database/migrations/execution/` (raw SQL in PHP migrations, forward-only).
- Tests: `tests/` (Concurrency, Contract, Drill, Feature, Support).

## Dependencies

Depends on: `billium-contracts`, `billium-core`, `billium-ledger`, `billium-platform`.

## Checks

```bash
vendor/bin/pest packages/core/modules/execution/tests --exclude-group=pg
PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 vendor/bin/pest packages/core/modules/execution/tests --group=pg
```

Type index: [docs/source-index/billium-execution.md](../../../../docs/source-index/billium-execution.md). Related: [target-architecture](../../../../docs/architecture/target-architecture.md).
