# axiomasoft/billium-usage

Usage metric ingestion, attribution, source completeness tracking and deterministic window aggregation. Optional: fixed-price schedules can run without it.

## Entry points

Paths are relative to `src/`. Public types carry `#[Api]`, host extension points `#[Spi]`.

- `Contracts/Usage.php` — fact ingestion
- `Contracts/UsageAttribution.php` — attribution of facts to subscriptions
- `Contracts/SourceCompletenessIntake.php` — source completeness frontiers
- `Contracts/AggregationService.php` — frozen window aggregation
- `Contracts/MetricReducerRegistry.php` — pluggable metric reducers

## Layout

- `src/Contracts`, `src/Data`, `src/Enums` — public surface; other modules may import only these.
- `src/Internal` — implementation; never imported from another module (enforced by `packages/core/tests/Arch/ModuleBoundariesTest.php`).
- `src/Laravel` — service provider and capability declaration; core composes it through `Billium\Core\Plugins`.
- Migrations: `packages/core/database/migrations/usage/` (raw SQL in PHP migrations, forward-only).
- Tests: `tests/` (Concurrency, Feature, Support, Unit).

## Dependencies

Depends on: `billium-contracts`, `billium-core`, `billium-platform`.

## Checks

```bash
vendor/bin/pest packages/core/modules/usage/tests --exclude-group=pg
PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 vendor/bin/pest packages/core/modules/usage/tests --group=pg
```

Type index: [docs/source-index/billium-usage.md](../../../../docs/source-index/billium-usage.md). Related: [optional-commercial-modules](../../../../docs/architecture/optional-commercial-modules.md).
