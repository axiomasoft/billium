# Query Plans and Hot-Path Performance (P10.4)

Billium verifies hot-path PostgreSQL query plans, bounded batching, seller-scoped isolation, and dispatch lock discipline using a compact, deterministic verification suite (D71/D72). Rather than maintaining an expensive multi-million-row synthetic load cluster in CI, acceptance runs `EXPLAIN (FORMAT JSON)` on a small multi-seller dataset with `SET LOCAL enable_seqscan = off` and verifies live runtime query paths through Pest.

## Key Hot-Path Indexes

| Domain | Table | Index | Key / Predicate | Protected Query Path |
| :--- | :--- | :--- | :--- | :--- |
| Ledger | `billium_obligation` | `billium_obligation_account_due_idx` | `(installation_ref, seller_ref, environment, billing_account_ref, status, due_at, id) WHERE status = 'open' AND due_at IS NOT NULL` | Account-scoped `dueObligations` cursor pagination |
| Ledger | `billium_obligation` | `billium_obligation_due_idx` | `(seller_ref, environment, status, due_at, id) WHERE due_at IS NOT NULL` | Seller-scoped overdue obligation scan |
| Ledger | `billium_payment` | `billium_payment_ref_idx` | `(installation_ref, seller_ref, environment, payment_ref)` | Scoped payment status & refund authorization lookup |
| Ledger | `billium_observed_payment` | `billium_observed_payment_refund_original_idx` | `(seller_ref, environment, source_authority, original_ref) WHERE original_ref IS NOT NULL` | Refunds of one charge: payment status, refund reconcile and import |
| Ledger | `billium_ledger_discrepancy` | `billium_ledger_discrepancy_observed_idx` | `(seller_ref, environment, observed_payment_id)` | Unresolved discrepancies of a charge and its refunds in payment status |
| Ledger | `billium_discrepancy_event` | `billium_discrepancy_event_discrepancy_idx` | `(discrepancy_id, kind)` | Resolution check in payment status and refund reconcile |
| Ledger | `billium_allocation_receipt` | `billium_allocation_receipt_digest_idx` | `(seller_ref, environment, semantic_digest)` | Refund detail replay probe, refund reconcile basis, payment status and checkpoint receipt lookups |
| Documents | `billium_document_credit_note_line` | `billium_document_credit_note_line_original_idx` | `(seller_ref, environment, original_legal_document_id)` | Prior credits of the original invoice in a document correction |
| Documents | `billium_document_render_blob` | `billium_document_render_blob_cleanup_idx` | `(seller_ref, environment, id) WHERE status IN ('orphaned', 'deleting')` | Orphan blob sweep in `cleanupOrphanBlobs` |
| Execution | `billium_execution_recon_item` | `billium_execution_recon_item_review_idx` | `(review_ref) WHERE review_ref IS NOT NULL` | Manual repair lookup of a reviewed inventory item (late refund adjudication, linked correction approval) |
| Ledger | `billium_allocation` | `billium_allocation_obligation_idx` | `(seller_ref, environment, obligation_id, id)` | Allocations by obligation in deterministic ID order |
| Ledger | `billium_allocation` | `billium_allocation_receipt_ref_idx` | `(seller_ref, environment, receipt_ref)` | `reverseAllocation` lookup of the original allocation by its public reference |
| Idempotency | `billium_idempotency_receipt` | `billium_idempotency_receipt_scope_key` | `UNIQUE (environment, seller_ref, command_kind, command_key)` | Command replay / conflict check |
| Idempotency | `billium_idempotency_receipt` | `billium_idempotency_opaque_receipt_scope` | `(financial_scope, result->>'class', result#>>'{body,receiptRef}', created_at, id) WHERE outcome = 'accepted' AND financial_scope IS NOT NULL` | Opaque receipt lookup without scanning rejected rows |
| Outbox | `billium_outbox_event` | `billium_outbox_event_scope_cursor` / `billium_outbox_event_seller_cursor` | `((source_scope::jsonb), tx_id, id)` and `(seller_ref, environment, tx_id, id)` | Seller/scope feed pagination in `(tx_id, id)` order without `Sort` |
| Outbox | `billium_outbox_delivery` | `billium_outbox_delivery_due` | `(available_at, id) WHERE status IN ('pending', 'in_flight')` | Relay `SKIP LOCKED` claim skipping backed-off & delivered history |
| Outbox | `billium_outbox_delivery` | `billium_outbox_delivery_open_event` | `(event_id) WHERE status <> 'delivered'` | Prune open-delivery proof without scanning delivered rows |
| Inbox | `billium_inbox_message` | `billium_inbox_message_pending_op_idx` | `(seller_ref, environment, channel, source_authority, operation_ref, payload->>'installationRef', operation_revision DESC, id DESC) WHERE status IN ('ready', 'parked_gap', 'parked_dependency')` | Pending observation lookup for latest revision |
| Ledger | `billium_observation_owner_binding` | `billium_observation_owner_binding_pkey` | `operation_key = sha256(jsonb_build_array(seller_ref, environment, source_authority, operation_ref))` | Legacy-revision probe on observation intake, parked drain and checkpoint reads (`ObservationOwnership::bindingKey`) |
| Execution | `billium_execution_dispatch_work` | `billium_execution_dispatch_work_lookup` | `(installation_ref, seller_ref, environment, state, due_at, id)` | Due dispatch work batch scan |
| Execution | `billium_execution_provider_work` | `billium_execution_provider_work_lookup` | `(installation_ref, seller_ref, environment, state, due_at, id)` | Due callback/evidence reducer work scan |
| Execution | `billium_execution_attempt` | `billium_execution_provider_reference` | `UNIQUE (installation_ref, environment, provider_id, merchant_account_ref, provider_reference)` | Provider callback & lookup correlation |
| Execution | `billium_execution_business_binding` | `billium_execution_business_identity_digest_unique` | `UNIQUE (business_identity_digest)` | Business identity lookup |
| Execution | `billium_execution_operation` | `billium_execution_operation_root_idx` | `(installation_ref, seller_ref, environment, intent_owner_namespace, root_operation_ref)` | Root + child operation tree reads |
| Schedule | `billium_schedule_occurrence_amendment` | `billium_schedule_occurrence_amendment_lookup` | `(seller_ref, environment, occurrence_ref, schedule_revision)` | Revision-confirmation check in `ScheduleQueries::occurrence` |
| Collection | `billium_collection_work` | `billium_collection_work_due_idx` | `(seller_ref, status, next_attempt_at, step_id) WHERE next_attempt_at IS NOT NULL` | Due collection step scheduling |

