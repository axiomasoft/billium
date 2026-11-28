<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Core\Tests\DatabaseTestCase;
use Billium\Core\Tests\TestEnvironment;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;

#[Group('pg')]
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
            .' AND database = (SELECT oid FROM pg_database WHERE datname = current_database())'
            .' AND classid = '.TestEnvironment::LEASE_CLASS.' AND objid = '.TestEnvironment::LEASE_OBJECT,
        );
        self::assertNotFalse($held);
        self::assertSame(1, (int) $held->fetchColumn());
    }

    public function test_a_child_runner_joins_the_live_parent_lease(): void
    {
        $root = dirname(__DIR__, 4);
        $probe = new Process([
            PHP_BINARY, $root.'/conformance/adapters/php/bin/with-database-lease.php', PHP_BINARY, '-r',
            'require "vendor/autoload.php"; Billium\\Core\\Tests\\TestEnvironment::acquireSuiteLease(1); echo "joined";',
        ], $root, TestEnvironment::suiteLeaseEnvironment(), timeout: 5);

        $probe->mustRun();

        self::assertSame('joined', $probe->getOutput());
        $this->test_a_second_run_cannot_take_the_database_while_this_run_holds_it();
    }

    public function test_a_forged_parent_lease_does_not_bypass_exclusion(): void
    {
        $environment = TestEnvironment::suiteLeaseEnvironment();
        $holder = json_decode($environment['BILLIUM_TEST_SUITE_LEASE'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($holder);
        $holder['token'] = 'not-the-holder';
        $environment['BILLIUM_TEST_SUITE_LEASE'] = json_encode($holder, JSON_THROW_ON_ERROR);
        $probe = new Process([PHP_BINARY, '-r',
            'require "vendor/autoload.php"; Billium\\Core\\Tests\\TestEnvironment::acquireSuiteLease(1);',
        ], dirname(__DIR__, 4), $environment, timeout: 5);

        self::assertNotSame(0, $probe->run());
        self::assertStringContainsString('Inherited test database lease', $probe->getErrorOutput().$probe->getOutput());
    }

    public function test_template_cleanup_keeps_a_database_that_holds_a_suite_lease(): void
    {
        $admin = TestEnvironment::pdo(owner: true);
        $leasedName = 'billium_cleanupleased_test';
        $openName = 'billium_cleanupopen_test';
        $leased = null;
        $drop = static function (string $name) use ($admin): void {
            $quoted = '"'.str_replace('"', '""', $name).'"';
            $admin->exec('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '.$admin->quote($name).' AND pid <> pg_backend_pid()');
            $admin->exec('DROP DATABASE IF EXISTS '.$quoted.' WITH (FORCE)');
        };
        $drop($leasedName);
        $drop($openName);
        try {
            $admin->exec('CREATE DATABASE "'.$leasedName.'"');
            $admin->exec('CREATE DATABASE "'.$openName.'"');
            $config = TestEnvironment::connection(owner: true);
            $leased = new PDO(
                "pgsql:host={$config['host']};port={$config['port']};dbname={$leasedName}",
                $config['username'],
                $config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            $leased->query('SELECT pg_advisory_lock('.TestEnvironment::LEASE_CLASS.', '.TestEnvironment::LEASE_OBJECT.')');

            $targets = TestEnvironment::unleasedParallelDatabases($admin);

            self::assertContains($openName, $targets);
            self::assertNotContains($leasedName, $targets);
            self::assertNotContains(TestEnvironment::databaseName(), $targets);
        } finally {
            $leased = null;
            $drop($leasedName);
            $drop($openName);
        }
    }
}
