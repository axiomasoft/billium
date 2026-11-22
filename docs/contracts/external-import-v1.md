# External Import Contract (`external.import.v1`)

Billium provides a product-agnostic, self-isolated external import contract under the `external.import.*`
specification namespace (D71, P9.1). Source-side exporters run in the external host and emit immutable,
sanitized export artifacts or streams. Billium adapters read only the supplied artifact/stream and write
strictly to Billium-owned PostgreSQL tables through PHP APIs. Billium never connects to external host
databases and contains no references to specific external products.

## Ownership and Isolation Boundaries

- **PHP sole writer**: All staging, source identity registration, preview evaluation, and economic application
  execute in PHP (`Billium\Platform\Contracts\Import\*` and `Billium\Platform\Internal\Import\*`).
- **Dart codec/contracts slice only**: `billium_contracts` decodes and canonicalizes `external.import.v1`,
  `external.import.manifest.v1`, and `external.import.preview.v1` envelopes against the pinned 2026-10 spec
  revision without owning any write path.
- **No external host DB reads**: `ExternalImportSourceAdapter` is a host-implemented SPI reading an exported
  artifact or stream only.
- **No fabricated monetary facts**: Absent historical evidence is omitted or marked `unanchored_evidence`.
  Billium never substitutes `0`, a default ISO currency, the latest plan/price version, `today`, `capture=true`,
  or a synthetic provider operation ID.

## Wire Envelopes

| Schema | File | Mode | Purpose |
| --- | --- | --- | --- |
| `external.import.v1` | `spec/revisions/2026-10/schema/import/external.v1.json` | Closed | One normalized external import record with source identity, evidence refs and canonical `digest`. |
| `external.import.manifest.v1` | `spec/revisions/2026-10/schema/import/manifest.v1.json` | Closed | Immutable batch manifest binding seller/environment scope, `exportCutoff`, `recordsChecksum`, per-currency historical totals and `sanitizationPolicy`. |
| `external.import.preview.v1` | `spec/revisions/2026-10/schema/import/preview.v1.json` | Open | Typed dry-run validation report classifying records into `ready`, `unanchored_evidence`, `rejected`, `conflict`, and `already_applied`. |

All monetary values use `MoneyWire` (`{"amount": "<fixed-scale decimal string>", "currency": "<ISO 4217>"}`).
JSON `number` is forbidden across all import schemas.

## Source Identity, Revisions, and `sourceFactRef`

Batch IDs, filenames, export revisions, and `importPolicyVersion` are **never** economic identity.

1. **Canonical Source Identity Tuple**:
   `(installationRef, environment, sellerRef, sourceInstallation, sourceBook, sourceRecordId, recordKind)`
   - Canonicalized via RFC 8785 (JCS) and hashed with SHA-256 to form `sourceIdentityDigest`.
   - Seller and environment are part of the primary uniqueness scope; two sellers sharing identical
     `sourceRecordId` values remain strictly isolated.
   - `sourceBook` and `sourceRecordId` are never renamed on mapper or policy updates.
2. **Revision and Payload Digest Semantics**:
   - Same `(sourceIdentity, sourceRecordRevision)` + identical `payloadDigest` -> idempotent replay (`duplicate` / `already_applied`), preserving the original `mappingRef` and `sourceFactRef`.
   - Same `(sourceIdentity, sourceRecordRevision)` + different `payloadDigest` -> deterministic `conflict` (quarantined; original row immutable).
   - Historical corrections create a new higher `sourceRecordRevision` via superseding (or a new correction record referencing `originalBusinessRefs.correctionOfRecordId`), never an in-place overwrite.
3. **Stable `sourceFactRef`**:
   - Derived deterministically from `(sourceIdentityDigest, sourceRecordRevision)` as `import:<sha256>`.
   - Stable across batch retries, artifact repackaging, and `importPolicyVersion` updates.
4. **Tombstones**:
   - Prior to apply, a batch may transition to `rejected`. After apply begins, monetary facts are never deleted.
   - A tombstoned mapping remains durable with `tombstone_reason` and `tombstoned_at`, cannot be deleted or
     un-tombstoned, and blocks silent reactivation of the same source identity.

## Normalized Record Classes

