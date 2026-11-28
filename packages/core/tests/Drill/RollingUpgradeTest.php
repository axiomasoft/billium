<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Drill;

use Billium\Contracts\Data\Money;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/** Real processes and committed PostgreSQL data, simulated application generations. */
#[Group('ACC-RST-10')]
#[Group('pg')]
final class RollingUpgradeTest extends LedgerTestCase
{
    public function test_expand_mixed_workers_interrupted_backfill_validate_and_code_rollback(): void
    {
        $this->cleanupFixture();
        $refs = [];
        for ($i = 0; $i < 20; $i++) {
            $refs[] = 'rolling-'.$i;
            $this->obligation('rolling-'.$i);
        }
        $this->ledger()->declareSettlementIntent(new DeclareSettlementIntent('rolling-intent', $this->account(), 'rolling-intent', 'RUB', $refs));
        $intent = self::integer($this->value("SELECT id FROM billium_settlement_intent WHERE business_intent_ref='rolling-intent'"));
        $this->payment(100000, intent: $intent);
        $baseline = $this->rows('billium_money_commit_log');
        $this->owner()->unprepared("CREATE TABLE billium_drill_workers(id integer PRIMARY KEY, phase text NOT NULL); INSERT INTO billium_drill_workers VALUES (100,'running')");
        $children = [];
        $expanded = false;
        try {
            for ($i = 0; $i < 4; $i++) {
                $children[] = $this->start('old', $i);
            }
            $this->waitFor(fn (): bool => $this->rows('billium_drill_workers', 'id < 4') === 4);
            self::assertSame(4, $this->rows('billium_allocation'));
            $beforeExpandMax = self::integer($this->value('SELECT max(id) FROM billium_allocation'));
            $this->migrateFixture('2026_10_29_000001_expand_billium_allocation.php');
            $expanded = true;
            self::assertFalse($this->value("SELECT convalidated FROM pg_constraint WHERE conname='billium_drill_note_length'"));
            $this->owner()->update("UPDATE billium_drill_workers SET phase='expanded' WHERE id=100");
            for ($i = 0; $i < 2; $i++) {
                $children[] = $this->start('new', $i);
            }
            $this->waitFor(fn (): bool => $this->rows('billium_allocation', "drill_note='new-generation'") === 4);
            // Leave the old workers running until their four obligations each are settled.
            $this->waitFor(fn (): bool => $this->rows('billium_allocation') === 20);
            $this->owner()->update("UPDATE billium_drill_workers SET phase='stop' WHERE id=100");
            $successes = [];
            foreach ($children as &$child) {
                $result = json_decode($this->finish($child), true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($result);
                self::assertIsArray($result['successes']);
                foreach ($result['successes'] as $ref) {
                    self::assertIsString($ref);
                    $successes[] = $ref;
                }
            }
            unset($child);
            self::assertCount(20, array_unique($successes));
            self::assertSame(12, $this->rows('billium_allocation', 'id > ? AND drill_note IS NULL', [$beforeExpandMax]), 'old generation writes successfully after expand too');
            self::assertSame(20000, self::integer($this->value('SELECT sum(amount_minor) FROM billium_allocation')));
            self::assertSame(20000, self::integer($this->value('SELECT allocated_minor FROM billium_payment')));
            self::assertSame(20, $this->rows('billium_allocation_receipt', "kind='allocation'"));
            self::assertSame(20, $this->rows('billium_allocation', 'settlement_intent_id=?', [$intent]));
            self::assertSame(20, $this->rows('billium_idempotency_receipt', "command_kind='ledger.allocate' AND outcome='accepted'"));
            self::assertSame(20, $this->rows('billium_outbox_event', "type='allocation.applied.v1'"));
            // Only accepted economic roots append commits refusals retain receipts without money writes.
            self::assertSame($baseline + 20, $this->rows('billium_money_commit_log'));
            self::assertSame(20, self::integer($this->value(<<<'SQL'
                SELECT count(*) FROM billium_allocation a
                JOIN billium_allocation_receipt r ON r.receipt_ref=a.receipt_ref
                JOIN billium_obligation o ON o.id=a.obligation_id
                JOIN billium_outbox_event e ON e.aggregate_ref=o.obligation_ref AND e.type='allocation.applied.v1'
                SQL)));
            self::assertSame(20, self::integer($this->value("SELECT count(DISTINCT tx_id) FROM billium_outbox_event WHERE type='allocation.applied.v1'")));

            $before = $this->economicSnapshot();
            self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), 'UPDATE billium_allocation SET amount_minor=amount_minor+1'));
            $children[] = $this->start('backfill');
            $this->waitFor(fn (): bool => self::integer($this->value("SELECT count(*) FROM pg_stat_activity WHERE application_name='billium-rolling-backfill' AND wait_event='PgSleep'")) === 1);
            self::assertSame(1, $this->rows('billium_allocation', "drill_note='backfilled'"), 'one batch survived the crash');
            $this->owner()->select("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE application_name='billium-rolling-backfill'");
            $last = array_key_last($children);
            $this->finish($children[$last], expectedExit: 1);
            self::assertSame(0, $this->rows('billium_allocation', "drill_note='interrupted'"));
            $this->owner()->update("UPDATE billium_allocation SET drill_note='backfilled' WHERE drill_note IS NULL");
            self::assertSame(16, $this->rows('billium_allocation', "drill_note='backfilled'"));
            self::assertEquals($before, $this->economicSnapshot());
            $this->migrateFixture('2026_10_29_000002_validate_billium_allocation.php');
            self::assertTrue($this->value("SELECT convalidated FROM pg_constraint WHERE conname='billium_drill_note_length'"));
            // Roll back application knowledge only: old command and receipt still work after new writes/backfill.
            $replay = $this->ledger()->allocate(new Allocate('drill-old-0-0', $this->account(), 'drill-old-0-0', 'rolling-0', Money::parse('10.00', 'RUB'), 'payment-a'));
            self::assertTrue($replay->accepted);
            self::assertEquals($before, $this->economicSnapshot());
            self::assertTrue($this->owner()->getSchemaBuilder()->hasColumn('billium_allocation', 'drill_note'), 'contract DROP COLUMN is deferred past the rollback window');
        } finally {
            foreach ($children as $child) {
                if (is_resource($child['process'])) {
                    proc_terminate($child['process'], 9);
                    fclose($child['stdout']);
                    fclose($child['stderr']);
                    proc_close($child['process']);
                }
            }
            if ($expanded) {
                $this->cleanupFixture();
            }
            $this->owner()->unprepared('DROP TABLE IF EXISTS billium_drill_workers');
        }
    }

    private function migrateFixture(string $name): void
    {
        DB::connection('billium_owner')->transaction(static function () use ($name): void {
            $previous = DB::getDefaultConnection();
            DB::setDefaultConnection('billium_owner');
            try {
                $migration = require __DIR__.'/migrations/'.$name;
                self::assertInstanceOf(Migration::class, $migration);
                $up = [$migration, 'up'];
                self::assertIsCallable($up);
                $up();
            } finally {
                DB::setDefaultConnection($previous);
            }
        });
    }

    private function cleanupFixture(): void
    {
        // Fixture cleanup retains the expanded column; no contract migration runs in this drill.
        $this->owner()->unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS billium_drill_note ON billium_allocation;
            DROP FUNCTION IF EXISTS public.billium_drill_note();
            DROP TRIGGER IF EXISTS billium_allocation_append_only ON billium_allocation;
            CREATE TRIGGER billium_allocation_append_only BEFORE UPDATE OR DELETE ON billium_allocation
                FOR EACH ROW EXECUTE FUNCTION public.billium_append_only();
            DROP FUNCTION IF EXISTS public.billium_drill_allocation_guard();
            ALTER TABLE billium_allocation DROP CONSTRAINT IF EXISTS billium_drill_note_length;
            DROP TABLE IF EXISTS billium_drill_workers;
            SQL);
    }

    /** @return array<string, mixed> */
    private function economicSnapshot(): array
    {
        return [
            'allocation' => $this->owner()->select('SELECT id,receipt_ref,amount_minor,settlement_intent_id FROM billium_allocation ORDER BY id'),
            'receipts' => $this->owner()->select('SELECT * FROM billium_allocation_receipt ORDER BY id'),
            'commits' => $this->owner()->select('SELECT * FROM billium_money_commit_log ORDER BY commit_no'),
        ];
    }

    private function waitFor(callable $condition): void
    {
        $deadline = microtime(true) + 20;
        while (! $condition()) {
            self::assertLessThan($deadline, microtime(true), 'rolling worker coordination timed out');
            usleep(10_000);
        }
    }

    /** @return array{process: resource|null, stdout: resource, stderr: resource} */
    private function start(string $mode, int $index = 0): array
    {
        $process = proc_open([PHP_BINARY, __DIR__.'/bin/rolling-worker.php', $mode, (string) $index], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 4));
        self::assertIsResource($process);
        fclose($pipes[0]);

        return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    /** @param array{process: resource|null, stdout: resource, stderr: resource} $child */
    private function finish(array &$child, int $expectedExit = 0): string
    {
        self::assertIsResource($child['process']);
        $output = stream_get_contents($child['stdout']);
        $errors = stream_get_contents($child['stderr']);
        fclose($child['stdout']);
        fclose($child['stderr']);
        $exit = proc_close($child['process']);
        $child['process'] = null;
        self::assertSame($expectedExit, $exit, $errors);
        self::assertIsString($output);

        return $output;
    }
}
