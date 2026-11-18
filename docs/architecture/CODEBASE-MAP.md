# Codebase map

Start here to find the right place before opening code. Each package README lists its entry points; the
generated [source index](../source-index/README.md) lists every PHP type with a one-line summary.

## Top level

| Path | What it is |
|---|---|
| `packages/contracts` | `axiomasoft/billium-contracts` (PHP): money, codec, envelopes, problem codes. Partly generated from `spec/`. |
| `packages/contracts-dart` | Dart client of the same contract (`BigInt` money, no writer). Generated parts from `spec/`. |
| `packages/core` | Laravel composition root: operation runner, module catalog, runtime contracts, configuration, migrations. |
| `packages/core/modules/<name>` | Business modules: `platform`, `ledger`, `usage`, `schedule`, `rating`, `execution`. |
| `packages/testkit` | Dev-only deterministic provider/authority fixtures and oracles for execution tests. |
| `spec/` | Normative contract spec: revision `spec/revisions/2026-10` (rule documents, JSON Schema, problem codes), identities, module registry, type map. |
| `fixtures/` | Language-neutral vectors and golden files. |
| `conformance/` | Runner, JCS oracle and PHP/Dart adapters that replay `fixtures/` through both languages. |
| `scripts/` | `ci.sh` gate; `spec/` generators and linters; `lint/` repository rules; `db/` preflight; `smoke/`, `drills/`; `phpstan/` custom rules; `docs/` source-index generator. |
| `docs/` | Requirements, architecture, contracts and operations guides. |
| `tests/` | Root Pest bootstrap and `zones.json` (path → CI layer mapping for `ci.sh --affected`). |
| `docker/`, `compose.yaml` | `toolchain` image and PostgreSQL 16 service used by every check. |
| `packman.json` | Package list, namespaces and layers; drives `packman gate` and the source index. |

## Packages and dependency direction

Allowed Billium imports per package (from `composer.json`; the generated index shows the actual graph):

| Package | May import |
|---|---|
| contracts | — |
| core | contracts; every module, as the composition root (`src/Plugins`, service provider, runtime wiring) |
| platform | contracts, core |
| schedule | contracts, core |
| usage | contracts, core, platform |
| ledger | contracts, core, platform |
| execution | contracts, core, platform, ledger |
| rating | contracts, core, platform, ledger, usage, schedule |
| testkit (dev) | contracts, core, platform, execution |

`rating` composes with `usage`, `schedule` and `ledger` as optional capabilities
(see [optional modules](optional-commercial-modules.md), [compatibility](module-compatibility.md)).
Runtime packages never import `testkit`.

Inside a module, `Contracts`, `Data`, `Enums` and `Exceptions` are the public surface (`#[Api]` for consumers,
`#[Spi]` for host extension points); `Internal` is never imported by another module; `Laravel` holds the service
provider and capability declaration. Each table has exactly one owning module and modules never read or write a
neighbour's tables. These rules are tests, not conventions:
`packages/core/tests/Arch/ModuleBoundariesTest.php`, `TableOwnershipTest.php`, `ModuleDependencyDirectionTest.php`
(imports match `composer.json`, no cycles, rating calls neighbours only through `Internal/Adapters`),
`packages/core/tests/Architecture/P4DependencyBoundaryTest.php`.

## Where to look

| Task | Look in |
|---|---|
| Root transaction, locks, idempotency order | `packages/core/src/Internal/Runner`, `packages/core/docs/transaction-boundaries.md`, [lock model](lock-model.md) |
| Table or SQL routine | `packages/core/database/migrations/<module>/`, [data model](data-model.md) |
| Ledger command (invoice, allocation, credit note) | `packages/core/modules/ledger/src/Internal/Commands` and the matching migration routine |
| Payment observation intake | `ledger/src/Contracts/ObservationApplier.php`, `ledger/src/Internal/Observation` |
| Outbox, relay, feed, inbox | `platform/src/Internal/{Outbox,Feed,Inbox}`, [outbox-feed guide](../operations/outbox-feed.md) |
| Provider dispatch, callbacks, refunds | `execution/src/Internal/*`, [execution guide](../operations/execution.md) |
| Pricing, proration, tax corrections | `rating/src/Internal/{Strategies,Proration,Tax,Corrections}` |
| Usage ingestion and aggregation | `usage/src/Internal/{Storage,Window,Reducers}` |
| Wire format, problem codes | `spec/revisions/2026-10/*.md`, [contracts v1](../contracts/billium-contracts-v1.md), [problem codes](../contracts/problem-codes.md) |
| Configuration keys | `packages/core/src/Configuration`, `packages/core/config/billium.php` |
| Module enable/disable lifecycle | `packages/core/src/Internal/Modules`, [module lifecycle](../operations/module-lifecycle.md) |
| Requirement or decision IDs (REQ-, INV-, D#) | [requirements](../requirements.md), [target architecture](target-architecture.md), [design coverage](design-coverage.md) |

Module paths above are relative to `packages/core/modules/`.

## Generated files

Never edit these by hand; regenerate and commit. The `derived` CI layer runs each generator with `--check`.

| Output | Generator |
|---|---|
| Generated parts of `packages/contracts`, `packages/contracts-dart` | `scripts/spec/export-php.py`, `export-dart.py` |
| `packages/core/resources/modules.json` | `scripts/spec/export-modules.py` |
| `spec/revisions/*/rules.json`, `revision.lock.json`, `fixtures/golden` | `build-rules.py`, `build-lock.py`, `build-golden.py` |
| `spec/identities*`, `spec/type-map.json`, `docs/contracts/problem-codes.md` | `identities.py`, `type-map.py`, `build-codes-index.py` |
| `packages/core/resources/contracts/{php-api,error-codes}.json` | `vendor/bin/packman php-api`, `error-codes` |
| `docs/source-index/` | `scripts/docs/source-index.py` |

## Running checks

Everything runs in the toolchain image (`docker compose run --rm toolchain …`) or on a host with PHP 8.4
(`bin/php84`), PostgreSQL and the sibling `../packman` checkout. Prefer the narrowest command:

```bash
vendor/bin/pint --test <paths>                       # style
vendor/bin/phpstan analyse --memory-limit=1G         # types (level max, NoFloatTypeRule)
vendor/bin/rector process --dry-run                  # refactoring rules
vendor/bin/pest <package>/tests --exclude-group=pg   # unit/feature tests of one package
PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 vendor/bin/pest <package>/tests --group=pg   # PostgreSQL tests
bash scripts/ci.sh --affected origin/main...HEAD     # which gate layers a change needs
bash scripts/ci.sh --layer derived                   # one gate layer
```

Plans and phase state live outside the repository; see [planning](../planning.md).
