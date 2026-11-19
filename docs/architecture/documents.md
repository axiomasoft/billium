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
3. Under the `Invoice` lock inside the same root transaction, reads live locked invoice totals and refreshed per-obligation lines via `LockedInvoiceSnapshotParticipant::lockedInvoiceSnapshot()` and unconditionally verifies exact currency, obligation references, and line/subtotal/tax/total equality against the finalization snapshot so late adjustments or tax mismatches cannot issue changed lines.
4. Checks whether `billium_document_legal` was already committed for `(seller_ref, environment, document_type, economic_ref, document_revision)` so parallel races with distinct command keys replay cleanly without touching `billium_document_series`.
5. Invokes `DocumentsNumberingParticipant::allocateAndRecord()`, acquiring `LockRank::DocumentSeries (130)` via `LockSet`, atomically incrementing `next_number`, and writing `billium_document_legal` + `billium_document_number_journal`.

PostgreSQL `BEFORE UPDATE OR DELETE` triggers enforce append-only history, monotonic `next_number`, and frozen snapshot/number immutability even against direct SQL mutations. Voiding never decrements or reuses sequence numbers.

## Zero-Float Tax Arithmetic

`DocumentTaxArithmetic` (`BcDocumentTaxArithmetic`) computes exact decimal arithmetic using `bcmath` and deterministic `LargestRemainder` allocation across lines without any `float` types or casts, supporting `exclusive`, `inclusive`, `none_declared` (requiring non-empty `noTaxReason`), and `discount_before_tax` / `tax_before_discount` ordering.

## Asynchronous Rendering & Read Access (`readRender`)

- `billium_document_render_request` keys render idempotency on `(seller_ref, environment, render_key_digest)` covering legal document revision, approved snapshot digest, template ID/version/digest, locale, and renderer name/version/digest. Retries or repeat requests never allocate a second `legal_document`.
- `DocumentRenderer` and `DocumentStorage` execute strictly **outside DB transactions**. `DocumentStorage::store()` receives a deterministic `uploadKey` so timeouts after upload return the identical `blobRef` on retry without duplicating storage objects.
- `attachRenderResult()` uses compare-and-swap on `(render_revision, render_key_digest)` and marks stale/superseded uploads as `orphaned` rather than overwriting newer revisions.
- `readRender()` and `dispatchReadRenderQuery(string $json, ?array $allowedActorRefs = null)` (`billium.query.v1`) enforce caller seller/environment scope and actor authorization, issue short-lived expiring grants (`1..900` seconds) without storing URLs in PostgreSQL, and continue serving historical ready renders when `DocumentRenderer` is disabled/unbound. Host HTTP/RPC endpoints act as the trusted authorization boundary: they authenticate the caller into `CallerContext` and pass the authorized `$allowedActorRefs` allow-list to `dispatchReadRenderQuery()` (or `ReadDocumentRender::$allowedActorRefs`) before read grants are issued.
- Asset URLs are restricted to allow-listed prefixes (`asset://`), and diagnostic logs scrub tokens, secrets, signatures, and sensitive query parameters.
- `cleanupOrphanBlobs()` atomically claims unpinned/expired `orphaned` rows to `'deleted'` in PostgreSQL (`UPDATE ... WHERE status = 'orphaned' AND (retention_pinned_until IS NULL OR retention_pinned_until <= ?) RETURNING id`) before calling `DocumentStorage::deleteOrphan()` outside the transaction, while `attachRenderResult()` atomically attaches via status-guarded `UPDATE ... WHERE status <> 'deleted' RETURNING id` and refuses any `'deleted'` blob (`RecoveryRequired`), eliminating concurrent attach-vs-delete races.

## Document Corrections, Credit Notes & Void/Reissue

- `DocumentCorrectionService` separates distinct economic commands (`credit_note`, `document_correction`, `void_reissue`, `write_off`) while keeping frozen legal document snapshots and provider refunds strictly separate (credit approval never dispatches a PSP refund).
- Positive post-issue deltas (`issuePositiveCorrection`) record a new linked correction obligation via `LedgerParticipants::recordObligation()` rather than mutating frozen original obligations.
- Negative credits (`issueCreditNote`) invoke `LedgerParticipants::issueCreditNote()` (`open_only` or `reverse_allocations`) and persist immutable `billium_document_credit_note_line` lineage (`original_invoice_line_id`, `original_obligation_ref`, `original_component_ref`, `original_tax_snapshot_ref`, `original_rounding_policy_version`, cumulative sums and original basis bounds), enforced by deferred PostgreSQL constraint trigger `billium_documents_check_credit_note_sums()`.
- `PartialCreditAllocator` applies the host `DocumentTaxCorrectionPolicy` (rejecting missing/unsupported policies) and guarantees that final partial credits exhausting a line receive the exact remaining net/tax/gross remainder under the frozen original rounding policy.

## Operational Integration & Failure Isolation

### Independent Receipt Levels

Legal documents, payment execution, fiscalization, accounting export, and host fulfilment operate as strictly independent receipt boundaries (`COV-006`, `COV-364`, `COV-365`, `COV-372`, `COV-523`):

