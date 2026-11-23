# Billium implementation plan

This repository does not check out, mount, or read an external planner.
Decision ids (`D<number>`) and method ids (`M-NN`) in the spec are citations.
`REQ`, `INV`, and `SRC` references resolve from `docs/requirements.md`.
The product gate is `bash scripts/ci.sh`. There is no repository `plans/` store.

Phases P1–P10 are implemented in this tree. Production seller, provider,
fiscal/tax, retention, and service-level inputs belong to deployment
qualification. The design does not fabricate them or authorize live charges
or publishing.

Planned checks are distinct from checks that have actually run. The design
summary is [architecture coverage](architecture/design-coverage.md).
