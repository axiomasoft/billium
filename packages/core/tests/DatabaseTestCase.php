<?php

declare(strict_types=1);

namespace Billium\Core\Tests;

use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Packman\Testkit\Concerns\Database\UsesSuiteDatabase;

abstract class DatabaseTestCase extends TestCase
{
    use UsesSuiteDatabase;

    private static bool $migratedInProcess = false;

    private static ?int $expectedMigrationCount = null;

    /** @var array<string, string> */
    private const array SCHEMA_SETS_BY_MODULE = [
        'platform' => 'axiomasoft/billium-core:billium-platform',
        'ledger' => 'axiomasoft/billium-core:billium-ledger',
        'usage' => 'axiomasoft/billium-core:billium-usage',
        'schedule' => 'axiomasoft/billium-core:billium-schedule',
        'rating' => 'axiomasoft/billium-core:billium-rating',
        'documents' => 'axiomasoft/billium-core:billium-documents',
        'execution' => 'axiomasoft/billium-core:billium-execution',
    ];

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
        TestEnvironment::assertDestructiveResetAllowed();
        $this->assertDestructiveSuitePgsqlAllowed('billium_owner');
        TestEnvironment::acquireSuiteLease();
        $owner = $this->app['db']->connection('billium_owner');

        if (TestEnvironment::hasProfileDatabase()) {
            $enabled = $this->app->make(BilliumOptions::class)->modules->enabled;
            $setIds = ['axiomasoft/billium-core:billium-platform'];
            foreach ($enabled as $module) {
                if (isset(self::SCHEMA_SETS_BY_MODULE[$module]) && $module !== 'platform') {
                    $setIds[] = self::SCHEMA_SETS_BY_MODULE[$module];
                }
            }
            $this->app->make(SchemaMigrator::class)->migrateSets($setIds, 'billium_owner');
            TestEnvironment::assertRuntimeSafe($this->app['db']->connection('pgsql')->getPdo());
        } else {
            $expectedCount = self::$expectedMigrationCount ??= count(glob(__DIR__.'/../database/migrations/*/*.php') ?: []);
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
                $this->artisan('packman:schema:migrate', [
                    '--scope' => 'central',
                    '--database' => 'billium_owner',
                    '--modules' => true,
                    '--force' => true,
                ])->assertExitCode(0);
                TestEnvironment::assertRuntimeSafe($this->app['db']->connection('pgsql')->getPdo());
                self::$migratedInProcess = true;
            }
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
                    WHERE schemaname = 'public'
                      AND starts_with(tablename, 'billium_')
                      AND tablename <> 'billium_event_contract'
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
