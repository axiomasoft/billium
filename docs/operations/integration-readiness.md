# Integration, Import, Consumer & Migration Readiness Runbook

Status: `PLAN1.P9.8` (`D17`, `D21`, `D23`, `D25`, `D26`, `D31`, `D55`, `D58`, `D59`, `D60`, `D61`, `D62`, `D63`, `D64`, `D71`).

Machine-readable manifest: [`artifacts/integration-readiness.json`](../../artifacts/integration-readiness.json) (retained plan workspace copy: `@plan/artifacts/P9/integration-readiness.json`).
Validator: `python3 scripts/spec/check-integration-readiness.py --check artifacts/integration-readiness.json`.

## 1. Self-Isolation & Authority Boundaries (D17, D58, D71)

- **Product-agnostic isolation**: Billium owns only its own PostgreSQL tables and public contracts. It contains no external host product names, no foreign business table schemas, and no direct connections to external databases.
- **Writer split**: PHP is the sole monetary writer (`external.import.v1`, ledger, execution, handoff). Dart implements only the shared codec/contracts slice (`--suite codec`) and never writes money state.
- **Immutable execution binding**: Original operation and executor bindings (`execution_binding`, `merchant_account_ref`, `routingEpoch`) are immutable; lookups and refunds permanently route to the original charge owner.
- **Stable economic identity**: Import replay preserves source economic identity and stable `sourceFactRef` across retries, batch repacking, and mapper version updates.
- **No fabricated owner inputs**: Live PSP selection, seller/currency scope, RET-01 retention floor numbers, operational on-call/SLO targets, and backup/PITR policies are never invented in repository manifests; they remain explicit pending activation inputs owned by deployment and product owners.

## 2. Evidence Classes & Workspace Evidence Discipline (D59)

Evidence classes are strictly separated and can never be cross-promoted:

| Evidence class | Boundary | Scope proved | Cannot claim |
| --- | --- | --- | --- |
| `static-contract` | `static` | Spec schemas, source mapping rules, handoff schema/digest rules, golden matrix, sanitization, D71 isolation | Live PostgreSQL or external deployment readiness |
| `import-codec-conformance` | `codec-slice` | Shared PHP/Dart wire decode, canonical JCS digests, negative control vectors | Monetary write authority |
| `import-behavior-conformance` | `sanitized-fixture-postgresql` | PHP sole-writer `IM-01…IM-17` against PostgreSQL 16 and independent JCS golden oracle (`paidPspTransactions = 0`) | Live production import approval or PSP qualification |
| `consumer-contract` | `loopback` | Local real PHP 8.4 + PostgreSQL 16 HTTP loopback producer and reference consumer across `CT-01…CT-20` (`paidPspTransactions = 0`) | `externalReady = true`, `rolloutGatePassed = true`, or live external consumer readiness |
| `consumer-contract` | `double` | Dev-only fault injection (`provider-double`, `host-double`, `transport-contract`) | `localRequiredSuiteComplete`, `externalReady`, or `provider-live` |
| `migration-drill` | `postgresql-worker-restore-fixture` | `MGATE-01…MGATE-11` via `scripts/drills/integration-migration.sh` with real `pg_dump`/`pg_restore` and multi-process workers | Production PITR or live deployment authority |
| `external-activation-input` / `provider-live` | `external-owner-supplied` / `external-runtime` / `provider-live` | Owner-approved production export, host freeze, target reconciliation, authority certificates, independent external consumer report, selected live driver qualification | Substituted by test doubles, loopback reports, or prose |

Actual readiness and drill reports are workspace evidence artifacts referenced via the workspace resolver path and recording `author`, `runtime`, `command`, `exit`, `timestamp`, and `sha256` checksum. Runtime manifests may also be exported to a host, but host-only copies never replace retained workspace evidence.

## 3. Rollout Gate Matrix & D59 Release Isolation

Every gate in [`artifacts/integration-readiness.json`](../../artifacts/integration-readiness.json) carries an explicit state (`passed`, `pending`, `failed`, or `not-applicable`), named `owner`, `scope`, `evidence_class`, `boundary`, hashed `evidence_refs`, and `reason`:

- **Mandatory local suites (`passed`)**:
  - `GATE-LOCAL-IMPORT-CONTRACT` — `scripts/spec/check-import-contract.py` (`--check`, `--source-mappings`, `--handoff`).
  - `GATE-LOCAL-IMPORT-FIXTURES` — `scripts/spec/check-import-fixtures.py` (`IM-01…IM-17` golden matrix, sanitization, D71 isolation).
  - `GATE-LOCAL-IMPORT-PHP` — `conformance/import/run.py --adapter php --suite all` (`IM-01…IM-17`, PHP sole writer, PostgreSQL 16).
  - `GATE-LOCAL-IMPORT-DART-CODEC` — `conformance/import/run.py --adapter dart --suite codec` (Dart codec-only slice).
  - `GATE-LOCAL-CONSUMER-LOOPBACK` — `scripts/test/consumer-contract.sh --boundary loopback --suite all` (`CT-01…CT-20`) + `conformance/consumers/bridge/check-report.py`.
  - `GATE-LOCAL-MIGRATION-DRILLS` — `scripts/drills/integration-migration.sh --suite all` (`MGATE-01…MGATE-11`).
