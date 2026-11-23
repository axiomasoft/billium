# Development environment

Billium uses the AgenTools agent environment with PHP and Dart enabled.
The contracts packages (`packages/contracts`, `packages/contracts-dart`) and P3 platform/ledger in
`packages/core` are implemented. Module lifecycle and authority boundaries follow D55/D60/D62;
the available modules and production qualification requirements are tracked by composition (D55/D59/D61).

## Toolchain and checks

The reproducible check environment is the `toolchain` image (D33/D50): PHP 8.4,
Composer, Dart SDK 3.13.5 and the pinned Python tooling. `compose.yaml` runs it as `UID:GID` of the host user
so files created in the container stay owned by you. Compose includes PostgreSQL 16 with distinct
fixture/migration owner and unprivileged runtime roles (D50/D60); its credentials are throwaway test values.

The product gate does not check out or mount an external planner. See [planning](planning.md).

```bash
mkdir -p .ci-cache/composer .ci-cache/pub
docker compose build toolchain
docker compose run --rm toolchain composer install --no-interaction
docker compose up -d postgres
docker compose run --rm toolchain php scripts/db/preflight.php
docker compose run --rm toolchain bash scripts/ci.sh                  # full gate
docker compose run --rm toolchain bash scripts/ci.sh --layer <lint|derived|php|dart|conformance>
docker compose run --rm toolchain bash scripts/ci.sh --list           # layers and their subjects
docker compose run --rm toolchain bash scripts/smoke/consumer-install.sh [php|dart]
```

`scripts/ci.sh` is the single carrier of the gate composition and `.github/workflows/ci.yml` calls it unchanged.
A full run executes `lint`, `derived` and `dart` concurrently, then `php`, then `conformance`.
Those three do not take the PostgreSQL suite lease. `php` and `conformance` share the cluster, so they stay ordered.
Inside `php`, non-`pg` Pest runs first, then parallel `pg` with one database per worker, then `pg-serial` alone.
A layer without its subject, or a missing tool, is red, never skipped. Regenerate derived files with the
script that `--check` names (for example `python3 scripts/spec/identities.py`).
GitHub Actions runs that same gate once per update: pull requests, and pushes to `main` only.
The job checks Packman out beside the product and runs `bash scripts/ci.sh`.
Composer and Dart downloads land in `.ci-cache/`
(gitignored) and the toolchain image is restored from the Actions cache on the next run.
The php layer runs Packman as `php -d memory_limit=1G vendor/bin/packman gate`, and the same memory limit
for `php-api --check`, `error-codes --check`, `config-schema --check`, and `ai:sync --dry-run`. There is no `bin/packman`.
CI for this gate runs on https://github.com/axiomasoft/billium. A pull request runs the gate once; a push runs it only when the branch is `main`.

Identities (`spec/identities.json`, snapshots in `spec/identities/snapshots/`) and `spec/type-map.json` are
frozen to 1.0 by major versions only: an entry that disappears must be kept with status `retired`.
`NoFloatTypeRule` (PHPStan) and `scripts/lint/no-double.py` (Dart) keep `float`/`double` out of money paths;
`packages/contracts/tests/Arch` checks `#[Api]` and the identity snapshots.

The consumer install smoke installs each package by copy into an empty project (no dev dependencies,
no `laravel/framework`) and decodes the pilot `payment.execute.v1`. PB-15 (the previous-minor consumer reads
`payment.outcome.v1`) is proved by the `compat/pilot` vectors in the `conformance` layer for both adapters.

## PostgreSQL proofs and host commands (D21/D50/D60/D62)

The conformance layer runs the required PHP and Dart HTTP suites against PostgreSQL, the pinned N/N−1 matrix, the archived test consumer installs and the server restore drill. The derived layer checks the HTTP API manifest. These checks apply with the D61 production Dart SDK disposition recorded as skipped. (D59, D61; owners PLAN1.P8.6–P8.8.)

```bash
docker compose run --rm toolchain python3 scripts/spec/check-server-api.py --check
docker compose run --rm toolchain vendor/bin/pest packages/server/tests/Integration/ReadinessTest.php
docker compose run --rm toolchain bash scripts/drills/server-restore.sh
docker compose run --rm toolchain bash scripts/test/server-http.sh --client php --suite required --report build/server-readiness/http-php.json
docker compose run --rm toolchain bash scripts/test/server-http.sh --client dart --suite required --report build/server-readiness/http-dart.json
docker compose run --rm toolchain python3 conformance/compatibility/run.py --matrix conformance/compatibility/matrix.json
docker compose run --rm toolchain bash scripts/smoke/server-consumers.sh
```

See the [server operations runbook](server/operations.md) for probe semantics, access to history during recovery and the production activation evidence (D54, D59, D62; owners server and deployment).

