# Billium implementation plan

[PLAN1](../plans/2026.10.14-№1-BILLIUM-V1/plan.md) describes phases P1–P10.
P1 is the preparation phase; later phases remain skeletons until its findings are available.
The [design dossier](../plans/2026.10.14-№1-BILLIUM-V1/research/P1-design.md) maps the owner’s
requirements to items and defines the money invariants that the architecture must address.
The [handoff](../plans/2026.10.14-№1-BILLIUM-V1/handoff.md) records the next eligible command.

Run from the repository root with Python 3 (standard library only):

```bash
python3 plans/2026.10.14-№1-BILLIUM-V1/artifacts/P1-design/test-check-p1.py
```

These synthetic regressions validate the acceptance checker, including rejection of missing
scenarios, unresolved evidence and a RED review. They do not prove Billium implementation behavior.

Once P1 artifacts exist, check one item with `check-p1.py P1.1` (or another item ID).
Before writing the final review, use `check-p1.py pre-review` to check P1.1–P1.8.
After writing the verdict, `check-p1.py all` also requires a GREEN P1.9 review.
Missing outputs before execution correctly fail these checks.

The design-only command `build-acceptance-inventory.py --check` verifies the 82-scenario snapshot
against the six local Brain sources. Its default Brain root is the owner’s workspace path;
use `--brain <path>` elsewhere. Source drift requires a recorded design decision before regeneration.