- **Pending external activation gates (`pending`, `active = false`)**:
  - Live migration cutover: `GATE-EXT-SOURCE-EXPORT-APPROVAL`, `GATE-EXT-SOURCE-HOST-FREEZE`, `GATE-EXT-TARGET-RECONCILE`, `GATE-EXT-AUTHORITY-ACTIVATION`.
  - Remote execution & production operations: `GATE-EXT-CONSUMER-RUNTIME`, `GATE-EXT-PSP-QUALIFICATION`, `GATE-EXT-OPS-RETENTION-POLICY`.
- **Composition disposition (`not-applicable`, `active = false`)**:
  - `GATE-COMP-DART-SERVER-SDK` — `not-applicable` solely under accepted composition decision `D61` (no named Dart server consumer). `not-applicable` is never used to skip a failing or unimplemented local test.
- **Independent phase closure (`pending`, `active = false`)**:
  - `GATE-PHASE-P9-INDEPENDENT-REVIEW` — independent read-only phase review (`PLAN1.P9.9`) on the frozen candidate (`D64`/`D71`).

**D59 minimal embedded release rule**: Pending external gates isolate **only** their affected activation (`live-migration-cutover` or `remote-billium-execution`). A minimal embedded `ledger-only` release remains permissible while remote execution gates are `pending`, provided the release manifest keeps `execution_enabled = false`, `outgoing_default = false`, and `remote_routes_effect_capable = false` (zero effect-capable execution routes or workers enabled).

## 4. Source Operator, Target Operator & Activation Gate Matrix

Live migration and remote execution rollout require completing four sequential operator gates outside Billium's local test doubles:

| Stage | Gate IDs | Owner | Mandatory Artifacts & Verification | Blocking Condition |
| --- | --- | --- | --- | --- |
| 1. Source export & commercial mapping | `GATE-EXT-SOURCE-EXPORT-APPROVAL` | Product owner & external source exporter | Owner-approved scope, seller/currency list, frozen commercial price/source mapping (`source.export.v1` -> `external.import.v1`), explicit RFC 3339 UTC `exportCutoff`, sanitized artifact (`piiRedacted = true`, `secretsStripped = true`, zero exported credentials), isolated per-currency totals, immutable JCS checksums. | Missing cutoff, missing frozen price basis, unsanitized payload, exported credentials, or unapproved mapping. |
| 2. Source host freeze & retention pin | `GATE-EXT-SOURCE-HOST-FREEZE` | Source deployment operator | Close every source mutator (UI, API, cron, collection, import, admin, background, direct permitted writers), seal financial admission, drain in-flight root Tx, issue `SourceFreezeCertificate` bound to source OwnerSession/epoch/seal receipt, reserve D52 retention lease before snapshot, export immutable `snapshotToken`/`snapshotDigest` + safe `(tx_id, id)` cursor + `MigrationManifest`. | Open mutator, missing/expired freeze or retention lease, missing `selectionDigest`, or reliance on diagnostic `commit_no`. |
| 3. Target reconcile & atomic adoption | `GATE-EXT-TARGET-RECONCILE` | Target deployment operator | Stage target in recovery under a separate OwnerSession, verify snapshot and retained tail bytes, replay via owning APIs (zero PSP calls, rerating, or duplicate cash export), record durable item dispositions, verify per-seller/currency counts, exact minor-unit totals and digests, adopt cursor atomically with reconciliation receipt. | Tampered bytes, expired pin, incomplete tail, mismatched selection/lineage, or any per-seller/currency variance. |
| 4. Privileged activation & live operations | `GATE-EXT-AUTHORITY-ACTIVATION`, `GATE-EXT-CONSUMER-RUNTIME`, `GATE-EXT-PSP-QUALIFICATION`, `GATE-EXT-OPS-RETENTION-POLICY` | Deployment authority, operations & provider owners | Separate privileged `activate` requiring `RetirementProof` (predecessor) + unexpired current-lineage `ActivationCertificate` + unresolved effect/namespace/feed dispositions. For remote execution, additionally supply: independent external consumer `external-runtime` conformance report, selected live `PaymentDrivers` qualification, named on-call/SLO, RET-01 retention floor & backup/PITR policy, lost-response/restore/clone drills, and credential rotation + refund lookup horizon. | Missing/stale certificate, active predecessor writer, uncertified external consumer, unqualified driver, or undefined operational owner/retention policy. |

