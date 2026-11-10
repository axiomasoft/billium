# P4 implementation evidence

The owner is PLAN1.P4.10. [The manifest](p4-acceptance.json) binds acceptance IDs to exact fixtures,
test methods, focused command outcomes and unsupported feature dispositions. This is implementation
evidence; V1–V7 and the independent phase gate remain pending until the coordinator records their
actual results (D53/D59/D64/D66, REQ-ACC-14/15/18).

Correction evidence retains the conductor's red `checks.1` outcomes for V1/V3/V4/V5/V6 and the
focused results after their fixes. Identity-safe query fixtures, the rating schema-version assertion,
internal event-payload classification, occurrence vector wrapping/control goldens and P4 test typing
were corrected. The manifest records the affected-file checks; it does not promote them to passing
acceptance outcomes before the conductor's recheck (D59; owner PLAN1.P4.10).

`packages/core/tests/Integration/P4AcceptanceReplayTest.php` exercises real PostgreSQL admission,
aggregation, original-period rating, ledger booking and issued-invoice correction. The initial source
quantity is 100. Two late facts, 30 and 20, arrive in both orders. A retained revision replaces 30 with
40 after the first correction. The fixture freezes USD exponent 2, graduated/volume tiers, the October
2026 original period and a host test policy selecting the original 20% exclusive tax rate. These values
are test inputs, not production commercial or jurisdictional defaults (D13/D19/D56/D63; owners
usage/rating/schedule/host).

| Strategy | Original gross minor | At quantity 150 | At quantity 160 | First delta | Revision delta |
|:--|--:|--:|--:|--:|--:|
| Graduated: first 100 at 1.00, remainder at 0.80 | 12000 | 16800 | 17760 | 4800 | 960 |
| Volume: below 150 at 1.00, from 150 at 0.80 for every unit | 12000 | 14400 | 15360 | 2400 | 960 |

The source/rated-basis/obligation counts are exactly `1/1/1`, then `3/2/2`, then `4/3/3`.
Both delivery orders preserve basis identities, predecessor lineage, frozen input digests and effective
totals. Publishing a current price of 999 does not change the original-period correction. Each restore
compares the complete logical backup, including all public table rows and sequence states. A restored
post-correction receipt replays without another financial row. Original issued invoice and occurrence
rows remain byte-identical. Reusing a source identity with another payload refuses before money
(D56/D63, INV-05/11/12/14; owners usage/rating/schedule/ledger).

A separate fixed fee case restores the pre-booking snapshot, reuses the frozen 49.00 price and 20% tax,
changes current price/tax, and still books exactly 5880 minor once. The PHP replay test writes observed
receipts, lineage, final counts, runtime versions and timestamps to
`.phpunit.cache/p4.10/replay-{graduated,volume,fixed}.json`. Focused command logs/results are named
in the manifest. Failed development runs remain separate from final passing evidence
(COV-AC-520, D19/D59; owner PLAN1.P4.10).

The test-only `P4DatabaseSnapshot` captures committed public-table data and identity/sequence state,
then restores them transactionally into the existing schema using the fixture owner. Generated columns
are recomputed. Transaction-local trigger suspension permits restoring retained append-only history;
runtime writers keep their original restricted role. This is a logical data restore test with an installed
schema/grants, not a `pg_dump`, WAL/PITR, infrastructure backup or writer-clone activation qualification
(D59/D60, REQ-ACC-12/18; owners testkit/platform).

The focused PostgreSQL concurrency gate selects existing test methods: ten couriers admit one fact and
receipt; ten conflicting couriers produce one winner and nine conflicts; five booking workers produce
one basis/obligation; rating versus schedule revision preserves the obligated/superseded boundary;
five correction workers create one delta; five schedule workers create one business occurrence.
These processes synchronize through the existing barrier and assert distinct PostgreSQL backend IDs
where the scenario exposes them (REQ-ACC-11, D27; owners usage/rating/schedule/testkit). Exact commands,
exits and timestamps are in the manifest. This is concurrency evidence, not a load measurement.

The feature-disposition rows cover approved trial/no-charge, approved quantity/seat revisions,
discount/coupon refusal, explicit advance versus final usage, package/block refusal, same-scope
minimum/maximum bounds, future-boundary pause/resume and unsupported midperiod/backdate policies,
calendar rules and the trusted extension gate. Positive PHP algorithms and database tests are listed
separately from wire codec fixtures. `advance-codecs.json` proves only explicit fixed/approved-snapshot
codec inputs; advance aggregate usage still refuses. No Dart monetary implementation is implied;
the conductor's conformance gate owns cross-runtime codec evidence (D56/D59; owner PLAN1.P4.10).

The host supplies approved prices, units, precision, currency/exponent versions, rounding/strategy
versions, agreement attribution, immutable quantity snapshots, period/anchor/timezone/DST/timing,
tax snapshots and correction policy publication, source completeness/finality and retention policy.
Grants, quota, reserve/consume, rewards, live user counting and trial/access lifecycle remain with the
host/Tariffler. Negative corrections, monetary customer credit, legal credit notes and PSP refunds are
distinct operations. P4 adds no payment route or operation-binding change (D55/D56/D63,
REQ-OUT-01/02/05/10/15/16, REQ-OWN-20/21; owners host/usage/rating/schedule/ledger/execution).

After V1–V7 actually run, the coordinator copies the product evidence into the plan-local
`artifacts/P4-rating/acceptance.json` and freezes `artifacts/reviews/P4/candidate.json` through
`tools/check_routing_review.py --print-candidate`. It binds genuine native executor session receipts,
head/dirty digest and check evidence. The executor does not mutate plan state, invent actor identity,
set `checks_passed=true` from these focused runs, or independently approve the phase
(D59/D64/D66; owners coordinator/registrar/independent reviewer).
