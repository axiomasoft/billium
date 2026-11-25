# Query Plans and Hot-Path Performance (P10.4)

Billium verifies hot-path PostgreSQL query plans, bounded batching, seller-scoped isolation, and dispatch lock discipline using a compact, deterministic verification suite (D71/D72). Rather than maintaining an expensive multi-million-row synthetic load cluster in CI, acceptance runs `EXPLAIN (FORMAT JSON)` on a small multi-seller dataset with `SET LOCAL enable_seqscan = off` and verifies live runtime query paths through Pest.

## Key Hot-Path Indexes

| Domain | Table | Index | Key / Predicate | Protected Query Path |
| :--- | :--- | :--- | :--- | :--- |
| Ledger | `billium_obligation` | `billium_obligation_account_due_idx` | `(installation_ref, seller_ref, environment, billing_account_ref, status, due_at, id) WHERE status = 'open' AND due_at IS NOT NULL` | Account-scoped `dueObligations` cursor pagination |
| Ledger | `billium_obligation` | `billium_obligation_due_idx` | `(seller_ref, environment, status, due_at, id) WHERE due_at IS NOT NULL` | Seller-scoped overdue obligation scan |
| Ledger | `billium_payment` | `billium_payment_ref_idx` | `(installation_ref, seller_ref, environment, payment_ref)` | Scoped payment status & refund authorization lookup |
| Ledger | `billium_allocation` | `billium_allocation_obligation_idx` | `(seller_ref, environment, obligation_id, id)` | Allocations by obligation in deterministic ID order |
| Idempotency | `billium_idempotency_receipt` | `billium_idempotency_receipt_scope_key` | `UNIQUE (environment, seller_ref, command_kind, command_key)` | Command replay / conflict check |
| Idempotency | `billium_idempotency_receipt` | `billium_idempotency_opaque_receipt_scope` | `(financial_scope, result->>'class', result#>>'{body,receiptRef}', created_at, id) WHERE outcome = 'accepted' AND financial_scope IS NOT NULL` | Opaque receipt lookup without scanning rejected rows |
| Outbox | `billium_outbox_event` | `billium_outbox_event_scope_cursor` / `billium_outbox_event_seller_cursor` | `((source_scope::jsonb), tx_id, id)` and `(seller_ref, environment, tx_id, id)` | Seller/scope feed pagination in `(tx_id, id)` order without `Sort` |
| Outbox | `billium_outbox_delivery` | `billium_outbox_delivery_due` | `(available_at, id) WHERE status IN ('pending', 'in_flight')` | Relay `SKIP LOCKED` claim skipping backed-off & delivered history |
| Outbox | `billium_outbox_delivery` | `billium_outbox_delivery_open_event` | `(event_id) WHERE status <> 'delivered'` | Prune open-delivery proof without scanning delivered rows |
| Inbox | `billium_inbox_message` | `billium_inbox_message_pending_op_idx` | `(seller_ref, environment, channel, source_authority, operation_ref, payload->>'installationRef', operation_revision DESC, id DESC) WHERE status IN ('ready', 'parked_gap', 'parked_dependency')` | Pending observation lookup for latest revision |
| Execution | `billium_execution_dispatch_work` | `billium_execution_dispatch_work_lookup` | `(installation_ref, seller_ref, environment, state, due_at, id)` | Due dispatch work batch scan |
| Execution | `billium_execution_provider_work` | `billium_execution_provider_work_lookup` | `(installation_ref, seller_ref, environment, state, due_at, id)` | Due callback/evidence reducer work scan |
| Execution | `billium_execution_attempt` | `billium_execution_provider_reference` | `UNIQUE (installation_ref, environment, provider_id, merchant_account_ref, provider_reference)` | Provider callback & lookup correlation |
| Execution | `billium_execution_business_binding` | `billium_execution_business_identity_digest_unique` | `UNIQUE (business_identity_digest)` | Business identity lookup |
| Execution | `billium_execution_operation` | `billium_execution_operation_root_idx` | `(installation_ref, seller_ref, environment, intent_owner_namespace, root_operation_ref)` | Root + child operation tree reads |
| Schedule | `billium_schedule_occurrence_amendment` | `billium_schedule_occurrence_amendment_lookup` | `(seller_ref, environment, occurrence_ref, schedule_revision)` | Revision-confirmation check in `ScheduleQueries::occurrence` |
| Collection | `billium_collection_work` | `billium_collection_work_due_idx` | `(seller_ref, status, next_attempt_at, step_id) WHERE next_attempt_at IS NOT NULL` | Due collection step scheduling |

## Bounded Batching & Cursor Pagination

All queue, feed, and list reads enforce explicit upper bounds and stable keyset cursors instead of `OFFSET`:

- **`LedgerQueries::dueObligations`**: uses `(due_at, id)` keyset cursor pagination with `LIMIT (limit + 1)` to detect `nextCursor` cleanly on `billium_obligation_account_due_idx`.
- **`OutboxRelay::claim`**: enforces `1 <= limit <= 1000`, filters by `available_at <= statement_timestamp()` (a stable clock so PostgreSQL stops at the first future backoff row), and locks only the bounded batch with `FOR UPDATE OF d SKIP LOCKED`.
- **`EventFeedReader::read`**: walks `(tx_id, id)` in index order up to `pg_snapshot_xmin(pg_current_snapshot())` with an explicit page `LIMIT`, preventing cross-seller scans or external `Sort` nodes.
- **`OutboxPruner::prune`**: removes safe prefixes in bounded batches of at most `1000` rows per transaction.
- **Execution & collection due work**: claim queries bound rows with `ORDER BY due_at, id LIMIT N` (or `ORDER BY next_attempt_at, step_id LIMIT N`).

## Isolation & Dispatch Contention Guardrails

- **No cross-seller read leakage**: every index leads with tenant/scope columns (`installation_ref`, `seller_ref`, `environment`, or `source_scope`) and runtime query services enforce authenticated `CallerContext` scope before resolving selectors (`billium.auth.denied` or `billium.reference.unknown`).
- **No `UPDATE billium_installation_state` on dispatch**: `AttemptWorker::dispatch` reads active session authority and fences execution via per-attempt CAS on `billium_execution_attempt` and `billium_financial_session`. It never executes `UPDATE public.billium_installation_state`, preventing global single-row lock contention across concurrent workers.

## Running the Lightweight Query-Plan Check

Run both targeted checks inside the repository `toolchain` container against PostgreSQL 16:

```bash
# 1. Pest runtime + EXPLAIN verification (indexes, bounded limits, seller isolation, dispatch lock check)
docker compose run --rm -u 0:0 toolchain vendor/bin/pest --filter=BillingQueryPaths

# 2. Standalone CLI EXPLAIN (FORMAT JSON) report on stdout (runs in a rolled-back transaction)
docker compose run --rm -u 0:0 toolchain python3 scripts/bench/billing.py --profile acceptance --output -
```

`scripts/bench/billing.py` exits `0` only when every expected index is present in `pg_indexes`, every query uses its expected index with zero `Seq Scan` nodes, and ordered cursor/due queries avoid `Sort` nodes.
