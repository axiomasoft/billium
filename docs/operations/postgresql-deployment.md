# PostgreSQL Deployment & Connection Profiles

> Normative operational guide for running Billium against PostgreSQL 16+ (direct and PgBouncer transaction pooling).

## Supported Connection Profiles

| Profile | Mode | Requirements & Restrictions |
|---|---|---|
| **Direct PostgreSQL 16+** (port `5432`) | Mandatory for `artisan migrate` and DDL | `PDO::ATTR_EMULATE_PREPARES => false`, `lock_timeout = '2s'`, `statement_timeout = '30s'`, `idle_in_transaction_session_timeout = '30s'` |
| **PgBouncer >= 1.21 Transaction Pooling** (port `6432`) | Supported for runtime traffic | `pool_mode = transaction`, `max_prepared_statements >= 200`, `PDO::ATTR_EMULATE_PREPARES => false`. **Never run migrations through port 6432.** |
| **PgBouncer < 1.21** | Unsupported by default | Reject in preflight when `PDO::ATTR_EMULATE_PREPARES => true` is required |

## Runtime Guarantees

1. **No session-level advisory locks:** `FeedCommandResults` uses the durable `billium_feed_command_lease` table with monotonic `lease_epoch` and fenced `lease_token`, safe across PgBouncer `pool_mode = transaction`.
2. **Online DDL:** All migrations targeting existing tables use `SET LOCAL lock_timeout`, `CREATE INDEX CONCURRENTLY` via `OnlineDdl::createIndexConcurrently()` (`$withinTransaction = false`), and two-step `ADD CONSTRAINT ... NOT VALID` + separate `VALIDATE CONSTRAINT`.
3. **Optional Seller-Scoped RLS:** Disabled by default (`billium.database.rls_enabled = false`) for embedded hosts; when enabled, `RlsSessionContext::apply()` sets transaction-local `SET LOCAL` GUCs (`billium.installation_ref`, `billium.seller_ref`, `billium.environment`) with zero `tenant_id` columns (`REQ-PROF-09`).
