<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Core\Tests\DatabaseTestCase;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\Support\Concurrency\ProbeScenario;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('REQ-ACC-11')]
#[Group('pg')]
final class ConcurrencyStandTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $owner = $this->app['db']->connection('billium_owner');
        $owner->unprepared('CREATE TABLE IF NOT EXISTS billium_concurrency_probe (worker_index integer PRIMARY KEY, backend_pid integer NOT NULL)');
        $owner->unprepared('CREATE TABLE IF NOT EXISTS billium_concurrency_ready (backend_pid integer PRIMARY KEY)');
        $runtime = $this->app['db']->connection('pgsql')->selectOne('SELECT current_user AS role')->role;
        $owner->unprepared('GRANT INSERT, SELECT ON billium_concurrency_probe, billium_concurrency_ready TO "'.str_replace('"', '""', $runtime).'"');
    }

    protected function tearDown(): void
    {
        try {
            $this->app['db']->connection('billium_owner')->unprepared('DROP TABLE IF EXISTS billium_concurrency_probe, billium_concurrency_ready');
        } finally {
            parent::tearDown();
        }
    }

    public function test_twenty_processes_cross_the_barrier_and_commit_without_test_transactions(): void
    {
        $results = ConcurrencyStand::run(ProbeScenario::class, 20, ['argument' => 'round trip', 'workers' => 20]);
        self::assertSame(range(0, 19), array_column($results, 'index'));
        self::assertCount(20, array_unique(array_map(static fn (array $result): int => $result['ok']['pid'], $results)));
        $runtime = $this->app['db']->connection('pgsql')->selectOne('SELECT current_user AS role')->role;
        foreach ($results as $result) {
            self::assertSame($runtime, $result['ok']['role']);
            self::assertSame(0, $result['ok']['transaction_level']);
            self::assertSame('round trip', $result['ok']['argument']);
        }
        self::assertSame(20, $this->app['db']->connection('pgsql')->table('billium_concurrency_probe')->count());
    }

    public function test_registered_problem_is_a_result_for_each_worker(): void
    {
        $results = ConcurrencyStand::run(ProbeScenario::class, 2, ['mode' => 'problem']);
        self::assertSame([
            ['index' => 0, 'error' => ['code' => 'billium.idempotency.conflict']],
            ['index' => 1, 'error' => ['code' => 'billium.idempotency.conflict']],
        ], $results);
    }

    public function test_unexpected_worker_failure_is_red_with_stderr(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('deliberate worker failure');
        ConcurrencyStand::run(ProbeScenario::class, 2, ['mode' => 'throw']);
    }

    public function test_stdout_noise_is_rejected_as_invalid_json(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON');
        ConcurrencyStand::run(ProbeScenario::class, 1, ['mode' => 'invalid-json']);
    }

    public function test_preparation_failure_is_red_before_releasing_the_barrier(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exited before the barrier');
        ConcurrencyStand::run(ProbeScenario::class, 2, ['mode' => 'prepare-failure']);
    }

    public function test_timeout_kills_workers_and_their_database_sessions(): void
    {
        try {
            ConcurrencyStand::run(ProbeScenario::class, 2, ['mode' => 'timeout'], timeoutSeconds: 2);
            self::fail('Timeout must fail the stand');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Timed out', $exception->getMessage());
        }
        $owner = $this->app['db']->connection('billium_owner');
        $deadline = microtime(true) + 2;
        do {
            $sleepers = (int) $owner->selectOne("SELECT count(*) AS count FROM pg_stat_activity WHERE datname = current_database() AND query = 'SELECT pg_sleep(10)' AND state = 'active'")->count;
            if ($sleepers === 0) {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        self::assertSame(0, $sleepers, 'Timed-out worker sessions must not keep running SQL');
        self::assertSame(0, $owner->table('billium_concurrency_probe')->count());
    }
}
