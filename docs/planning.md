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
phase/item JSON and `log.jsonl` hold execution state. Decisions are in `decisions/D*.json`; development methods
are in `artifacts/P1-methods/methods.json`. The spec linter reads these workspace sources
alongside the product requirements in `docs/requirements.md`.

The 2026-10-24 redesign preserves completed P1/P2 and details the remaining delivery path.
Its accepted decisions start at D53. `knowledge/` contains scoped, self-contained JSON design
sections; `research/2026-10-24-redesign/` holds the baseline export, archived research source,
atomic coverage inventory, model research, review findings and validation evidence. The
implementation inputs do not depend on the repository audit file. The design is summarized in
[architecture coverage](architecture/design-coverage.md); planned checks are distinct from
checks that have actually run.

Use the project launcher to inspect the current next step and validate the plan graph:

```bash
python3 -I .swissknifeman/launcher.py planner next PLAN1 --json
python3 -I .swissknifeman/launcher.py planner get PLAN1 P4
python3 -I .swissknifeman/launcher.py planner context PLAN1 P4.1 --stdout
python3 -I .swissknifeman/launcher.py planner check PLAN1 --json
```

Implementation remains subject to each item's dependencies and checks. Production seller,
provider, fiscal/tax, retention and service-level inputs belong to deployment qualification;
the design does not fabricate them or authorize live charges or publishing.

The legacy P1 acceptance checker and its synthetic regressions are retained in the
workspace's `artifacts/P1-design/` as historical artifacts. They predate workspace migration
and assume repository-local plans; they are not current product validation commands.
Use `bash scripts/ci.sh` for product checks. Its lint layer also tests workspace resolution,
spec generators and lint refusals, including isolation from local and other-project plans.
