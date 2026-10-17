<!-- generated from .ai/guidelines/agent-entrypoint.md by scripts/build-agent-docs.sh -->

# Billium

- Billium is a billing system implemented in PHP and Dart.
- Keep shared billing contracts and behavior consistent across both languages.
- The repository is at its initial setup stage. Frameworks, package layout,
  persistence, and runtime versions have not been selected yet.
- Document implemented behavior and development commands in `README.md` and
  `docs/`; run the relevant PHP/Dart checks once their tooling exists.
- Preserve unrelated worktree changes. Keep credentials and machine-specific
  agent settings out of Git.
- Skills use the swissknifeman connect channel. Project memory is accessed
  explicitly through mAInd in scope `project:billium`; code navigation uses
  Serena with PHP and Dart language servers.
- Edit this context in `.ai/guidelines/agent-entrypoint.md`, then run
  `bash scripts/build-agent-docs.sh` to update `AGENTS.md` and `CLAUDE.md`.

<!-- swissknifeman:hub:start -->
<!-- swissknifeman hub: stub — skills and task-commands are connected natively; index and priority rules are not duplicated here. -->
<!-- swissknifeman:registry sha=273bdbe54ab3 skills=232 -->
<!-- Full index: `skiller status` or generate-hub.sh --target <dir> --full-index. -->
<!-- swissknifeman:hub:end -->
