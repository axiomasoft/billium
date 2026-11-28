# Billium

Billing engine and contracts for the Axioma ecosystem, implemented in **PHP 8.4+** (Laravel 13, PostgreSQL 16+).
**Dart** (`billium_contracts`) is the shared contracts client and codec SDK: it builds commands and reads outcomes, and never writes money state.

Billium is strictly self-isolated and product-agnostic (D71): all monetary state is owned inside Billium's PostgreSQL schema via guarded routines, integer minor-unit arithmetic (`brick/money` / `BigInt`, zero floats), RFC 8785 JCS canonical digests, and explicit extension ports.

## Profiles and Quickstart

Delivery (`server` HTTP/JSON vs in-process Laravel) and monetary authority (`execution` / `WriterAdmission`) are orthogonal (D61). Choose one of four profiles:

| Profile | Packages | Required Modules | Optional Modules | `execution` / `WriterAdmission` | Use Case |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `contract-only` | `axiomasoft/billium-contracts` (PHP) or `billium_contracts` (Dart) | `contracts` | none | forbidden | Shared money types, JCS digests, envelopes, problem codes; no core, DB, or provider SDKs |
| `embedded` (`ledger-only`) | `billium-contracts` + `billium-core` | `platform`, `ledger` | `usage`, `rating`, `schedule`, `documents` | forbidden (0 provider calls) | In-process host ledger, invoices, allocations, observations, and outbox/feed |
| `standalone` | `billium-contracts` + `billium-core` | `platform`, `ledger` | `usage`, `rating`, `schedule`, `documents`, `execution`, `collection` | optional (when `execution` enabled) | Full in-process billing in a Laravel host without a separate HTTP server |
| `remote` | `billium-contracts` + `billium-core` + `billium-server` | `platform`, `ledger`, `execution`, `server` | `usage`, `rating`, `schedule`, `documents`, `collection` | required (hard-fenced) | Dedicated HTTP/JSON billing service with fenced writer authority |

See [module composition matrix](docs/architecture/module-composition.md) and [optional commercial modules](docs/architecture/optional-commercial-modules.md) for DAG rules and lifecycle retirement (`accepting -> draining -> recovery_only -> retired`, preserving `historical_read` and `receipt_replay`).

### Quickstart Commands

All verification runs inside the `toolchain` Docker image (PHP 8.4, Composer, Dart SDK, Python 3) against PostgreSQL 16. Check out `axiomasoft/packman` at `v0.1.1` in `../packman` for Composer path dependencies.

```bash
# 1. Build toolchain, install dependencies, start PostgreSQL
docker compose build toolchain
docker compose run --rm toolchain composer install --no-interaction
docker compose up -d postgres
docker compose run --rm toolchain php scripts/db/preflight.php

# 2. Profile smoke & release-candidate verification (local-only, 0 network calls)
docker compose run --rm -u 0:0 toolchain bash scripts/smoke/profile-matrix.sh
docker compose run --rm -u 0:0 toolchain bash scripts/quality/release-candidate.sh --local-only
docker compose run --rm -u 0:0 toolchain bash scripts/quality/release-supply-chain.sh
docker compose run --rm -u 0:0 toolchain python3 scripts/quality/deployment-profile.py path/to/manifest.json --json

# 3. Recovery, upgrade, and query-path drills
docker compose run --rm -u 0:0 toolchain bash scripts/drills/billing-restore.sh --fake-provider
docker compose run --rm -u 0:0 toolchain bash scripts/drills/billing-upgrade.sh
docker compose run --rm -u 0:0 toolchain python3 scripts/bench/billing.py --profile acceptance --output -

# 4. Full CI gate (lint, derived, php, dart, conformance)
docker compose run --rm -u 0:0 toolchain bash scripts/ci.sh
docker compose run --rm -u 0:0 toolchain bash scripts/ci.sh --layer php
docker compose run --rm -u 0:0 toolchain bash scripts/ci.sh --list
```

Host Laravel Artisan commands: `billium:installation:init` (does not activate a financial session), `billium:outbox:relay`, `billium:outbox:prune` (requires explicit RET-01 floor), `billium:ledger:drain-parked`, and `billium:ledger:reconcile-refunds`.

## Extension Ports

Hosts integrate with Billium through typed, versioned ports rather than direct table writes:

