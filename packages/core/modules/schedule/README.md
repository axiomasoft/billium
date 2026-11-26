# axiomasoft/billium-schedule

Commercial period resolution, occurrences and billing schedule (agreement) revisions with microsecond-exact identities.

## Entry points

Paths are relative to `src/`. Public types carry `#[Api]`, host extension points `#[Spi]`.

- `Contracts/CommercialPeriods.php` — period resolution
- `Contracts/IntervalResolver.php` — calendar interval rules
- `Contracts/OccurrenceService.php` — occurrence creation and identity
- `Contracts/AgreementRevisionCommit.php` — agreement revision commits
- `Contracts/ScheduleQueries.php` — read side

## Layout

- `src/Contracts`, `src/Data`, `src/Enums` — public surface; other modules may import only these.
- `src/Internal` — implementation; never imported from another module (enforced by `packages/core/tests/Arch/ModuleBoundariesTest.php`).
- `src/Laravel` — service provider and capability declaration; core composes it through `Billium\Core\Plugins`.
- Migrations: `packages/core/database/migrations/schedule/` (raw SQL in PHP migrations, forward-only).
- Tests: `tests/` (Feature, Support, Unit).

## Dependencies

Depends on: `billium-contracts`, `billium-core`.

## Checks

```bash
vendor/bin/pest packages/core/modules/schedule/tests --exclude-group=pg
PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 vendor/bin/pest packages/core/modules/schedule/tests --group=pg
```

Type index: [docs/source-index/billium-schedule.md](../../../../docs/source-index/billium-schedule.md). Related: [optional-commercial-modules](../../../../docs/architecture/optional-commercial-modules.md).
