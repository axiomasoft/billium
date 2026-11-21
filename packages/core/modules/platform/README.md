# axiomasoft/billium-platform

Infrastructure every money-changing module builds on: installation and financial-session state, idempotency receipts, the transactional outbox and its relay, the authenticated inbox, feed partitions and the event-contract registry.

## Entry points

Paths are relative to `src/`. Public types carry `#[Api]`, host extension points `#[Spi]`.

- `Contracts/Installation` — installation, module state and financial sessions (implements core `ModuleStateReader`)
- `Contracts/Idempotency` — first-write idempotency claim of every root transaction
- `Contracts/Outbox` — transactional outbox written inside the owning root Tx
- `Contracts/Inbox` — raw custody of authenticated inbound deliveries
- `Contracts/Feed` — feed partitions and subscriptions
- `Contracts/EventContracts` — registered event contracts per owner

Console commands: `billium:installation:init` (`src/Internal/Installation`), `billium:outbox:relay`, `billium:outbox:prune`, `billium:event-contracts:register` (`src/Internal/Console`).

## Layout

- `src/Contracts`, `src/Data`, `src/Exceptions` — public surface; other modules may import only these.
- `src/Internal` — implementation; never imported from another module (enforced by `packages/core/tests/Arch/ModuleBoundariesTest.php`).
- `src/Laravel` — service provider and capability declaration; core composes it through `Billium\Core\Plugins`.
- Migrations: `packages/core/database/migrations/platform/` (raw SQL in PHP migrations, forward-only).
- Tests: `tests/` (Concurrency, Drill, Feature, Inbox, Outbox, Support).

## Dependencies

Depends on: `billium-contracts`, `billium-core`.

## Checks

```bash
vendor/bin/pest packages/core/modules/platform/tests --exclude-group=pg
PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 vendor/bin/pest packages/core/modules/platform/tests --group=pg
```

Type index: [docs/source-index/billium-platform.md](../../../../docs/source-index/billium-platform.md). Related: [outbox-feed](../../../../docs/operations/outbox-feed.md), [lock-model](../../../../docs/architecture/lock-model.md).
