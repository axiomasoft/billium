# Retention and Pruning Operations

Billium separates **operational queues** (prunable after proven delivery and retention expiry) from **immutable financial truth, evidence, and dedupe floors** (D25, D52, D60).

## 1. Outbox Pruning (`billium:outbox:prune`)

`OutboxPruner` removes only a proven, contiguous physical `(tx_id, id)` prefix of `billium_outbox_event` and `billium_outbox_delivery`:

- **Explicit retention floor required:** `billium.outbox.retention_floor_seconds` must be configured (`>= 0`). An unset retention floor fails closed (`LogicException`).
- **Owner-only maintenance connection:** Pruning requires the explicitly provisioned maintenance owner connection (`billium.outbox.maintenance_connection`); runtime worker connections cannot delete outbox rows.
- **Bounded batches:** Each maintenance transaction examines and deletes at most `1000` rows while advancing `billium_outbox_watermark` atomically.
- **Active pins block pruning:** Pruning stops before the first row that is:
  - newer than the retention floor,
  - not yet `delivered` across all destinations (`billium_outbox_delivery`),
  - protected by an active `billium_feed_bootstrap_lease`, or
  - protected by an open required `billium_feed_subscription` (used for mandatory consumers and legal/audit holds).

Once a required subscription is closed via `EventFeed::closeFeed()` (irreversible) and deliveries are complete, subsequent prune runs reclaim the safe prefix. Consumers whose cursor falls behind the pruned watermark receive `billium.recovery.required` and re-sync via snapshot bootstrap (see [outbox-feed.md](outbox-feed.md)).

## 2. Protected Floors (Never Pruned While Active)

| Class | Storage / Mechanism | Operational Rule |
| :--- | :--- | :--- |
| **Legal holds & document render pins** | `billium_document_render_blob.retention_pinned_until` and open required `billium_feed_subscription` pins | Orphaned render blobs with `retention_pinned_until > asOf` are skipped by cleanup; rows in `billium_document_render_blob` cannot be hard-deleted (`SQLSTATE 23514`). |
| **Unknown attempts & in-flight work** | `billium_execution_attempt` (`state = 'unknown'`), `billium_execution_reservation` | Never expired or pruned by TTL. Capacity remains held until reconciled with qualified provider evidence or certified absence. |
| **Provider evidence & audit history** | `billium_execution_provider_evidence`, `billium_execution_provider_fact`, `billium_money_commit_log` | Append-only custody and audit trail; preserved across outbox and receipt pruning. |
| **Business identity dedupe floor** | `billium_execution_business_binding` (`retention_policy = 'execution-business-identity-indefinite-v1'`, `retention_deadline_at IS NULL`) | Survives `billium_idempotency_receipt` pruning. Replaying an original key resolves the original receipt with zero new provider dispatch; unseen keys in a `closed` namespace fail with `billium.key.expired`. |

## 3. Verification

Verify retention floor enforcement, legal/subscription pin protection, unknown/evidence survival, and closed-namespace dedupe behavior with:

```bash
docker compose run --rm -u 0:0 toolchain vendor/bin/pest --filter=BillingRecoveryDrill
docker compose run --rm -u 0:0 toolchain bash scripts/drills/billing-restore.sh --fake-provider
```