## Measured Index Additions

Each change below has a plan test that fails without it (one is a query rewrite on existing indexes), except the replay probe, which runs inside a routine; `scripts/bench/billing.py` checks its index instead. Timings are single-seller `EXPLAIN ANALYZE` runs on PostgreSQL 18 before and after the change, on synthetic tables at the stated scale:

| Query path | Scale | Before | After | Plan test |
| :--- | :--- | ---: | ---: | :--- |
| Revision-confirmation check in `ScheduleQueries::occurrence` | 200k amendments | 28 ms | 0.05 ms | `OccurrenceAmendmentLookupPlanTest` |
| Legacy-revision probe on observation intake (owner binding by primary key) | 200k bindings | 28 ms | 0.07 ms | `ObservationOwnerBindingLookupTest` |
| Refunds of one charge in payment status | 100k observed payments | 35 ms | 0.07 ms | `PaymentStatusLookupPlanTest` |
| Unresolved discrepancies of a charge in `LedgerReader` | 100k discrepancies, 150k events | 20 ms | 0.12 ms | `PaymentStatusLookupPlanTest` |
| Refund reconcile basis in `RefundReconciler::compare` | 250k observations, 500k revisions, 700k receipts | 159 ms | 0.15 ms | `RefundReconcileLookupPlanTest` |
| Refund detail replay probe in `billium_record_refund_detail()` | 700k receipts | 50 ms | 0.07 ms | `scripts/bench/billing.py` (`ledger.refund_detail_by_digest`) |
| Reviewed inventory item lookup in manual repair | 1M retained items | 75 ms | 0.09 ms | `HotPathIndexTest` |
| Original allocation lookup in `reverseAllocation` (read before and after its lock plan) | 500k allocations | 31 ms | 0.06 ms | `ReverseAllocationLookupPlanTest`, `scripts/bench/billing.py` (`ledger.allocation_by_receipt_ref`) |
| Prior credit note lines of the original invoice in a document correction | 400k credit note lines | 28–35 ms | 0.04 ms | `CreditNoteLineLookupPlanTest` |
| Orphan blob sweep candidates in `cleanupOrphanBlobs` | 500k retained blobs, 10 orphaned | 34–37 ms | 0.06 ms | `RenderBlobCleanupPlanTest` |
| Per-case child reads in `DunningQueries` and `CollectionInterventionQueries` (steps, policy migrations, notifications, signals, interventions) and consent tombstones in `ConsentQueries::listTombstones`: resolve the parent id by its public ref, then read the child `(seller_ref, environment, dunning_case_id or consent_guard_id, …)` index; no new index | 100k cases, 300k steps, 200k notifications | 24 ms (steps), 19 ms (notification by key) | 0.04 ms | `ParentRefLookupPlanTest` |

Left without an index on purpose: `billium_feed_bootstrap_session.new_partition_id` is read only during gap recovery and bootstrap proof (9 ms even at 100k sessions), and foreign keys of append-only tables are not indexed by default: their parent rows are never deleted, so such an index would only serve a join. Add one only for a measured hot path.

## Round Trips and Write Amplification (0.1.0)

Measured on PostgreSQL 16.16 over local TCP. The suite counts come from `pg_stat_statements` (`track = all`) over the full PostgreSQL test suite.

