<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Core\Tests\DatabaseTestCase;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\Support\Concurrency\ReportDatabaseScenario;
use Billium\Core\Tests\TestEnvironment;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class SuiteResetIsolationTest extends DatabaseTestCase
{
    public function test_second_setup_keeps_migrations_and_truncates_rows(): void
    {
        $owner = $this->app['db']->connection('billium_owner');
        $before = (int) $owner->scalar('SELECT count(*) FROM public.migrations');
        $runs = TestEnvironment::migrationRuns();
        self::assertGreaterThan(0, $before);
        $owner->unprepared(<<<'SQL'
            INSERT INTO public.billium_module_state
                (installation_ref, module_id, state, config_revision, schema_version, generation)
            VALUES ('reset-isolation', 'platform', 'accepting', 1, 0, 1)
            SQL);
        $this->setUp();
        $owner = $this->app['db']->connection('billium_owner');
        self::assertSame($before, (int) $owner->scalar('SELECT count(*) FROM public.migrations'));
        self::assertSame($runs, TestEnvironment::migrationRuns());
        self::assertSame(0, (int) $owner->scalar("SELECT count(*) FROM public.billium_module_state WHERE installation_ref = 'reset-isolation'"));
    }

    public function test_concurrency_child_uses_the_worker_database(): void
    {
        $previousToken = getenv('TEST_TOKEN');
        $previousDatabase = getenv('DB_DATABASE');
        $installed = ! is_string($previousToken) || $previousToken === '';
        if ($installed) {
            putenv('TEST_TOKEN=9');
            $_ENV['TEST_TOKEN'] = '9';
            $_SERVER['TEST_TOKEN'] = '9';
        }
        try {
            TestEnvironment::isolateParallelDatabase();
            $token = getenv('TEST_TOKEN');
            self::assertIsString($token);
            $expected = 'billium_'.$token.'_test';
            self::assertSame($expected, getenv('DB_DATABASE'));
            $results = ConcurrencyStand::run(ReportDatabaseScenario::class, 1, [], 60);
            self::assertSame($expected, $results[0]['ok']['database'] ?? null);
        } finally {
            if ($installed) {
                putenv('TEST_TOKEN');
                unset($_ENV['TEST_TOKEN'], $_SERVER['TEST_TOKEN']);
                if (is_string($previousDatabase) && $previousDatabase !== '') {
                    putenv('DB_DATABASE='.$previousDatabase);
                    $_ENV['DB_DATABASE'] = $previousDatabase;
                    $_SERVER['DB_DATABASE'] = $previousDatabase;
                }
            }
        }
    }
}