| Receipt Level | Owner | Independence & Failure Isolation Rule |
|:--|:--|:--|
| `payment` | `Billium\Execution\` / `Billium\Ledger\` | Durable provider custody and bounded ledger observations. Replaying `PaymentSucceeded` or observation deliveries never creates a second PSP charge. |
| `legal_document` | `Billium\Documents\` | Frozen legal snapshot and monotonic seller-scoped number. Never mutated by rerenders, fiscal outages, accounting backpressure, or over-refund discrepancies. |
| `fiscal` | `Billium\Execution\` ([fiscal-boundary.md](fiscal-boundary.md)) | Independent fiscal intent FSM owned strictly by the original payment executor (`standalone-billium` / `remote-billium`), or projection-only with 0 provider sends in `embedded-commerz`. Fiscal outage/rejection leaves settled `paid` state intact and triggers 0 compensating PSP actions. |
| `accounting` | `Billium\Platform\` ([accounting-facts.md](../contracts/accounting-facts.md)) | Replay-safe `AccountingFactEnvelope` and optional `FinBridgeExportEnvelope` with separate transport `AccountingDeliveryAck` and idempotent economic `AccountingAppliedReceipt`. Billium owns 0 GL tables. |
| Host `provisioning` / `fulfilment` | Host application | Rights, licensing, provisioning, and fulfilment remain host-owned. Payment facts are money evidence, not fulfilment completion; host timeouts or delayed activation never alter payment, legal, fiscal, or accounting receipts. |

### End-to-End Integration & Recovery Runbook

Standard and fault-recovery flow across `ledger`, `documents`, `execution` (fiscal), and `platform` (accounting):

1. **Issue (`LegalDocumentIssuer`)**: A single root `OperationRunner` transaction (`Obligation -> Invoice -> DocumentSeries`) issues the ledger invoice and allocates the frozen legal document snapshot/number; async PDF/HTML rendering runs strictly outside DB transactions.
2. **Partial Allocation (`Ledger::allocate`)**: Applies settled capture funds partially against the issued invoice, producing 1 `cash_in` accounting fact for the actual capture and separate `noncash_allocation` facts for allocations.
3. **Credit Note (`DocumentCorrectionService::issueCreditNote`)**: Creates an immutable credit note with `CreditNoteLine` lineage (`open_only` or `reverse_allocations`) and a linked `receivable` fact without mutating the frozen invoice snapshot and without auto-dispatching a PSP refund.
4. **Separately Authorized Refund (`Execution`)**: A distinct host-authorized refund command executes through the original payment executor's frozen binding and produces authenticated actual provider evidence. Under exceptional over-refund (`ACC-RST-12`: capture `100`, two distinct confirmed actual refunds `70 + 70`), fiscalization creates 2 refund intents totaling `140` (second marked `exceptional = true` with exposure provenance), accounting emits 2 `cash_out` facts totaling `140` plus 1 `noncash_exposure` fact for `40` (never double-counted as cash out), bounded ledger projection holds at `100 / 70` (`financial_hold = true`, `spendable = 0`), the frozen legal document snapshot/number stays byte-identical, and 0 automatic compensations run.
5. **Fiscal `unknown` & Lookup Recovery (`FiscalOrchestrator`)**: If a fiscal registration response is lost or the provider is unavailable, the committed `submitted` intent transitions to `unknown` while `paid` stays intact. Recovery runs bounded seller-scoped due-lookup claims (`claimDueLookups`) and invokes `FiscalDriver::findReceipt()` exclusively (with `ContinuityMerchantCredentials` for historical merchant revisions) — never re-registering.
6. **Accounting Replay (`AccountingFactMapper` / `AccountingConsumer`)**: Replaying callbacks, lookups, or outbox records in any permutation preserves identical `sourceFactRef` identities, counts, and amounts; unknown input schemas quarantine without data loss and apply once after explicit schema registration and replay.

### Safely Disabling `documents` and/or FinBridge

- **Disabling `documents`**: Omit `'documents'` from `billium.modules.enabled` or set `billium.documents.enabled = false`. Core `ledger` API (`RecordObligation`, `CreateInvoice`, `IssueInvoice`, `Allocate`, `LedgerQueries`) and `platform` `AccountingFactMapper` remain fully functional with zero documents routes, workers, or cross-module table coupling. Historical ready renders remain readable via `readRender()` even when `DocumentRenderer` is unbound.
- **Disabling or Backpressuring FinBridge**: Omitting or pausing `AccountingExportExtension` / `AccountingConsumer` never blocks or rolls back `ledger` commits, `documents` issuance, or `execution` transitions. Consumers catch up idempotently from durable source records once delivery resumes.

### Reproduction Commands

```bash
docker compose run --rm toolchain vendor/bin/pest packages/core/tests/P6
docker compose run --rm toolchain vendor/bin/pest --filter=DocumentsIntegration
docker compose run --rm toolchain vendor/bin/pest packages/core/tests/P6/FiscalAccountingExposureTest.php
docker compose run --rm toolchain bash scripts/ci.sh
```