| `recordKind` | Required Evidence | Invariants |
| --- | --- | --- |
| `proven_obligation` | `money` + `frozenPriceSnapshot` | Exact frozen commercial price/tax basis; currency matches `frozenPriceSnapshot.currency`. |
| `proven_invoice_history` | `legalDocument` (`originalIssuer`, `sourceNumber`, `legalOwnershipConfirmed`) | Preserves historical legal number without issuing a second Billium legal number; unconfirmed legal ownership stays historical evidence. |
| `proven_payment_evidence` | `money` + `fundingRefs` | Never repeats PSP execution; preserves original executor/operation binding. |
| `proven_refund_evidence` | `money` + `fundingRefs` (including `originalOperationRef`) | Routes strictly to original charge owner; partial refunds require proven owner and capacity. |
| `proven_allocation` | `money` | Links proven monetary allocation history without duplicating cash facts. |
| `nonfinancial_agreement_reference` | `originalBusinessRefs` | Stores non-monetary agreement/entitlement correlation only; never creates monetary balance. |
| `unanchored_evidence` | Explicit `missingEvidence` | Stored and queryable as explicitly incomplete; never promoted to paid price version, obligation, or payment fact. |

## Preview Report (`external.import.preview.v1`)

`ImportPreviewEvaluator::evaluate()` performs read-only validation and returns:

- **Per-record typed outcomes**:
  - `ready`
  - `unanchored_evidence` (requires non-empty `reasonCodes` and `missingEvidence`)
  - `rejected` (requires `rejectionCode`)
  - `conflict` (requires `existingMappingRef`)
  - `already_applied` (requires `receiptRef`)
- **Isolated Currency Totals (`totalsByCurrency`)**:
  - Grouped strictly by `(sellerRef, currency, recordKind)`.
  - Currencies are never summed together.
  - Every row declares `authoritativeCurrentBalance: false`: historical import totals never become authoritative
    current balances without reconciliation.
- **Diagnostics**: `unexplainedDeltas`, `referencesWithoutEvidence`, `duplicateMappings`, `planPriceMismatches`.

## Platform-Owned PostgreSQL Tables

Migration `2026_11_22_000001_create_billium_import_staging_tables` creates four platform-owned tables
registered in `spec/modules.json`:

1. `billium_import_batch` — immutable manifest digest, `records_checksum`, `export_cutoff`, and guarded lifecycle
   (`staged -> validated -> approved -> applying -> reconciled -> completed`, or `rejected`/`quarantined`; no
   rollback to `rejected` after `applying`).
2. `billium_import_record` — composite FK `(batch_pk, installation_ref, environment, seller_ref)` preventing
   cross-seller staging, nullable `(currency, amount_minor)` pair constraint, immutable payload and identity.
3. `billium_import_mapping` — unique `(installation_ref, environment, seller_ref, source_installation, source_book, source_record_id, record_kind, source_record_revision)`, partial unique active identity index, unique `source_fact_ref`, immutable historical document numbers (`original_issuer`, `original_source_number`), and PG triggers blocking delete, un-tombstone, and silent reactivation of tombstoned identities.
4. `billium_import_receipt` — append-only (`billium_append_only()` trigger) audit trail with a partial unique
   index enforcing at most one `applied` / `observation_queued` receipt per source identity and revision.

## Named Design and Selection Gate (COV-543, COV-565)

- **Verified local contract evidence**:
  - Schema/mapping/handoff checker: `scripts/spec/check-import-contract.py` (`--check`, `--source-mappings`, `--handoff`) and `scripts/spec/tests/test_import_contract.py`.
  - Sanitized positive and negative fixtures: `fixtures/integration/import/schema/**`.
  - Shared PHP/Dart codec vectors: `fixtures/vectors/import/**`.
  - PostgreSQL identity, conflict, tombstone, and preview proof: `packages/core/modules/platform/tests/Import/ImportIdentityTest.php`.
- **Explicitly deferred external inputs (no live readiness claim)**:
  - Production source export manifests, cutoff certificates, owner-approved price/source mappings, and live
    activation certificates are supplied by the source owner at the activation gate. Documentation and local
    fixtures never substitute for external production activation evidence.
