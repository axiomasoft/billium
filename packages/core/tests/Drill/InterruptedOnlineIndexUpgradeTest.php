<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Drill;

use Billium\Core\Database\OnlineDdl;
use Billium\Platform\Tests\PlatformTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class InterruptedOnlineIndexUpgradeTest extends PlatformTestCase
{
    public function test_interrupted_invalid_concurrent_index_is_dropped_and_rebuilt_cleanly(): void
    {
        $owner = self::connection('billium_owner');
        $indexName = 'idx_billium_drill_concurrent_test';

        $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS public.'.$indexName);
        // Simulate an interrupted CREATE INDEX CONCURRENTLY that left an INVALID index behind
        $owner->statement('CREATE INDEX '.$indexName.' ON public.billium_feed_command_lease (seller_ref, environment)');
        $owner->update(<<<'SQL'
            UPDATE pg_index
            SET indisvalid = false
            WHERE indexrelid = (
                SELECT c.oid
                FROM pg_class c
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' AND c.relname = ?
            )
            SQL, [$indexName]);

        $before = $owner->scalar(<<<'SQL'
            SELECT i.indisvalid
            FROM pg_index i
            JOIN pg_class c ON c.oid = i.indexrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relname = ?
            SQL, [$indexName]);
        self::assertFalse((bool) $before);

        OnlineDdl::createIndexConcurrently(
            $owner,
            $indexName,
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$indexName.' ON public.billium_feed_command_lease (seller_ref, environment)',
        );

        $after = $owner->scalar(<<<'SQL'
            SELECT i.indisvalid
            FROM pg_index i
            JOIN pg_class c ON c.oid = i.indexrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relname = ?
            SQL, [$indexName]);
        self::assertTrue((bool) $after);

        $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS public.'.$indexName);
    }

    public function test_the_session_lock_timeout_is_restored_after_the_build(): void
    {
        $owner = self::connection('billium_owner');
        $indexName = 'idx_billium_drill_lock_timeout_test';
        $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS public.'.$indexName);
        $owner->statement("SET lock_timeout = '7s'");

        try {
            OnlineDdl::createIndexConcurrently(
                $owner,
                $indexName,
                'CREATE INDEX CONCURRENTLY '.$indexName.' ON public.billium_feed_command_lease (environment)',
                lockTimeoutMs: 500,
            );

            self::assertSame('7s', $owner->scalar("SELECT current_setting('lock_timeout')"));
        } finally {
            $owner->statement('RESET lock_timeout');
            $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS public.'.$indexName);
        }
    }

    public function test_a_blocking_create_index_statement_is_refused(): void
    {
        $this->expectException(LogicException::class);

        OnlineDdl::createIndexConcurrently(
            self::connection('billium_owner'),
            'idx_billium_drill_blocking_test',
            'CREATE INDEX idx_billium_drill_blocking_test ON public.billium_feed_command_lease (environment)',
        );
    }
}