See [`docs/migrations/source-export-contract.md`](../migrations/source-export-contract.md), [`docs/migrations/import-operations.md`](../migrations/import-operations.md), [`docs/migrations/authority-handoff.md`](../migrations/authority-handoff.md), and [`docs/migrations/restore-upgrade-drills.md`](../migrations/restore-upgrade-drills.md) for detailed command procedures.

## 5. Generic External Consumer Actual Evidence Import Contract

External consumers and bridges live outside Billium and prove integration readiness by submitting a hashed conformance report conforming to `billium.consumer.contract.report.v1` (`fixtures/integration/consumer/schema/report.json`):

```bash
python3 conformance/consumers/bridge/check-report.py \
  --report /path/to/external-consumer-report.json \
  --require-external-ready
```

Acceptance requirements enforced fail-closed by `check-report.py`:

1. `protocol == "billium.consumer.contract.report.v1"`, `evidence_class == "consumer-contract"`, `boundary == "external-runtime"`.
2. `status == "passed"`, `passed == true`, `localRequiredSuiteComplete == true`, `externalReady == true`, `rolloutGatePassed == true`.
3. `consumer.available == true`, `consumer.deliveryProfile == "external-http"`, valid `commitHash`/`packageHash`/`specHash` on both producer and consumer, `consumer.packageHash != producer.packageHash`, and `specHash` matching `spec/revisions/2026-10/revision.lock.json`.
4. Exact coverage of all 20 cases `CT-01…CT-20` with zero unknown/duplicate/omitted cases, `paidPspTransactions == 0`, valid canonical JCS `reportDigest`, and zero D71 forbidden terms.
5. Reading HTTP examples, docs, or source code never counts as consumer acceptance. When no authorized external consumer deployment is available, the runner emits `status = "pending"`, `externalReady = false`, and `rolloutGatePassed = false`, isolating only the affected remote activation.

## 6. Hard Operational Limitations & Retention Guarantees

### `unanchored_evidence` Limitations
- Records classified as `unanchored_evidence` (missing frozen price basis, missing currency, missing authoritative payment/provider refs, mutable subscriptions without frozen occurrences, or non-monetary credits/rewards claimed as money) may be stored and read as explicitly incomplete history with `reasonCodes` and `missingEvidence`.
- They **cannot** be promoted to a paid `PriceVersion`, occurrence coverage, payment fact, monetary balance, synthetic obligation, or collection eligibility (`collectionAllowed = false`).
- External hosts establish new commercial terms and billing occurrences **prospectively only**; historical gaps are never retroactively fabricated from current plans or document display prices.
- Partial refunds require proven original owner, amount, currency, payment refs, and capacity; otherwise the case requires explicit operator intervention, never an estimated refund.

### No Automatic Fallback After Target Write
- **Before the target's first monetary commit or external effect** (counting recovery apply as a target write): aborting handoff requires fencing and draining the target, obtaining authenticated proof of zero target commits/effects and inability to reacquire admission, and resuming the source only via explicit authority retirement and lineage activation in a new epoch. Source release never silently auto-reopens a writer.
- **After the target's first monetary commit or external effect**: automatic fallback to the old source is **strictly forbidden**. Operators must either continue target recovery or execute a separately certified reverse handoff.
- Original execution bindings (`execution_binding`) never mutate across cutover, rollback, or reverse handoff. Restored or cloned attachments remain quarantined regardless of whether diagnostic `commit_no` is lower, equal, or greater than any prior counter.

### Retention & Anti-Truncation Rules
- Source identity mappings (`billium_import_mapping`), tombstones, import receipts (`billium_import_receipt`), operation revisions, provider evidence, and original execution bindings are retained **separately from the outbox delivery queue**.
- Completing an import or disconnecting a client/SDK never authorizes broad truncation. Retention pruning can never erase the sole proof required to reject a duplicate command or route a historical refund.
- Schema rollback or worker code downgrade never authorizes deleting or replaying historical monetary facts; module disable/retirement checks must keep historical mapping and binding readers intact.

## 7. Phase Closure & P10 Handoff Boundary (D64)

- `PLAN1.P9.8` closes P9 implementation evidence, CI integration, and the readiness manifest; it does **not** self-approve phase P9.
- Independent read-only phase acceptance runs in `PLAN1.P9.9` against the frozen candidate (`@plan/artifacts/reviews/P9/candidate.json`).
- `P10` consumes hashed local runtime evidence, verified manifests, and the explicit limitations recorded in `artifacts/integration-readiness.json` — never unreviewed prose.