| Change | Measurement | Before | After |
| :--- | :--- | ---: | ---: |
| `Pdo\Pgsql::ATTR_DISABLE_PREPARES` on the connection (see [PostgreSQL deployment](postgresql-deployment.md#connection-options)) | 20k parameterised `SELECT`s, best of 3 | 60.7 µs/query | 30.7 µs/query |
| Root attempt opens with `SET TRANSACTION` plus one `set_config()` `SELECT` for the deadline, idle timeout and RLS scope (`DatabaseOperationRunner`, `RlsSessionContext::apply()`) | statements per root attempt; 20,879 attempts in the suite | 4 | 2 (−41,758 round trips) |
| Session admission re-checks and reads the locked financial session in one statement (`Sessions::findIfCurrent()`) | statements after the lock plan per admitted root | 3 | 2 |
| 49 unique keys that contain the primary key and back no foreign key dropped (ledger, execution, usage; migrations `2026_12_01_00000{1,2,3}`) | 100k-row `INSERT` into a copy of `billium_allocation_receipt`, median of 3 | 4,769 ms | 4,013 ms (−16%) |
| Same | index bytes per row / indexes on the 41 tables | 477 B / 201 | 324 B / 152 |
| `billium:server:metrics` computes the parked-inbox count and the outbox lag once each | `EXPLAIN ANALYZE`, 300k inbox rows (6k parked), 3 runs | 69 ms, 3 inbox scans | 35 ms, 1 inbox scan |

Composite unique keys that a foreign key references stay: they carry the cross-seller composite foreign keys. The drop migrations look each key up by table and column list and refuse to drop one that a foreign key depends on.

Open items with measurements are tracked in the [0.1 database audit](../audit/v0.1-db-proposals.md).

## Bounded Batching & Cursor Pagination

Queue, feed, and list reads use explicit limits and stable keyset cursors instead of `OFFSET`:

- **`LedgerQueries::dueObligations`**: uses `(due_at, id)` keyset cursor pagination with `LIMIT (limit + 1)` to detect `nextCursor` cleanly on `billium_obligation_account_due_idx`. The page stays within one billing account and `limit` is at most 1000.
- **`OutboxRelay::claim`**: enforces `1 <= limit <= 1000`, filters by `available_at <= statement_timestamp()` (a stable clock so PostgreSQL stops at the first future backoff row), and locks only the bounded batch with `FOR UPDATE OF d SKIP LOCKED`.
- **`EventFeedReader::read`**: walks `(tx_id, id)` in index order up to `pg_snapshot_xmin(pg_current_snapshot())` with an explicit page `LIMIT`, preventing cross-seller scans or external `Sort` nodes.
- **`OutboxPruner::prune`**: removes safe prefixes in bounded batches of at most `1000` rows per transaction.
- **Execution & collection due work**: claim queries bound rows with `ORDER BY due_at, id LIMIT N` (or `ORDER BY next_attempt_at, step_id LIMIT N`).
- **`ImportReconciler::reconcileBatch`**: resolves the revision mapping and the applied receipt of staged rows in chunks of 500, one set-based query each per chunk, instead of two queries per row (`ImportReconcileQueryBudgetTest`). On PostgreSQL 18 with 11k staged rows this cut the call from 28,034 queries and 8.0 s to 6,058 queries and 3.3 s, with an identical report. The rest is the per-row `ImportMonetaryPort::findObservationOutcome` call for mapped payment and refund evidence, which stays per row because the port takes one operation. The staged rows are still loaded at once, because the manifest inventory checksum covers the whole batch. Two further changes were considered and not made. A batch `findObservationOutcomes` would remove the remaining per-row reads, but `ImportMonetaryPort` is an implement SPI, so adding a method breaks every implementation, and the call is an offline operator step that now takes 3.3 s for 11k rows. A row cap in the reconciler would only move the limit: the export reader and the batch applier already hold the full batch in memory, so batch size is set when the export is cut, and an oversized migration should be split into several batches there.

## Isolation & Dispatch Contention Guardrails

- **No cross-seller read leakage**: every index leads with tenant/scope columns (`installation_ref`, `seller_ref`, `environment`, or `source_scope`) and runtime query services enforce authenticated `CallerContext` scope before resolving selectors (`billium.auth.denied` or `billium.reference.unknown`).
- **No `UPDATE billium_installation_state` on dispatch**: `AttemptWorker::dispatch` reads active session authority and fences execution via per-attempt CAS on `billium_execution_attempt` and `billium_financial_session`. It never executes `UPDATE public.billium_installation_state`, preventing global single-row lock contention across concurrent workers.

## Running the Lightweight Query-Plan Check

Run both targeted checks inside the repository `toolchain` container against PostgreSQL 16:

```bash
# 1. Pest runtime + EXPLAIN verification (indexes, bounded limits, seller isolation, dispatch lock check)
docker compose run --rm -u 0:0 toolchain vendor/bin/pest --filter='BillingQueryPaths|HotPathIndex|LookupPlan|OwnerBindingLookup'

# 2. Standalone CLI EXPLAIN (FORMAT JSON) report on stdout (runs in a rolled-back transaction)
docker compose run --rm -u 0:0 toolchain python3 scripts/bench/billing.py --profile acceptance --output -
```

`scripts/bench/billing.py` exits `0` only when every expected index is present in `pg_indexes`, every query uses its expected index with zero `Seq Scan` nodes, and ordered cursor/due queries avoid `Sort` nodes.
