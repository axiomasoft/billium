# Development environment

Billium uses the AgenTools agent environment with PHP and Dart enabled.
Only the contracts packages exist so far (`packages/contracts`, `packages/contracts-dart`) plus the
core skeleton `packages/core`; domain code, persistence and billing APIs start in P3.

## Toolchain and checks

The host has PHP 8.3 and no Dart SDK, so every check runs in the `toolchain` image (D33): PHP 8.4,
Composer, Dart SDK 3.13.5 and the pinned Python tooling. `compose.yaml` runs it as `UID:GID` of the host user
so files created in the container stay owned by you. PostgreSQL is not needed before P3.

```bash
docker compose build toolchain
docker compose run --rm toolchain composer install --no-interaction
docker compose run --rm toolchain bash scripts/ci.sh                  # full gate
docker compose run --rm toolchain bash scripts/ci.sh --layer <lint|derived|php|dart|conformance>
docker compose run --rm toolchain bash scripts/ci.sh --list           # layers and their subjects
docker compose run --rm toolchain bash scripts/smoke/consumer-install.sh [php|dart]
```

`scripts/ci.sh` is the single carrier of the gate composition and `.github/workflows/ci.yml` calls it unchanged.
A layer without its subject, or a missing tool, is red, never skipped. Regenerate derived files with the
script that `--check` names (for example `python3 scripts/spec/identities.py`).

Identities (`spec/identities.json`, snapshots in `spec/identities/snapshots/`) and `spec/type-map.json` are
frozen to 1.0 by major versions only: an entry that disappears must be kept with status `retired`.
`NoFloatTypeRule` (PHPStan) and `scripts/lint/no-double.py` (Dart) keep `float`/`double` out of money paths;
`packages/contracts/tests/Arch` checks `#[Api]` and the identity snapshots.

The consumer install smoke installs each package by copy into an empty project (no dev dependencies,
no `laravel/framework`) and decodes the pilot `payment.execute.v1`. PB-15 (the previous-minor consumer reads
`payment.outcome.v1`) is proved by the `compat/pilot` vectors in the `conformance` layer for both adapters.

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
There is no application source to index yet.

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
- Application tests are pending application code and manifests.

The current `maind health` output still labels manual memory as
`[FAIL] memory hook MODE=maind (manual)`, even though manual mode is the
selected policy and the connection and deep round-trip pass. The diagnostic
expects digest mode; do not enable a memory digest solely to satisfy that label.

A fresh native Codex plugin selection can create `config.toml` before mAInd
owns its MCP section. On this initial setup, that caused `unmanaged-target`;
the initial plugin-only file was backed up, MCP was deployed first, and the
native selection was reapplied. Run the mAInd sync after native selection to
refresh the managed MCP boundary if it reports `repair-required`.
