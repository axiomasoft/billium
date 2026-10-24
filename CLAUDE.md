<!-- generated from .ai/guidelines/agent-entrypoint.md by scripts/build-agent-docs.sh -->

# Billium

- Billium is a PHP billing system; Dart is the contracts client and SDK,
  never a second money writer.
- Keep PHP and Dart contracts consistent through the shared spec and
  fixtures, not shared code.
- Requirements: `docs/requirements.md`; architecture and data model:
  `docs/architecture/`; contracts v1: `docs/contracts/`; acceptance:
  `docs/acceptance-catalog.md`.
- Stack (PLAN1 D16): PHP 8.4+, Laravel 13, PostgreSQL 16+. No production
  code before P2; layout follows the PLAN1 target structure.
- Document implemented behavior and development commands in `README.md` and
  `docs/`; run the relevant PHP/Dart checks once their tooling exists.
- Preserve unrelated worktree changes. Keep credentials and machine-specific
  agent settings out of Git.
- Plans exist only in the SwissKnifeman workspace named by
  `.swissknifeman/project.json`; resolve them with `python3 scripts/spec/workspace.py`.
  Never read or create a repository-local plan store. See `docs/planning.md`.
- Skills use the swissknifeman connect channel. Project memory is accessed
  explicitly through mAInd in scope `project:billium`; code navigation uses
  Serena with PHP and Dart language servers.
- Edit this context in `.ai/guidelines/agent-entrypoint.md`, then run
  `bash scripts/build-agent-docs.sh` to update `AGENTS.md` and `CLAUDE.md`.

<!-- swissknifeman:hub:start -->
<!-- swissknifeman hub: stub — skills and task-commands are connected natively; index and priority rules are not duplicated here. -->
<!-- swissknifeman:registry sha=4a7f58cd2b84 skills=209 -->
<!-- Full index: `skiller status` or generate-hub.sh --target <dir> --full-index. -->
<!-- swissknifeman:hub:end -->
<!-- maind:healthcheck hash=278951e58880 (managed by maind — do not edit manually) -->
## mAInd — temporary project context and graph

Memory defaults to this project's private scope `project:billium`. Read `ns:*` or `global`
only for an explicit cross-project request and when the scope is authorized.

Store only short useful temporary context: 48-hour default TTL, 72-hour maximum,
800 characters, at most 8 live entries per scope. Use one replaceable `active-handoff`
for continuity, then forget it when work finishes. Keep plans, decisions, progress,
commit history and durable rules in repository artifacts. Empty memory is normal.

Use `maind health --cwd` for connection symptoms or setup changes; repair with
`maind health --cwd --fix`. A memory outage does not block ordinary repository work.
Project connections: `maind graph --project billium`.
<!-- /maind:healthcheck -->
