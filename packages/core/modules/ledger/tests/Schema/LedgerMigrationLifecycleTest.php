<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Schema;

use Billium\Core\Data\Modules\MigrationIdentity;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Data\DeclareSettlementIntent;
use Illuminate\Testing\PendingCommand;

final class LedgerMigrationLifecycleTest extends LedgerTestCase
{
    public function test_rebuilding_ledger_tables_refreshes_retained_functions_and_runtime_grants(): void
    {
        $ledger = ModuleCatalog::packaged()->descriptor('ledger');
        self::assertNotNull($ledger);
        $owner = $this->owner();
        // Reproduce a retained routine from an earlier migration body, even though its file is now correct.
        $owner->unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.billium_declare_settlement_intent(
                p_installation text,p_seller text,p_environment text,p_account text,p_currency text,p_ref text,p_policy text,p_targets bigint[],p_digest text)
            RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, pg_temp AS $$
            BEGIN
                PERFORM captured_minor FROM public.billium_settlement_target;
                RETURN '{}'::jsonb;
            END $$
            SQL);
        self::assertSame('42703', self::sqlState(TestEnvironment::pdo(), 'SELECT public.billium_declare_settlement_intent(NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL)'));

        // Like migrate:fresh, drop tables while retaining standalone functions; platform fixtures stay intact.
        $tables = array_map(static fn (string $table): string => 'public."'.$table.'"', $ledger->ownedTables);
        $migrations = array_map(static fn (MigrationIdentity $migration): string => $migration->id, $ledger->migrationIdentities);
        for ($pass = 0; $pass < 2; $pass++) {
            $owner->getPdo()->exec('DROP TABLE '.implode(', ', $tables).' CASCADE');
            $owner->table('migrations')->whereIn('migration', $migrations)->delete();
            $command = $this->artisan('migrate', ['--database' => 'billium_owner', '--force' => true]);
            self::assertInstanceOf(PendingCommand::class, $command);
            $command->assertExitCode(0)->run();
            TestEnvironment::assertRuntimeSafe(TestEnvironment::pdo());
            self::assertSame('42501', self::sqlState(TestEnvironment::pdo(), "SELECT public.billium_ledger_json_safe('{}'::jsonb)"));

            // Platform command receipts survive this ledger-only rebuild, so use fresh command identities each pass.
            $a = $this->obligation('a-'.$pass);
            $b = $this->obligation('b-'.$pass);
            $command = new DeclareSettlementIntent('intent-'.$pass, $this->account(), 'business-'.$pass, 'RUB', ['b-'.$pass, 'a-'.$pass]);
            $first = $this->ledger()->declareSettlementIntent($command);
            self::assertSame($first->body, $this->ledger()->declareSettlementIntent($command)->body);
            $targets = $this->table('SELECT * FROM billium_settlement_target ORDER BY position');
            $digest = self::text($this->value('SELECT semantic_digest FROM billium_settlement_intent'));
            $replay = $this->routine('declare_settlement_intent', [...$this->scopeArguments(), 'business-'.$pass, 'pro_rata_open/1', '{'.$b.','.$a.'}', $digest]);
            self::assertTrue($replay['replayed']);
            self::assertEquals($first->body, $replay['body']);
            self::assertSame($targets, $this->table('SELECT * FROM billium_settlement_target ORDER BY position'));
            self::assertSame(1, self::integer($this->value('SELECT revision FROM billium_settlement_intent')));
            self::assertSame(2, $this->rows('billium_settlement_target'));
            self::assertSame(1, $this->rows('billium_settlement_intent'));
            self::assertSame(1, $this->rows('billium_allocation_receipt', "kind='settlement_intent'"));
        }
    }
}