- **Execution & Authority**: `PaymentDrivers` (PSP adapter with declared capabilities and qualification manifest), `WriterAdmission` (fenced lease/epoch authority for outgoing dispatch).
- **Documents & Tax**: `TaxSnapshotPort` (called outside root Tx with CAS `input_digest`), `DocumentRenderer`, `DocumentStorage` (async rendering and blob custody). Disabling `documents` (`billium.documents.enabled = false`) keeps `ledger` operational with zero document ports required.
- **Collection**: `CollectionConsentGuard` (out-of-Tx host consent verification), `CollectionNotificationPort` (durable outbox notifications; transport ACK never alters payment state).
- **Accounting & External Import**: `AccountingExportExtension` (replay-safe economic facts, zero GL ownership), `external.import.v1` / `source.export.v1` (`scripts/import/billium-import.php` for product-agnostic historical import and authority handoff).

## Error Codes and `unknown` Outcome Recovery

Billium never treats an uncertain provider call as a terminal failure:

- **`unknown` outcome**: On network timeout or crash after provider submission, the operation stays `unknown` (never auto-retried as a second charge). Capacity remains reserved until `lookup` or an authenticated callback resolves the terminal outcome.
- **Conflicting or forged callbacks**: Duplicate callbacks are idempotent; conflicting digests or unverified signatures are quarantined (`discrepancy`) while protecting any already confirmed terminal state.
- **Key problem codes**:
  - `billium.idempotency.conflict` — same `commandKey` with a different canonical digest (deterministic reject).
  - `billium.key.expired` — replay of a key whose namespace/tombstone horizon has closed.
  - `billium.recovery.required` — missing readiness evidence, restore clone detected, or feed cursor behind applied state.
  - `billium.installation.quarantined` — writer lease lost or clone attempted dispatch; outgoing sends stay blocked (`0` calls) until explicit operator reconciliation and certified session activation (`openSession` -> `activateSession`).
  - `billium.auth.denied` — cross-seller or unauthorized command/query rejected without existence oracle.

## Module Support and Deployment Gates

- **Optional modules**: `usage`, `rating`, `schedule`, `documents`, `execution`, and `collection` are independently enabled via `billium.modules.enabled` and feature flags. Omitting `execution` guarantees zero outbound provider calls.
- **Simulated vs Live qualification**: Automated test suites use deterministic test doubles (`QualificationMode::Simulated`). Live production activation (`remote-live` / `--require-live`) is fail-closed in both `scripts/quality/deployment-profile.py` and runtime `ExecutionReadinessGuard` until a `provider_verified` qualification dossier, historical lookup/refund support, egress quiesce runbook, retention/legal-hold policy, SLO/RPO/RTO, and all six `evidenceRefs` are supplied with zero unresolved holds or gaps.
- **MECH-02 zero-test enforcement**: Both `scripts/ci.sh` (`scripts/lint/test-discovery.py`) and `scripts/quality/release-candidate.sh` reject empty test selections and zero-test acceptance evidence.

## Documentation Map

- Architecture & data model: [CODEBASE-MAP](docs/architecture/CODEBASE-MAP.md), [target-architecture](docs/architecture/target-architecture.md), [data-model](docs/architecture/data-model.md), [lock-model](docs/architecture/lock-model.md), [module-composition](docs/architecture/module-composition.md), [documents](docs/architecture/documents.md), [fiscal-boundary](docs/architecture/fiscal-boundary.md)
- Contracts & SDKs: [billium-contracts-v1](docs/contracts/billium-contracts-v1.md), [accounting-facts](docs/contracts/accounting-facts.md), [ledger-queries](docs/contracts/ledger-queries.md), [external-import-v1](docs/contracts/external-import-v1.md), [consumer-contract-kit](docs/contracts/consumer-contract-kit.md), [PHP contracts](packages/contracts/README.md), [Dart contracts](packages/contracts-dart/README.md)
- Operations & readiness: [server operations](docs/server/operations.md), [outbox-feed](docs/operations/outbox-feed.md), [collection](docs/operations/collection.md), [performance](docs/operations/performance.md), [PostgreSQL deployment](docs/operations/postgresql-deployment.md), [CI](docs/operations/ci.md), [0.1 database audit](docs/audit/v0.1-db-proposals.md), [restore](docs/operations/restore.md), [retention](docs/operations/retention.md), [security](docs/operations/security.md), [deployment-profile](docs/operations/deployment-profile.md), [integration-readiness](docs/operations/integration-readiness.md), [third-party-notices](docs/third-party-notices.md)
- Acceptance & traceability: [acceptance-catalog](docs/acceptance-catalog.md), [requirements](docs/requirements.md), [development-environment](docs/development-environment.md), [CHANGELOG](CHANGELOG.md)
