# External Import Operations Guide (`external.import.v1`)

This runbook documents how Billium previews, applies, resumes, reconciles, and corrects sanitized external export artifacts under the product-agnostic `external.import.v1` contract.

## 1. Self-Isolation & Authority Guardrails

1. **Product-Agnostic Boundary**: Billium reads immutable local export artifacts or streams (`source.export.v1` normalized into `external.import.v1`). It never connects to external host databases, never imports external product domain models, and never accepts exported source credentials as monetary authority.
2. **Read-Only vs Mutating Operations**:
   - `preview` (`ImportPreviewEvaluator`) and `reconcile` (`ImportReconciler`) are strictly read-only: zero database money mutations, zero outbox emissions, and zero payment provider or accounting dispatches.
   - `apply` and `resume` (`ImportBatchApplier`) require:
     - Explicit capability scope `import:apply`.
     - Exact match between the operator-approved `expectedManifestDigest` and the batch's immutable `manifestDigest`.
     - Authenticated `Caller` matching `(installationRef, environment, sellerRef)` and holding a grant for the configured migration `sourceAuthority` whenever payment or refund observations are present.

## 2. Batch Lifecycle State Machine

`billium_import_batch` enforces forward-only state transitions at the PostgreSQL trigger level:

```text
staged -> validated -> approved -> applying -> reconciled -> completed
  |          |           |            |            |
  +----------+-----------+            +------------+---> quarantined (conflict or discrepancy)
             |
             v
          rejected (pre-apply only)
```

- **`rejected`** is permitted only prior to `applying` (`staged`, `validated`, or `approved`). Once `applying` begins, batches are never rolled back destructively or deleted.
- **`quarantined`** halts progression when a source identity/revision arrives with a conflicting `payloadDigest`, a tombstoned mapping is re-imported, a refund contradicts its original charge's currency/account/capacity, or per-currency reconciliation detects an unexplained variance.

## 3. Dependency-Ordered Apply DAG & Two-Root Observation Protocol

`ImportBatchApplier` processes staged records in deterministic dependency order across configurable chunks:

1. **Non-Monetary & Unanchored Evidence (`nonfinancial_agreement_reference`, `unanchored_evidence`)**:
   - Registers source identity mappings and append-only import receipts (`billium_import_mapping`, `billium_import_receipt`) without creating obligations, balances, customer credits, or collection eligibility.
2. **Proven Obligations (`proven_obligation`)**:
   - Executes inside a single `OperationRunner` `FinancialMode::Active` root transaction admitting `['ledger', 'platform']`.
   - Atomically claims idempotency by `sourceFactRef`, registers `billium_import_mapping`, calls `LedgerParticipants::recordObligation(ctx, cmd)` preserving the historical frozen `PriceVersion`, `termsDigest`, currency, and tax snapshot (ignoring any mutable current plan), binds `billium_target_ref`, and writes the `applied` import receipt and `obligation.created.v1` outbox event in the same PostgreSQL transaction.
3. **Historical Legal Invoices (`proven_invoice_history`)**:
   - Preserves `originalIssuer` and `sourceNumber` immutably in `billium_import_mapping` without invoking Billium legal document numbering or issuing a second legal number.
4. **Proven Settlement Targets (`proven_allocation` & explicit payment targets)**:
   - Declares `DeclareSettlementIntent` through `LedgerParticipants::declareSettlementIntent(ctx, cmd)` prior to observation reduction when both the `billingAccountRef` and proven target obligation(s) exist.
   - Missing account, intent, or target proof is never fabricated.
