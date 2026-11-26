# Changelog

All notable changes to Billium are documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.1.0-rc.1] - 2026-11-22

Release candidate for Billium v1 (PHP 8.4+ / Laravel 13 / PostgreSQL 16+ core and server; Dart client/codec SDK).

### Added

- **Contracts & Cross-Language Conformance (`billium-contracts` PHP, `billium_contracts` Dart)**
  - Spec revision `2026-10` JSON schemas, versioned envelopes, RFC 8785 JCS canonicalization oracle, integer minor-unit `Money` / `MoneyMath` (`brick/money` in PHP, `BigInt` in Dart, enforced zero-float / `no-double`), and typed problem codes.
  - Shared vector/golden conformance runner (`conformance/runner/run.py`) and compatibility archive checks.
- **Core Platform & Ledger (`axiomasoft/billium-core`)**
  - Guarded PostgreSQL 16 routines, financial-session admission, transactional outbox, feed subscriptions/cursor tracking, idempotency receipts, obligations, invoices, allocations, credit notes, and authenticated ledger queries.
  - Strict database lock ordering, cross-seller composite FK isolation, and runtime role grants.
- **Commercial, Documents, Execution & Collection Modules**
  - Optional `usage`, `rating`, and `schedule` modules with immutable price versions, calendar proration, and correction children.
  - Optional `documents` module with seller-scoped atomic numbering, outside-Tx `TaxSnapshotPort` finalization, async rendering (`DocumentRenderer` / `DocumentStorage`), credit notes, independent fiscal lifecycle, and replay-safe accounting facts (`AccountingFactMapper`).
  - `execution` module with fenced `WriterAdmission`, provider custody (`provider_evidence`), `unknown`-outcome lookup recovery, callback authenticity/quarantine, and refund capacity conservation.
  - Optional `collection` module with versioned `ConsentBinding`, append-only `DunningPolicy`, separate delivery vs business-decline retry budgets, host signals, and audited manual repair ports.
- **Server Profile & External Import / Authority Handoff (`axiomasoft/billium-server`)**
  - Dedicated HTTP/JSON server profile with readiness/doctor checks, metrics, authenticated queries, and restore drills.
  - Product-agnostic `external.import.v1` and `source.export.v1` contracts, `ImportBatchApplier`, `AuthorityRouter`, `AuthorityHandoff`, loopback consumer contract suite (`CT-01…CT-20`), and migration/restore drills (`MGATE-01…MGATE-11`).
- **Release Readiness & Quality Gates (P10.1–P10.8)**
  - Profile matrix smoke (`scripts/smoke/profile-matrix.sh`) across `contract-only`, `embedded`, `standalone`, and `remote`.
  - Seeded fault-injection and independent conservation verification (`BillingFaultSuiteTest`).
  - PostgreSQL `EXPLAIN (FORMAT JSON)` hot query-path checks (`scripts/bench/billing.py`, `BillingQueryPathsTest`).
  - Restore, retention, and rolling-upgrade drills (`scripts/drills/billing-restore.sh`, `scripts/drills/billing-upgrade.sh`).
  - Security, privacy, redaction, and license/hold supply-chain gate (`scripts/quality/release-supply-chain.sh`, `docs/third-party-notices.md`).
  - Deployment profile and provider qualification schema/validator (`spec/deployment-profile.schema.json`, `scripts/quality/deployment-profile.py`).
  - Local release-candidate validation runner (`scripts/quality/release-candidate.sh --local-only`) with unresolved-hold rejection, zero-test acceptance rejection, and MECH-02 empty-filter proof.

### Notes & Deployment Gates

- **Live execution activation** (`remote-live` / `--require-live`) remains fail-closed until a `provider_verified` driver qualification dossier, egress quiesce runbook, retention/legal-hold policy, SLO/RPO/RTO, and operational owner are provided with zero unresolved holds.
- **Dart SDK** (`billium_contracts`) is strictly a codec and client library; PHP (`billium-core`) is the sole monetary writer.
