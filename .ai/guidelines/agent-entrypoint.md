# Billium

- Billium is a PHP billing system. PHP is the only money writer. Dart
  (`packages/contracts-dart`) is the contracts client and SDK and never
  persists or executes money.
- Keep PHP and Dart contracts consistent through `spec/`, `fixtures/` and
  `conformance/`, not through shared code.
- Read these before editing money code: `docs/requirements.md`,
  `docs/architecture/` (composition, lock order, data model),
  `docs/contracts/`, `docs/operations/execution.md`, and
  `docs/acceptance-catalog.md`.
- Stack (PLAN1 D16): PHP 8.4+, Laravel 13, PostgreSQL 16+. The tree
  implements PLAN1 through P10. Do not open a new phase unless asked.
  SQLite is not a money writer.
- One execution owner per money operation. `unknown` is resolved by lookup
  of the same attempt, not by a second charge. Refunds go to the original
  executor. Dart must not grow a second journal.
- Modules in `packages/core` keep separate tables and writers: platform
  (idempotency, outbox, installation), usage, rating, schedule, ledger,
  documents, execution, collection. The host owns agreements, grants,
  provisioning, tax profiles and PSP credentials. Billium owns the monetary
  schedule, obligations and, when enabled, execution.
- This repository does not check out or read an external planner.
  Decision and method ids in the spec are citations. `REQ`, `INV`, and
  `SRC` resolve from `docs/requirements.md`. See `docs/planning.md`.
  Never create a repository `plans/` store.
- Product checks are `bash scripts/ci.sh` inside the toolchain image.
  Layers, in order, are `lint`, `derived`, `php`, `dart`, `conformance`.
  `lint`, `derived` and `dart` may run together. `php` then `conformance`
  share PostgreSQL and stay ordered. `pg-serial` runs alone after parallel
  `pg` workers (`PACKMAN_TEST_DB_PER_PROCESS=1`). Database names end in
  `_test`. Destructive resets need `PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1`.
  A missing tool or subject is a failure, never a skip.
- `.github/workflows/ci.yml` only calls `bash scripts/ci.sh`. It runs on
  pull requests and on pushes to `main` of the public repository. It does
  not check out another repository for plans.
- Packman generates `.claude/rules/`, `.claude/agents/`, `.codex/agents/`,
  `.gemini/rules/`, `.claude/settings.json`, `.claude/agent-pack.lock.json`
  and `AGENTS.md`. Do not edit those. `vendor/bin/packman ai:sync --dry-run`
  fails if they drift. Billium-specific agent rules are this file.
- Edit this file, then run `bash scripts/build-agent-docs.sh` to refresh
  `CLAUDE.md`. Do not commit `.agents/`, editor hooks, or `.ci-cache/`.
- Code navigation uses Serena (PHP and Dart). Temporary memory is mAInd
  scope `project:billium` only. Do not copy skills into `.agents/skills/`
  or `.claude/skills/`.
- Preserve unrelated worktree changes. Keep credentials and machine-specific
  agent settings out of Git.
