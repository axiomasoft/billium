<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Schema;

use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\TestEnvironment;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use PDO;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class HotPathIndexTest extends ExecutionTestCase
{
    private const string FEED_PREDICATE = 'source_scope::jsonb = ?::jsonb AND (tx_id,id) > (?::xid8,?::bigint)';

    protected function setUp(): void
    {
        parent::setUp();
        $this->application()->make(SchemaMigrator::class)->migrateSets([
            'axiomasoft/billium-core:billium-platform',
            'axiomasoft/billium-core:billium-ledger',
            'axiomasoft/billium-core:billium-execution',
        ], 'billium_owner');
    }

    public function test_hot_path_indexes_cover_the_query_columns(): void
    {
        $this->assertIndex('billium_outbox_event_scope_cursor', ['source_scope', 'jsonb', 'tx_id']);
        $this->assertIndex('billium_outbox_event_seller_cursor', ['seller_ref', 'environment', 'tx_id']);
        $this->assertIndex('billium_outbox_event_cursor', ['tx_id']);
        $this->assertIndex('billium_inbox_message_pending_op_idx', ['installationRef', 'ready', 'parked_gap', 'parked_dependency']);
        $this->assertIndex('billium_obligation_account_due_idx', ['billing_account_ref', 'due_at', 'open']);
        $this->assertIndex('billium_obligation_due_idx', ['due_at']);
        $this->assertIndex('billium_payment_ref_idx', ['payment_ref']);
        $this->assertIndex('billium_execution_operation_root_idx', ['intent_owner_namespace', 'root_operation_ref']);
        $this->assertIndex('billium_execution_reservation_root_idx', ['root_operation_ref']);
        $this->assertIndex('billium_execution_partition_root_idx', ['root_operation_ref']);
    }

    public function test_outbox_scope_cursor_explains_the_event_feed_predicate(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/modules/platform/src/Internal/Outbox/EventFeedReader.php');
        self::assertIsString($source);
        self::assertStringContainsString(self::FEED_PREDICATE, $source);
        self::assertStringContainsString('tx_id < pg_snapshot_xmin(pg_current_snapshot())', $source);
        self::assertStringContainsString('ORDER BY tx_id,id', $source);

        $where = str_replace(
            ['?::jsonb', '?::xid8', '?::bigint'],
            ["'{}'::jsonb", "'0'::xid8", '0::bigint'],
            self::FEED_PREDICATE,
        );
        $pdo = TestEnvironment::pdo(owner: true);
        $pdo->beginTransaction();
        try {
            $pdo->exec('SET LOCAL enable_seqscan = off');
            $statement = $pdo->query(<<<SQL
                EXPLAIN SELECT * FROM public.billium_outbox_event
                WHERE {$where}
                  AND tx_id < pg_snapshot_xmin(pg_current_snapshot())
                ORDER BY tx_id,id LIMIT 2
                SQL);
            self::assertNotFalse($statement);
            $plan = implode("\n", array_map(self::text(...), $statement->fetchAll(PDO::FETCH_COLUMN)));
            self::assertStringContainsString('billium_outbox_event_scope_cursor', $plan);
        } finally {
            $pdo->rollBack();
        }
    }

    /** @param list<string> $fragments */
    private function assertIndex(string $name, array $fragments): void
    {
        $catalog = self::text($this->value("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND indexname = ?", [$name]));
        $rebuilt = self::text($this->value("SELECT pg_get_indexdef(c.oid) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relname = ?", [$name]));
        foreach ([...$fragments, $name] as $fragment) {
            self::assertStringContainsString($fragment, $catalog, $name);
            self::assertStringContainsString($fragment, $rebuilt, $name);
        }
    }
}
