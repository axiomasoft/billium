# Billium Documents Module Architecture (`billium-documents`)

The `billium-documents` optional module (`L2`, namespace `Billium\Documents\`, schema stream `billium.documents`) owns legal document profiles, finalization snapshots, seller-scoped legal number series, issued legal documents, and number journals without owning rights, quota, provisioning, payments, or general ledger posting.

## Module & Table Ownership

Owned tables (`public.billium_document_*`):

- `billium_document_profile`: append-only versioned seller legal/tax profiles (`is_complete`, `profile_digest`).
- `billium_document_finalization`: CAS-guarded `draft -> finalizing -> snapshot_ready` (or `superseded`) tax finalization state anchored by `basis_revision`, `basis_digest`, `finalization_revision`, and `input_digest`.
- `billium_document_finalization_event`: append-only audit stream (`draft_created`, `finalization_started`, `revision_superseded`, `snapshot_attached`, `stale_snapshot_ignored`, `tax_port_failed`).
- `billium_document_series`: seller- and environment-isolated numbering sequences (`series_ref`, `period_key`, `period_policy`, monotonic `next_number`).
- `billium_document_legal`: frozen legal document snapshots, legal numbers, `status` (`issued`/`voided`), and allow-listed `display_metadata`.
- `billium_document_number_journal`: append-only allocation and void journal.

Cross-module writes are strictly forbidden: `documents` never writes `billium_invoice` or other ledger tables directly, and ledger never writes `billium_document_*`. Optional-disabled mode (`billium.documents.enabled = false`) keeps obligations, invoices, and payments fully operational without documents routes or workers.

## Finalization & External Tax Snapshot Boundary (INV-06)

1. `DocumentFinalizationService::createDraft()` persists an immutable-digest draft revision (`state = 'draft'`).
2. `DocumentFinalizationService::finalizeWithTaxPort()` transitions `draft -> finalizing` in short Transaction #1.
3. `DocumentTaxSnapshotPort::resolve()` runs strictly **outside any database transaction** (`transactionLevel() === 0`). Timeout or transport failures record `tax_port_failed` and keep `state = 'finalizing'` for safe retry.
4. `DocumentFinalizationService::attachTaxSnapshot()` runs in short Transaction #2 with compare-and-swap on `(finalization_revision, input_digest, state = 'finalizing')`. Stale/late results after `reviseDraft()` are ignored (`staleIgnored: true`) and recorded as `stale_snapshot_ignored`; currency or total mismatches are refused before mutation.

## Atomic Legal Issue & Lock Order (D27 / D49 / D60)

`LegalDocumentIssuer::issueInvoiceDocument()` executes under a single documents-root `OperationRunner` transaction (`FinancialMode::Active`, `modules: ['platform', 'ledger', 'documents']`):

1. Validates `snapshot_ready` finalization, `approved_snapshot_digest`, complete `DocumentProfile`, required buyer/seller fields, and caller-scoped active `DocumentSeries`.
2. Invokes `LedgerIssuePolicy::issue()` -> `LedgerParticipants::issueInvoice()` first, acquiring `LockRank::Obligation (70)` and `LockRank::Invoice (80)`.
3. Under the `Invoice` lock, re-reads `LedgerQueries::invoice()` and verifies exact currency, obligation references, and line/subtotal/total equality against the finalization snapshot so a late snapshot cannot issue changed lines.
4. Checks whether `billium_document_legal` was already committed for `(seller_ref, environment, document_type, economic_ref, document_revision)` so parallel races with distinct command keys replay cleanly without touching `billium_document_series`.
5. Invokes `DocumentsNumberingParticipant::allocateAndRecord()`, acquiring `LockRank::DocumentSeries (130)` via `LockSet`, atomically incrementing `next_number`, and writing `billium_document_legal` + `billium_document_number_journal`.

PostgreSQL `BEFORE UPDATE OR DELETE` triggers enforce append-only history, monotonic `next_number`, and frozen snapshot/number immutability even against direct SQL mutations. Voiding never decrements or reuses sequence numbers.

## Zero-Float Tax Arithmetic

`DocumentTaxArithmetic` (`BcDocumentTaxArithmetic`) computes exact decimal arithmetic using `bcmath` and deterministic `LargestRemainder` allocation across lines without any `float` types or casts, supporting `exclusive`, `inclusive`, `none_declared` (requiring non-empty `noTaxReason`), and `discount_before_tax` / `tax_before_discount` ordering.
