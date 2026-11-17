<?php

declare(strict_types=1);

namespace Billium\Core\Tests;

use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    private static bool $migratedInProcess = false;

    private static ?int $expectedMigrationCount = null;

    protected function getPackageProviders($app): array
    {
        return [BilliumCoreServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        TestEnvironment::configure($app);
    }

    protected function setUp(): void
    {
        // D50/INV-03: reject wrappers before Testbench has a chance to begin a transaction.
        $traits = class_uses_recursive(static::class);
        self::assertNotContains(RefreshDatabase::class, $traits);
        self::assertNotContains(DatabaseTransactions::class, $traits);
        parent::setUp();
        SimulatedFinancialAuthorityEvidence::reset();
        // D50: without a wrapper trait nothing else closes this application's connections; left to the cycle
        // collector they outlive the test, pile up across the suite and exhaust PostgreSQL connection slots.
        $databases = $this->app['db'];
        $this->beforeApplicationDestroyed(static function () use ($databases): void {
            foreach (array_keys($databases->getConnections()) as $name) {
                $databases->purge($name);
            }
        });

        TestEnvironment::preflight();
        TestEnvironment::acquireSuiteLease();
        $expectedCount = self::$expectedMigrationCount ??= count(glob(__DIR__.'/../database/migrations/*/*.php') ?: []);
        $owner = $this->app['db']->connection('billium_owner');
        $needsMigration = ! self::$migratedInProcess;
        if (! $needsMigration) {
            try {
                $needsMigration = (int) $owner->scalar('SELECT count(*) FROM public.migrations') !== $expectedCount;
            } catch (QueryException $exception) {
                // PostgreSQL resolves relations before evaluating CASE/to_regclass guards.
                if ($exception->getCode() !== '42P01') {
                    throw $exception;
                }
                $needsMigration = true;
            }
        }
        if ($needsMigration) {
            $this->artisan('migrate', ['--database' => 'billium_owner', '--force' => true])->assertExitCode(0);
            TestEnvironment::assertRuntimeSafe($this->app['db']->connection('pgsql')->getPdo());
            self::$migratedInProcess = true;
        }

        self::assertSame(0, $owner->transactionLevel());
        self::assertSame(0, $this->app['db']->connection('pgsql')->transactionLevel());
        $owner->unprepared(<<<'SQL'
            DO $$
            DECLARE
                r record;
                has_rows boolean;
                non_empty_tables text[] := ARRAY[]::text[];
            BEGIN
                FOR r IN SELECT tablename FROM pg_tables
                    WHERE schemaname = 'public' AND tablename NOT IN ('migrations', 'billium_event_contract')
                    ORDER BY tablename
                LOOP
                    EXECUTE format('SELECT EXISTS (SELECT 1 FROM public.%I LIMIT 1)', r.tablename) INTO has_rows;
                    IF has_rows THEN
                        non_empty_tables := array_append(non_empty_tables, format('public.%I', r.tablename));
                    END IF;
                END LOOP;
                IF cardinality(non_empty_tables) > 0 THEN
                    EXECUTE 'TRUNCATE ' || array_to_string(non_empty_tables, ', ') || ' RESTART IDENTITY CASCADE';
                END IF;
            END $$
            SQL);
        // Keep the fixed v1 migration backfill intact unless a test changed it.
        $contractsIntact = $owner->scalar(<<<'SQL'
            SELECT (SELECT count(*) FROM public.billium_event_contract) = 7 AND NOT EXISTS (
                SELECT 1 FROM public.billium_event_contract
                WHERE contract_revision <> 'events-2026-10-v1' OR status <> 'registered'
            )
            SQL);
        if (! $contractsIntact) {
            $owner->unprepared('TRUNCATE public.billium_event_contract RESTART IDENTITY CASCADE');
            $modules = $this->app->make(ModuleRegistry::class);
            $ledger = $modules->descriptor('ledger');
            if ($ledger !== null) {
                $registrar = new DatabaseEventContractRegistrar($owner, $modules);
                $registrar->register($registrar->plan($ledger, true));
            }
        }
    }
}
