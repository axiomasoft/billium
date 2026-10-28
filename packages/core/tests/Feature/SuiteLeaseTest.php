<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Core\Tests\DatabaseTestCase;
use Billium\Core\Tests\TestEnvironment;
use PDOException;

final class SuiteLeaseTest extends DatabaseTestCase
{
    public function test_a_second_run_cannot_take_the_database_while_this_run_holds_it(): void
    {
        $other = TestEnvironment::pdo(owner: true);
        $other->exec("SET lock_timeout = '100ms'");
        $state = null;
        try {
            $other->query('SELECT pg_advisory_lock('.TestEnvironment::LEASE_CLASS.', '.TestEnvironment::LEASE_OBJECT.')');
        } catch (PDOException $exception) {
            $state = $exception->errorInfo[0] ?? null;
        }

        self::assertSame('55P03', $state, 'a concurrent runner waits instead of truncating this run');
    }

    public function test_the_lease_is_reentrant_within_the_holding_process(): void
    {
        TestEnvironment::acquireSuiteLease(timeoutSeconds: 1);

        $held = TestEnvironment::pdo(owner: true)->query(
            'SELECT count(*) FROM pg_locks WHERE locktype = \'advisory\' AND granted AND objsubid = 2'
            .' AND classid = '.TestEnvironment::LEASE_CLASS.' AND objid = '.TestEnvironment::LEASE_OBJECT,
        );
        self::assertNotFalse($held);
        self::assertSame(1, (int) $held->fetchColumn());
    }
}
