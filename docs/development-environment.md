# Development environment

Billium uses the AgenTools agent environment with PHP and Dart enabled.
The contracts packages (`packages/contracts`, `packages/contracts-dart`) and P3 platform/ledger in
`packages/core` are implemented. Module lifecycle and authority boundaries follow D55/D60/D62;
PSP execution and legal documents are later modules, not qualified live capabilities (D59).

## Toolchain and checks

The reproducible check environment is the `toolchain` image (D33/D50): PHP 8.4,
Composer, Dart SDK 3.13.5 and the pinned Python tooling. `compose.yaml` runs it as `UID:GID` of the host user
so files created in the container stay owned by you. Compose includes PostgreSQL 16 with distinct
fixture/migration owner and unprivileged runtime roles (D50/D60); its credentials are throwaway test values.

Plans exist only in the SwissKnifeman workspace `axioma` (see [planning](planning.md)).
Before running the gate, clone or sync that workspace. Compose mounts the directory containing
workspaces at `/skm-workspaces` read-only and sets `SKM_WORKSPACES` inside the container.
On the host it honors `SKM_WORKSPACES_ROOT`, then `SKM_WORKSPACES`, then
`$SKM_HOME/workspaces`, otherwise `~/.local/share/swissknifeman/workspaces`.
For example, if the workspace checkout is `/srv/workspaces/axioma`, export
`SKM_WORKSPACES=/srv/workspaces`. Missing workspace directories fail instead of being created.

```bash
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
A layer without its subject, or a missing tool, is red, never skipped. Regenerate derived files with the
script that `--check` names (for example `python3 scripts/spec/identities.py`).

GitHub Actions checks out the product into `billium/` and the workspace repository
`academici/swissknifeman-axioma-workspace` into `workspaces/axioma/`, then installs Composer
dependencies before the gate. Configure the repository secret `SKM_WORKSPACE_TOKEN` with read
access to that private workspace repository. The workspace checkout stays separate from the product.

Identities (`spec/identities.json`, snapshots in `spec/identities/snapshots/`) and `spec/type-map.json` are
frozen to 1.0 by major versions only: an entry that disappears must be kept with status `retired`.
`NoFloatTypeRule` (PHPStan) and `scripts/lint/no-double.py` (Dart) keep `float`/`double` out of money paths;
`packages/contracts/tests/Arch` checks `#[Api]` and the identity snapshots.

The consumer install smoke installs each package by copy into an empty project (no dev dependencies,
no `laravel/framework`) and decodes the pilot `payment.execute.v1`. PB-15 (the previous-minor consumer reads
`payment.outcome.v1`) is proved by the `compat/pilot` vectors in the `conformance` layer for both adapters.

## PostgreSQL proofs and host commands (D21/D50/D60/D62)

`scripts/db/preflight.php` fails on missing pdo_pgsql, PostgreSQL <16, unsafe runtime privileges,
missing owner/runtime credentials or a database name without `_test`. Tests migrate with the owner,
then use TRUNCATE isolation and real runtime transactions. RefreshDatabase/DatabaseTransactions are
forbidden for these proofs; the suite lease serializes independent test runs against the same database.
Four/six-worker drill processes do not take separate suite leases and are supervised by the owning test.

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
See [outbox-feed operations](operations/outbox-feed.md), [data model](architecture/data-model.md)
and [lock/invariant proofs](architecture/lock-model.md).

## Skills and providers

`.swissknife.json` is the portable source of provider and skill selection.
The **connect** channel uses the installed swissknifeman release. It enables
`core`, `task`, `architect`, `dart`, `devops`, `mobile`, `php`, `php-packages`,
`quality`, and `system`. Claude Code and Codex use the same selection.
Cursor and Grok receive their supported MCP and lifecycle configurations.
This setup requires local access to swissknifeman; it is not vendor mode.

`AGENTS.md` and `CLAUDE.md` share the entrypoint from
`.ai/guidelines/agent-entrypoint.md`. Regenerate and check it with:

```bash
bash scripts/build-agent-docs.sh
bash scripts/build-agent-docs.sh --check
```

Keep the trailing managed hub and mAInd sections intact.

## Memory and code navigation

The hub registers `billium` in namespaces `axioma` and `billium`.
Memory uses manual MCP access in private scope `project:billium`, with native
Claude auto-memory disabled and no automatic memory digest.
Shared namespaces are not enabled for memory access.

Serena uses both `php` and `dart` language servers, configured in
`.serena/project.yml`. The default PHP-oriented exclusion of `packages/`
was removed for this repository, so future first-party packages can be indexed.
Core platform/ledger source is available for indexing.

MCP provides mAInd, Serena, and the local Perplexity service to Claude Code,
Codex, and Cursor. Grok receives mAInd and Perplexity. Machine-specific paths
and settings are ignored by Git. `.mcp.json.example` contains portable path
placeholders. Providers must trust this project before loading local settings
and executable MCP/hook surfaces.

## Updating and checking

Run from the Billium repository on a machine with the ecosystem CLI installed:

```bash
# Refresh skill distribution; enable the matching native Codex selection.
skiller connect --hub --native-codex

# Refresh generated MCP and managed ecosystem artifacts after skill settings.
maind sync --project billium --only mcp,code-graph,health-doc,ecosystem-skill,setup-skill,gitignore

# Verify the link, including a temporary write/recall/forget round-trip.
maind health --project billium --deep
skiller status
skiller budget --first-turn
maind doctor --gitignore-drift --project billium
```

After changing `.serena/project.yml`, keep the corresponding hub overrides
aligned: `code_languages: [php, dart]`, `code_workspace: false`, and
`code_ignores` without `packages/`. Gitignored files remain excluded from
code navigation.
Machine-local trust is recorded separately from repository configuration.
On another machine, establish provider trust before enabling local settings.

## Setup verification and tooling observations

- All four providers passed the hub trust check.
- The mAInd temporary memory round-trip passed.
- Codex native plugins were enabled and their skills appeared in the offline
  prompt inspection.
- Serena MCP initialization and symbol-navigation tool discovery passed.
- Codex, Cursor, and Grok safe-directory hooks exited successfully on a
  read-only command payload.
- Managed MCP and skills were checked for drift after setup; repeat sync
  reported no changes.
- P3 committed-data tests use PostgreSQL and TRUNCATE isolation (D50).

The current `maind health` output still labels manual memory as
`[FAIL] memory hook MODE=maind (manual)`, even though manual mode is the
selected policy and the connection and deep round-trip pass. The diagnostic
expects digest mode; do not enable a memory digest solely to satisfy that label.

A fresh native Codex plugin selection can create `config.toml` before mAInd
owns its MCP section. On this initial setup, that caused `unmanaged-target`;
the initial plugin-only file was backed up, MCP was deployed first, and the
native selection was reapplied. Run the mAInd sync after native selection to
refresh the managed MCP boundary if it reports `repair-required`.
