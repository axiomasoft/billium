<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Core\Tests\DatabaseTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class ConnectionReleaseTest extends DatabaseTestCase
{
    /** @return list<int> */
    public function test_a_test_application_holds_its_own_backends(): array
    {
        $pids = [];
        foreach (['pgsql', 'billium_owner'] as $name) {
            $pids[] = (int) $this->app['db']->connection($name)->selectOne('SELECT pg_backend_pid() AS pid')->pid;
        }

        self::assertCount(2, array_unique($pids));

        return $pids;
    }

    /** @param list<int> $pids */
    #[Depends('test_a_test_application_holds_its_own_backends')]
    public function test_teardown_closes_the_backends_of_the_previous_application(array $pids): void
    {
        $owner = $this->app['db']->connection('billium_owner');
        $deadline = microtime(true) + 2;
        do {
            // A backend leaves pg_stat_activity shortly after its client disconnects.
            $alive = (int) $owner->selectOne(
                'SELECT count(*) AS alive FROM pg_stat_activity WHERE pid = ANY (?::int[])',
                ['{'.implode(',', $pids).'}'],
            )->alive;
            if ($alive === 0 || microtime(true) >= $deadline) {
                break;
            }
            usleep(20_000);
        } while (true);

        self::assertSame(0, $alive, 'D50: a finished test must not keep PostgreSQL connection slots');
    }
}