`scripts/db/preflight.php` fails on missing pdo_pgsql, PostgreSQL <16, unsafe runtime privileges,
missing owner/runtime credentials or a database name without `_test`. `scripts/db/prepare-worker-template.php`
migrates the template database `billium_worker_schema_test`. Ordinary tests then `TRUNCATE ... RESTART IDENTITY CASCADE`
and use real runtime transactions. A worker database is `billium_${TEST_TOKEN}_test`; `billium_test_${TEST_TOKEN}` is rejected.
`P4MigrationRebuildTest` and `BilliumSchemaMigratorTest` still migrate an empty database.
RefreshDatabase/DatabaseTransactions are forbidden for these proofs; the suite lease serializes independent test runs against the same database.
Four/six-worker drill processes do not take separate suite leases and are supervised by the owning test.
`scripts/ci.sh` runs `vendor/bin/pest --group=pg --exclude-group=pg-serial --parallel --processes=8`, then `vendor/bin/pest --group=pg-serial`,
with `PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1`. `pg-serial` stays one process because a foreign transaction holds the cluster xmin and breaks feed horizon and prune counts.

```bash
docker compose run --rm toolchain vendor/bin/pest --group ACC-RST-10
docker compose run --rm toolchain vendor/bin/pest packages/core/tests/Feature/RuntimeRoleGrantsTest.php
docker compose run --rm toolchain vendor/bin/pest --group ACC-CON-08,ACC-CON-09,ACC-CON-19
docker compose run --rm toolchain vendor/bin/pest --group D60,D62
```

ACC-RST-10 uses 4 old workers, expand, 2 new workers alongside the old ones, interrupted/restarted
backfill, separate constraint validation and old-code replay. PostgreSQL concurrency and committed rows
are real; application generations and authority admission are test doubles. Contract DROP COLUMN waits
until the rollback window; live deployment/PSP qualification and P10 load measurements remain separate (D59).

In an installed host Laravel application, use these commands (the package repository has no host artisan):

```bash
php artisan billium:installation:init --installation-ref=your-installation
php artisan billium:outbox:relay --limit=100
php artisan billium:outbox:prune
php artisan billium:ledger:drain-parked --limit=100
php artisan billium:ledger:reconcile-refunds --limit=100 --after=3600
```

Init does not open an active financial session; activation/seal need the host authority/session procedure,
not a comparison of commit numbers. Before prune, configure an explicit RET-01
`billium.outbox.retention_floor_seconds` and the maintenance connection; unset floor refuses pruning.
Required subscriptions and live snapshot retention pins also constrain prune. Reconciliation runs after
the consistency window; dependency draining opens a fresh economic root per event (D48/D54/D60).
P3 committed-data tests use PostgreSQL and TRUNCATE isolation (D50).
See [outbox-feed operations](operations/outbox-feed.md), [data model](architecture/data-model.md)
and [lock/invariant proofs](architecture/lock-model.md).

## Integration, import and migration checks (P9 / D58/D59/D63/D71)

Billium is strictly self-isolated: PHP is the sole monetary writer, Dart covers only the shared codec/contracts slice, and external product databases are never accessed directly. `scripts/ci.sh` runs each P9 static check once in `lint` and each runtime conformance/drill suite once in `conformance`; `tests/zones.json` maps P9 docs, manifests, import CLI, consumer contract runner, and migration drills (`p9-integration`) to `lint`, `php`, and `conformance`.

```bash
# Static contract, source mapping, handoff, golden fixture, report validator and readiness manifest checks (lint layer)
docker compose run --rm toolchain python3 scripts/spec/check-import-contract.py --check
docker compose run --rm toolchain python3 scripts/spec/check-import-contract.py --source-mappings docs/migrations/source-mapping.json
docker compose run --rm toolchain python3 scripts/spec/check-import-contract.py --handoff
docker compose run --rm toolchain python3 scripts/spec/check-import-fixtures.py
docker compose run --rm toolchain python3 conformance/consumers/bridge/check-report.py --fixture conformance/consumers/bridge/tests/reports
docker compose run --rm toolchain python3 scripts/spec/check-integration-readiness.py --check artifacts/integration-readiness.json

# Local runtime conformance, loopback consumer suite (CT-01..20) and migration drills (MGATE-01..11) (conformance layer)
docker compose run --rm toolchain env PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 python3 conformance/import/run.py --adapter php --suite all --report build/integration-readiness/import-php-all.json
docker compose run --rm toolchain python3 conformance/import/run.py --adapter dart --suite codec --report build/integration-readiness/import-dart-codec.json
docker compose run --rm toolchain env PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1 bash scripts/test/consumer-contract.sh --boundary loopback --suite all --report build/integration-readiness/consumer-loopback.json
docker compose run --rm toolchain python3 conformance/consumers/bridge/check-report.py --report build/integration-readiness/consumer-loopback.json
docker compose run --rm toolchain bash scripts/drills/integration-migration.sh --suite all --report build/integration-readiness/migration-drills.json
```

Local suites prove `sanitized-fixture-postgresql`, `codec-slice`, `loopback`, and `postgresql-worker-restore-fixture` boundaries with `paidPspTransactions = 0`. They never promote test doubles or loopback runs to `external-runtime` or `provider-live`. See the [integration readiness runbook](operations/integration-readiness.md) and [readiness manifest](../artifacts/integration-readiness.json) for external operator gates, `unanchored_evidence` rules, and the no-automatic-fallback invariant after target write.
