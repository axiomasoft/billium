<?php

declare(strict_types=1);

namespace Billium\Core\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use LogicException;
use Packman\Attributes\Internal;
use RuntimeException;

#[Internal]
final readonly class OnlineDdl
{
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

        for ($attempt = 1; $attempt <= max(1, $maxRetries); $attempt++) {
            $status = self::indexStatus($db, $indexName);
            if ($status === true) {
                return;
            }
            if ($status === false) {
                $db->statement('DROP INDEX CONCURRENTLY IF EXISTS public.'.$indexName);
            }

            try {
                $db->statement("SET lock_timeout = '".max(100, $lockTimeoutMs)."ms'");
                $db->statement($createSql);
                if (self::indexStatus($db, $indexName) === true) {
                    return;
                }
            } catch (QueryException $e) {
                if ($e->getCode() !== '55P03' || $attempt === $maxRetries) {
                    throw $e;
                }
                $backoffUs = min(500_000, 25_000 * (2 ** ($attempt - 1))) + random_int(0, 25_000);
                usleep($backoffUs);
            } finally {
                $db->statement('RESET lock_timeout');
            }
        }

        if (self::indexStatus($db, $indexName) !== true) {
            throw new RuntimeException("Index {$indexName} failed to reach pg_index.indisvalid = true.");
        }
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
