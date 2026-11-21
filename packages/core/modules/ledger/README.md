# axiomasoft/billium-ledger

Accounts-receivable subledger: obligations, invoices, credit notes, allocations, settlement intents, customer credit and payment observations. All writes go through guarded PostgreSQL routines inside one root transaction.

## Entry points

Paths are relative to `src/`. Public types carry `#[Api]`, host extension points `#[Spi]`.

- `Contracts/Ledger.php` — root-only financial commands
- `Contracts/LedgerQueries.php` — authenticated, side-effect-free recovery reads
- `Contracts/LedgerParticipants.php` — typed participation in a root Tx owned by another module (corrections, numbering)
- `Contracts/ObservationApplier.php` — applies authenticated payment observations
- `Internal/Policy` — allocation policies such as `pro_rata_open/1`

Console commands: `billium:ledger:drain-parked`, `billium:ledger:reconcile-refunds` (`src/Internal/Console`).

## Layout

- `src/Contracts`, `src/Data` — public surface; other modules may import only these.
- `src/Internal` — implementation; never imported from another module (enforced by `packages/core/tests/Arch/ModuleBoundariesTest.php`).
- `src/Laravel` — service provider and capability declaration; core composes it through `Billium\Core\Plugins`.
- Migrations: `packages/core/database/migrations/ledger/` (raw SQL in PHP migrations, forward-only).
- Tests: `tests/` (Commands, Concurrency, Contract, Database, Feature, Observation, Queries, Schema, Unit).

## Dependencies

Depends on: `billium-contracts`, `billium-core`, `billium-platform`.

## Checks

```bash
vendor/bin/pest packages/core/modules/ledger/tests --exclude-group=pg
PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 vendor/bin/pest packages/core/modules/ledger/tests --group=pg
```

Type index: [docs/source-index/billium-ledger.md](../../../../docs/source-index/billium-ledger.md). Related: [data-model](../../../../docs/architecture/data-model.md), [ledger-queries](../../../../docs/contracts/ledger-queries.md).
