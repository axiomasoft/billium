<?php

declare(strict_types=1);

namespace Billium\Core\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use LogicException;
use Packman\Attributes\Api;
use RuntimeException;

/**
 * Builds an index on a live table without blocking writes.
 *
 * Run from a migration with `$withinTransaction = false`: CREATE INDEX CONCURRENTLY cannot run inside a
 * transaction. A build that was interrupted leaves an invalid index behind; the next call drops it and
 * builds it again, so the method is safe to re-run after a crash.
 */
#[Api(since: '1.0')]
final readonly class OnlineDdl
{
    /**
     * Creates `public.<indexName>` with `$createSql` and returns once `pg_index.indisvalid` is true.
     *
     * `$createSql` must be a `CREATE [UNIQUE] INDEX CONCURRENTLY` statement for `$indexName`. Each attempt
     * runs under `lock_timeout = $lockTimeoutMs`; a lock timeout (SQLSTATE 55P03) is retried with jittered
     * exponential backoff up to `$maxRetries` attempts. Any other error is rethrown. The session's previous
     * `lock_timeout` is restored after every attempt.
     *
     * @throws LogicException when called inside a transaction or with an invalid identifier or statement
     * @throws QueryException when the build fails for a reason other than a lock timeout, or on the last attempt
     * @throws RuntimeException when the index is still not valid after the last attempt
     */
    public static function createIndexConcurrently(
        Connection $db,
        string $indexName,
        string $createSql,
        int $lockTimeoutMs = 2000,
        int $maxRetries = 5,
    ): void {
        if ($db->transactionLevel() !== 0) {
            throw new LogicException('OnlineDdl::createIndexConcurrently() must run outside a transaction ($withinTransaction = false).');
        }
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $indexName) !== 1) {
            throw new LogicException('Invalid index identifier.');
        }
        if (preg_match('/^\s*CREATE\s+(?:UNIQUE\s+)?INDEX\s+CONCURRENTLY\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:public\.)?'.$indexName.'\s+ON\s/i', $createSql) !== 1) {
            throw new LogicException("OnlineDdl::createIndexConcurrently() needs a CREATE INDEX CONCURRENTLY statement for {$indexName}.");
        }

        $attempts = max(1, $maxRetries);
        $previousLockTimeout = self::currentLockTimeout($db);
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $status = self::indexStatus($db, $indexName);
            if ($status === true) {
                return;
            }
            if ($status === false) {
                $db->statement('DROP INDEX CONCURRENTLY IF EXISTS public.'.$indexName);
            }

            try {
                $db->select("SELECT set_config('lock_timeout', ?, false)", [max(100, $lockTimeoutMs).'ms'], false);
                $db->statement($createSql);
                if (self::indexStatus($db, $indexName) === true) {
                    return;
                }
            } catch (QueryException $e) {
                if ($e->getCode() !== '55P03' || $attempt === $attempts) {
                    throw $e;
                }
                $backoffUs = min(500_000, 25_000 * (2 ** ($attempt - 1))) + random_int(0, 25_000);
                usleep($backoffUs);
            } finally {
                $db->select("SELECT set_config('lock_timeout', ?, false)", [$previousLockTimeout], false);
            }
        }

        if (self::indexStatus($db, $indexName) !== true) {
            throw new RuntimeException("Index {$indexName} failed to reach pg_index.indisvalid = true.");
        }
    }

    private static function currentLockTimeout(Connection $db): string
    {
        $value = $db->scalar("SELECT current_setting('lock_timeout')", [], false);

        return is_string($value) ? $value : '0';
    }

    private static function indexStatus(Connection $db, string $indexName): ?bool
    {
        $row = $db->selectOne(<<<'SQL'
            SELECT i.indisvalid
            FROM pg_index i
            JOIN pg_class c ON c.oid = i.indexrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public'
              AND c.relname = ?
            SQL, [$indexName], false);

        if ($row === null) {
            return null;
        }

        $data = (array) $row;

        return (bool) ($data['indisvalid'] ?? false);
    }
}
