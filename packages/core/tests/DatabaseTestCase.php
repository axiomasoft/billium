<?php

declare(strict_types=1);

namespace Billium\Core\Tests;

use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase;

abstract class DatabaseTestCase extends TestCase
{
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
        $this->artisan('migrate', ['--database' => 'billium_owner', '--force' => true])->assertExitCode(0);
        TestEnvironment::assertRuntimeSafe($this->app['db']->connection('pgsql')->getPdo());

        $owner = $this->app['db']->connection('billium_owner');
        self::assertSame(0, $owner->transactionLevel());
        self::assertSame(0, $this->app['db']->connection('pgsql')->transactionLevel());
        $tables = $owner->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations' ORDER BY tablename");
        if ($tables !== []) {
            $names = array_map(static fn (object $table): string => '"public"."'.str_replace('"', '""', $table->tablename).'"', $tables);
            $owner->unprepared('TRUNCATE '.implode(', ', $names).' RESTART IDENTITY CASCADE');
        }
        // Restore only the fixed v1 migration backfill after fixture TRUNCATE; no runtime boot registration.
        if ($owner->getSchemaBuilder()->hasTable('billium_event_contract')) {
            $modules = $this->app->make(ModuleRegistry::class);
            $ledger = $modules->descriptor('ledger');
            if ($ledger !== null) {
                $registrar = new DatabaseEventContractRegistrar($owner, $modules);
                $registrar->register($registrar->plan($ledger, true));
            }
        }
    }
}
