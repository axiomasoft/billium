# Billium implementation plan

Plans live exclusively in the SwissKnifeman workspace, in
`axioma/projects/billium/plans/`. The repository marker `.swissknifeman/project.json`
names the workspace and project and must declare `plans_store: workspace`.
The checkout has no plan store; scripts never fall back to a repository `plans/` directory.

Resolve the workspace plans from the repository root:

```bash
python3 scripts/spec/workspace.py
```

Location precedence: `SKM_WORKSPACES_ROOT`, then `SKM_WORKSPACES`, then
`$SKM_HOME/workspaces`, otherwise `~/.local/share/swissknifeman/workspaces`.
Environment overrides must be absolute directories. The workspace code and project code
always come from the marker. A missing marker, a different store type or a missing workspace
fails explicitly; no files are copied back into the product repository.

Under the resolved directory, `2026.10.14-№1-BILLIUM-V1/plan.json` describes phases P1–P10;
phase/item JSON and `log.jsonl` hold execution state. Its `research/P1-design.md` maps
owner requirements to items. Decisions are in `decisions/D*.json`; development methods
are in `artifacts/P1-methods/methods.json`. The spec linter reads these workspace sources
alongside the product requirements in `docs/requirements.md`.

The legacy P1 acceptance checker and its synthetic regressions are retained in the
workspace's `artifacts/P1-design/` as historical artifacts. They predate workspace migration
and assume repository-local plans; they are not current product validation commands.
Use `bash scripts/ci.sh` for product checks. Its lint layer also tests workspace resolution,
spec generators and lint refusals, including isolation from local and other-project plans.
