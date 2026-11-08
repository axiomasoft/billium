<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Support\Concurrency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Illuminate\Foundation\Application;
use RuntimeException;

/** Infrastructure self-test only; domain scenarios belong to their owning module (D50). */
final readonly class ProbeScenario implements Scenario
{
    /** @param array<string, mixed> $args */
    public function __construct(Application $app, int $index, array $args)
    {
        if (($args['mode'] ?? null) === 'prepare-failure' && $index === 0) {
            throw new RuntimeException('deliberate preparation failure');
        }
        if (isset($args['workers']) && $index === $args['workers'] - 1) {
            usleep(300_000);
        }
        $app['db']->connection('pgsql')->insert('INSERT INTO billium_concurrency_ready (backend_pid) VALUES (pg_backend_pid())');
    }

    public function run(Application $app, int $index, array $args): array
    {
        $db = $app['db']->connection(config('billium.connection'));
        if (isset($args['workers']) && $db->table('billium_concurrency_ready')->count() !== $args['workers']) {
            throw new RuntimeException('Barrier released before every worker prepared');
        }
        if (($args['mode'] ?? null) === 'problem') {
            throw new ProblemException(ProblemCode::IdempotencyConflict);
        }
        if (($args['mode'] ?? null) === 'throw') {
            throw new RuntimeException('deliberate worker failure');
        }
        if (($args['mode'] ?? null) === 'invalid-json') {
            fwrite(STDOUT, "unexpected output\n");
        }
        if (($args['mode'] ?? null) === 'timeout') {
            $db->select('SELECT pg_sleep(10)');
        }
        $row = $db->selectOne('SELECT pg_backend_pid() AS pid, current_user AS role');
        $db->insert('INSERT INTO billium_concurrency_probe (worker_index, backend_pid) VALUES (?, ?)', [$index, $row->pid]);

        return ['pid' => $row->pid, 'role' => $row->role, 'transaction_level' => $db->transactionLevel(), 'argument' => $args['argument'] ?? null];
    }
}
