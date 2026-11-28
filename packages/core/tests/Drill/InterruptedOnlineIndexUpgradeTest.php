<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Drill;

use Billium\Core\Database\OnlineDdl;
use Billium\Platform\Tests\PlatformTestCase;
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
}
