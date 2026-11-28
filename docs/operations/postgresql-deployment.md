# PostgreSQL Deployment & Connection Profiles

> Normative operational guide for running Billium against PostgreSQL 16+ (direct connections and PgBouncer transaction pooling).

Billium is a library inside a Laravel host: the host owns the `database.connections.*` entry that `billium.connection`
names. This page lists what that entry must contain.

## Connection options

```php
'pgsql' => [
    'driver' => 'pgsql',
    // ...host, port, database, username, password...
    'search_path' => 'public',
    'options' => [
        PDO::ATTR_EMULATE_PREPARES => false,
        Pdo\Pgsql::ATTR_DISABLE_PREPARES => true,
    ],
],
```

| Option | Value | Why |
| :--- | :--- | :--- |
| `PDO::ATTR_EMULATE_PREPARES` | `false` | Parameters stay server-side typed values; PDO never interpolates them into SQL text. |
| `Pdo\Pgsql::ATTR_DISABLE_PREPARES` | `true` (recommended) | PDO sends each query with its parameters in one `PQexecParams` round trip instead of a separate named prepare followed by an execute. Laravel prepares every query anew, so the named statement is never reused. On a local TCP connection this halves the per-query latency (61 µs to 31 µs per parameterised `SELECT`); over a real network link the saving is one full round trip per query. Unnamed statements also need no PgBouncer prepared-statement tracking. |

The Billium test suite runs with exactly these options (`packages/core/tests/TestEnvironment.php`).

## Supported connection profiles

| Profile | Mode | Requirements & restrictions |
| :--- | :--- | :--- |
| **Direct PostgreSQL 16+** (port `5432`) | Required for `artisan migrate` and every DDL | Options above. Migrations set their own `SET LOCAL lock_timeout` / `statement_timeout`. |
| **PgBouncer >= 1.21, transaction pooling** (port `6432`) | Supported for runtime traffic | `pool_mode = transaction`. With `ATTR_DISABLE_PREPARES => true` no `max_prepared_statements` tuning is needed; with native prepares set `max_prepared_statements >= 200`. **Never run migrations through the pooler.** |
| **PgBouncer < 1.21** | Supported only with `ATTR_DISABLE_PREPARES => true` | Older PgBouncer cannot track named prepared statements across pooled server connections. |

## Runtime timeouts

Billium sets its own transaction-local limits; the host does not need session defaults for them:

| Setting | Where | Value |
| :--- | :--- | :--- |
| `statement_timeout`, `idle_in_transaction_session_timeout` | every root operation (`DatabaseOperationRunner`) | `billium.runner.root_deadline_ms`, 30 s by default |
| `lock_timeout` | idempotency claim (`DatabaseIdempotencyStore`) | `billium.idempotency.lock_timeout_ms`, 5 s by default |
| `lock_timeout` | financial-session seal (`DatabaseInstallation`) | `billium.financial_session.seal_lock_timeout_ms`, 5 s by default |
| `lock_timeout`, `statement_timeout` | migrations on existing tables | set per migration with `SET LOCAL` |

A root operation that fails with a serialization failure (`40001`), a deadlock (`40P01`) or a changed lock plan is
rolled back and repeated as a whole, up to `billium.runner.max_attempts` attempts (3 by default).

## Runtime guarantees

1. **No session-level advisory locks.** `FeedCommandResults` serialises feed commands through the durable
   `billium_feed_command_lease` table (monotonic `lease_epoch`, fenced `lease_token`), so it works behind PgBouncer
   transaction pooling.
2. **Online DDL.** Migrations that touch existing tables use `SET LOCAL lock_timeout`, two-step
   `ADD CONSTRAINT ... NOT VALID` followed by `VALIDATE CONSTRAINT` in a later migration, and
   `OnlineDdl::createIndexConcurrently()` (outside a transaction) for new indexes on large tables.
3. **Optional seller-scoped row-level security.** Billium ships no RLS policies. Every root operation, ledger read
   session, usage call, idempotency lookup and feed read sets the transaction-local GUCs `billium.installation_ref`,
   `billium.seller_ref` and `billium.environment` (`RlsSessionContext`). A host that wants database-enforced seller
   isolation can create policies on those GUCs; `packages/core/tests/Feature/SellerScopedRlsTest.php` shows the policy
   shape and proves that the runtime keeps working under them. There are no `tenant_id` columns.