5. **Proven Payment & Refund Observations (`proven_payment_evidence`, `proven_refund_evidence`)**:
   - **Root 1 — Import Custody Queueing**: Commits `billium_import_mapping` (`billium_target_ref = NULL`), `billium_import_receipt` (`status = 'observation_queued'`), durable `billium_inbox_message` custody on compound partition `(sourceInstallation, sourceBook, sellerRef, environment)` under the configured migration `sourceAuthority`, and `billium_outbox_command` delivery. Never inserts directly into `billium_payment`, never fabricates provider operation IDs, never promotes `unknown` remote status to `succeeded`, and never claims `applied` or `paid` at queue time.
   - **Root 2 — Observation Owner Reduction**: `ParkedDrainer` reduces strictly one economic event per independent `ledger.observation_apply` root transaction through `ObservationHandler` / `PaymentProjection`, producing the authoritative `AllocationReceipt` (or parking in `billium_observation_park` when a settlement intent or original charge is absent).
   - **Receipt Adoption**: Queries `LedgerQueries::allocationReceipt` by deterministic `(sourceAuthority, operationRef, operationKind, revision)` and binds `billium_import_mapping.(billium_target_kind, billium_target_ref)` to the committed `AllocationReceipt`. If a worker crashes after the observation owner commits and before adoption completes, `resume` recovers the original `AllocationReceipt` by query alone without creating a second payment or receipt.
6. **Per-Currency Reconciliation (`ImportReconciler`)**:
   - Compares manifest and staged records against committed mappings, receipts, per-currency ledger conservation (`due + adjusted - credited - settled - written_off == open`, `settled == live_allocations`), and `AccountingFactMapper` `FinBridgeExportEnvelope` `sourceFactRef` stability without ever summing across currencies.

## 4. CLI Usage

```bash
# 1. Read-only preview (validates checksums, classifies records, emits hashed external.import.preview.v1 JSON)
php scripts/import/billium-import.php preview \
  --manifest fixtures/integration/import/reference-recurring/complete-manifest.json

# 2. Capability-gated apply with frozen manifestDigest
php scripts/import/billium-import.php apply \
  --manifest fixtures/integration/import/reference-recurring/complete-manifest.json \
  --manifest-digest sha256:<64-hex-chars> \
  --scope import:apply

# 3. Crash-safe resume from last committed checkpoint
php scripts/import/billium-import.php resume \
  --batch batch-rec-complete-2026-10-001 \
  --seller seller-recurring-1 \
  --manifest-digest sha256:<64-hex-chars> \
  --scope import:apply

# 4. Read-only per-currency reconciliation report
php scripts/import/billium-import.php reconcile \
  --batch batch-rec-complete-2026-10-001 \
  --seller seller-recurring-1

# 5. Isolated end-to-end fixture verification harness
php scripts/import/billium-import.php verify \
  --fixture fixtures/integration/import/reference-recurring/complete-manifest.json
```

## 5. Post-Apply Corrections Without Destructive Rollback

Committed import mappings, obligations, payments, and receipts are never deleted or overwritten. Post-apply corrections use `ImportBatchApplier::applyLinkedCorrection`:

- **`positive_obligation`**: Derives a deterministic linked basis via `CorrectionBasis::derive(originalBasisRef, correctionRef, newRevision)`, allocates multi-component deltas with exact `MoneyMath` remainder distribution, records the new obligation via `LedgerParticipants::recordObligation`, and supersedes the previous `billium_import_mapping` revision.
- **`negative_credit_note`**: Issues a linked credit note (`open_only` or `reverse_allocations`) via `LedgerParticipants::issueCreditNote` against the issued invoice without dispatching any automatic external payment provider refund.
- **`draft_obligation_adjustment`**: Appends a signed adjustment via `LedgerParticipants::adjustObligation` while the obligation remains unbilled.
- **`tombstone_mapping`**: Marks an erroneous or quarantined source mapping `tombstoned` with an explicit audit reason so future re-imports of that source identity fail closed into quarantine.
- **External Provider Refunds**: Any real money refund on a historical payment must be executed explicitly through the original execution owner and subsequently observed via `payment.observation.v1`, never synthesized inside import correction.

## 6. Local Evidence vs External Cutover Inputs

Hashed preview (`external.import.preview.v1`) and reconciliation (`external.import.reconciliation.v1`) reports prove only **local Billium runtime state**. Cutover and live authority activation additionally require external owner-supplied artifacts (`SourceFreezeCertificate`, `RetirementProof`, and `ActivationCertificate`), which are intentionally separated in report provenance and verified before cutover.
