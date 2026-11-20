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

    protected function migratesFromEmptyDatabase(): bool
    {
        return false;
    }

    /** @var array<string, string> */
    private const array SCHEMA_SETS_BY_MODULE = [
        'platform' => 'axiomasoft/billium-core:billium-platform',
        'ledger' => 'axiomasoft/billium-core:billium-ledger',
        'usage' => 'axiomasoft/billium-core:billium-usage',
        'schedule' => 'axiomasoft/billium-core:billium-schedule',
        'rating' => 'axiomasoft/billium-core:billium-rating',
        'documents' => 'axiomasoft/billium-core:billium-documents',
        'execution' => 'axiomasoft/billium-core:billium-execution',
        'collection' => 'axiomasoft/billium-core:billium-collection',
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

        if ($this->migratesFromEmptyDatabase()) {
            $this->rebuildFromEmpty();
        } elseif (TestEnvironment::hasProfileDatabase()) {
            $enabled = $this->app->make(BilliumOptions::class)->modules->enabled;
            $setIds = ['axiomasoft/billium-core:billium-platform'];
            foreach ($enabled as $module) {
                if (isset(self::SCHEMA_SETS_BY_MODULE[$module]) && $module !== 'platform') {
                    $setIds[] = self::SCHEMA_SETS_BY_MODULE[$module];
                }
            }
            $disconnect = function (): void {
                foreach (array_keys($this->app['db']->getConnections()) as $name) {
                    $this->app['db']->purge($name);
                }
            };
            if (! TestEnvironment::adoptProfileTemplate($setIds, $disconnect)) {
                $this->app->make(SchemaMigrator::class)->migrateSets($setIds, 'billium_owner');
                TestEnvironment::noteMigrationRun();
                $disconnect();
                TestEnvironment::publishProfileTemplate($setIds, $disconnect);
            }
            TestEnvironment::assertRuntimeSafe($this->app['db']->connection('pgsql')->getPdo());
        } else {
            $owner = $this->app['db']->connection('billium_owner');
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
                TestEnvironment::noteMigrationRun();
                TestEnvironment::assertRuntimeSafe($this->app['db']->connection('pgsql')->getPdo());
                self::$migratedInProcess = true;
            }
        }

        $owner = $this->app['db']->connection('billium_owner');
        self::assertSame(0, $owner->transactionLevel());
        self::assertSame(0, $this->app['db']->connection('pgsql')->transactionLevel());
        TestEnvironment::truncateBilliumTables($owner->getPdo());
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

    private function rebuildFromEmpty(): void
    {
        TestEnvironment::prepareFreshRebuild($this->app);
        $paths = glob(dirname(__DIR__).'/database/migrations/*', GLOB_ONLYDIR) ?: [];
        self::assertNotSame([], $paths);
        $this->artisan('migrate:fresh', [
            '--database' => 'billium_owner',
            '--force' => true,
            '--realpath' => true,
            '--path' => $paths,
        ])->assertExitCode(0);
        TestEnvironment::noteMigrationRun();
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
