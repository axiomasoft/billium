<?php

declare(strict_types=1);

namespace Billium\Core\Tests;

use Billium\Core\BilliumCoreServiceProvider;
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

        TestEnvironment::preflight();
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
    }
}
