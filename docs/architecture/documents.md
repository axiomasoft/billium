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

## Asynchronous Rendering & Read Access (`readRender`)

- `billium_document_render_request` keys render idempotency on `(seller_ref, environment, render_key_digest)` covering legal document revision, approved snapshot digest, template ID/version/digest, locale, and renderer name/version/digest. Retries or repeat requests never allocate a second `legal_document`.
- `DocumentRenderer` and `DocumentStorage` execute strictly **outside DB transactions**. `DocumentStorage::store()` receives a deterministic `uploadKey` so timeouts after upload return the identical `blobRef` on retry without duplicating storage objects.
- `attachRenderResult()` uses compare-and-swap on `(render_revision, render_key_digest)` and marks stale/superseded uploads as `orphaned` rather than overwriting newer revisions.
- `readRender()` and `dispatchReadRenderQuery()` (`billium.query.v1`) enforce caller seller scope and actor authorization, issue short-lived expiring grants (`1..900` seconds) without storing URLs in PostgreSQL, and continue serving historical ready renders when `DocumentRenderer` is disabled/unbound.
- Asset URLs are restricted to allow-listed prefixes (`asset://`), and diagnostic logs scrub tokens, secrets, signatures, and sensitive query parameters.
- `cleanupOrphanBlobs()` deletes expired/unpinned `orphaned` blobs via `DocumentStorage::deleteOrphan()` while respecting `retention_pinned_until` pins and never deleting `attached` blobs.

## Document Corrections, Credit Notes & Void/Reissue

- `DocumentCorrectionService` separates distinct economic commands (`credit_note`, `document_correction`, `void_reissue`, `write_off`) while keeping frozen legal document snapshots and provider refunds strictly separate (credit approval never dispatches a PSP refund).
- Positive post-issue deltas (`issuePositiveCorrection`) record a new linked correction obligation via `LedgerParticipants::recordObligation()` rather than mutating frozen original obligations.
- Negative credits (`issueCreditNote`) invoke `LedgerParticipants::issueCreditNote()` (`open_only` or `reverse_allocations`) and persist immutable `billium_document_credit_note_line` lineage (`original_invoice_line_id`, `original_obligation_ref`, `original_component_ref`, `original_tax_snapshot_ref`, `original_rounding_policy_version`, cumulative sums and original basis bounds), enforced by deferred PostgreSQL constraint trigger `billium_documents_check_credit_note_sums()`.
- `PartialCreditAllocator` applies the host `DocumentTaxCorrectionPolicy` (rejecting missing/unsupported policies) and guarantees that final partial credits exhausting a line receive the exact remaining net/tax/gross remainder under the frozen original rounding policy.
