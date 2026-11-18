# axiomasoft/billium-rating

Deterministic pricing: immutable price versions, pricing strategies, proration, rating pipelines, tax snapshots and tax-aware corrections that book into the ledger.

## Entry points

Paths are relative to `src/`. Public types carry `#[Api]`, host extension points `#[Spi]`.

- `Contracts/RatingQuoter.php` — pure quotes (no ledger needed)
- `Contracts/RatingPreparer.php` — prepares a rating run from usage/schedule inputs
- `Contracts/RatingBooking.php` — books rated lines into the ledger
- `Contracts/RatingCorrection.php` — issued-invoice corrections
- `Contracts/PricingStrategyRegistry.php` — pluggable pricing strategies
- `Contracts/TaxSnapshotPort.php` — tax evidence port

## Layout

- `src/Contracts`, `src/Data`, `src/Enums` — public surface; other modules may import only these.
- `src/Internal` — implementation; never imported from another module (enforced by `packages/core/tests/Arch/ModuleBoundariesTest.php`).
- `src/Laravel` — service provider and capability declaration; core composes it through `Billium\Core\Plugins`.
- Migrations: `packages/core/database/migrations/rating/` (raw SQL in PHP migrations, forward-only).
- Tests: `tests/` (Feature, Support, Unit).

## Dependencies

Depends on: `billium-contracts`, `billium-core`, `billium-ledger`, `billium-platform`, `billium-schedule`, `billium-usage`.

## Checks

```bash
vendor/bin/pest packages/core/modules/rating/tests --exclude-group=pg
PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 vendor/bin/pest packages/core/modules/rating/tests --group=pg
```

Type index: [docs/source-index/billium-rating.md](../../../../docs/source-index/billium-rating.md). Related: [optional-commercial-modules](../../../../docs/architecture/optional-commercial-modules.md), [module-compatibility](../../../../docs/architecture/module-compatibility.md).
