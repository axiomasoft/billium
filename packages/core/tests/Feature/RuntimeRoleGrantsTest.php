<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Core\Tests\DatabaseTestCase;
use Billium\Core\Tests\TestEnvironment;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('D60')]
final class RuntimeRoleGrantsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        // Seed before the base setup: this proves isolation runs without a transaction wrapper.
        $owner = TestEnvironment::pdo(owner: true);
        $owner->exec('CREATE TABLE IF NOT EXISTS billium_truncate_probe (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY)');
        $owner->exec('INSERT INTO billium_truncate_probe DEFAULT VALUES');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            TestEnvironment::pdo(owner: true)->exec('DROP TABLE IF EXISTS billium_truncate_probe');
        } finally {
            parent::tearDown();
        }
    }

    public function test_setup_truncates_owner_fixtures_and_restarts_identity(): void
    {
        $owner = TestEnvironment::pdo(owner: true);
        self::assertSame(0, $owner->query('SELECT count(*) FROM billium_truncate_probe')->fetchColumn());
        self::assertSame(1, $owner->query('INSERT INTO billium_truncate_probe DEFAULT VALUES RETURNING id')->fetchColumn());
        self::assertSame(0, $this->app['db']->connection('pgsql')->transactionLevel());
        self::assertSame('migrations', $owner->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename = 'migrations'")->fetchColumn());
    }

    public function test_runtime_has_no_owner_powers(): void
    {
        $runtime = TestEnvironment::pdo();
        TestEnvironment::assertRuntimeSafe($runtime);
        self::assertNotSame(
            TestEnvironment::pdo(owner: true)->query('SELECT current_user')->fetchColumn(),
            $runtime->query('SELECT current_user')->fetchColumn(),
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('D60: runtime must not own');
        TestEnvironment::assertRuntimeSafe(TestEnvironment::pdo(owner: true));
    }

    public function test_guarded_routine_works_while_direct_dml_is_denied(): void
    {
        $owner = TestEnvironment::pdo(owner: true);
        $runtime = TestEnvironment::pdo();
        $role = '"'.str_replace('"', '""', $runtime->query('SELECT current_user')->fetchColumn()).'"';
        try {
            $owner->exec('CREATE TABLE billium_p3_role_probe (id integer PRIMARY KEY, value integer NOT NULL)');
            $owner->exec('INSERT INTO billium_p3_role_probe VALUES (1, 0)');
            $owner->exec(<<<'SQL'
                CREATE FUNCTION public.billium_p3_role_probe_apply(delta integer) RETURNS integer
                LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$
                BEGIN
                    IF delta < 0 THEN RAISE EXCEPTION 'negative probe delta' USING ERRCODE = '22023'; END IF;
                    UPDATE public.billium_p3_role_probe SET value = value + delta WHERE id = 1;
                    RETURN (SELECT value FROM public.billium_p3_role_probe WHERE id = 1);
                END $$
                SQL);
            $owner->exec('REVOKE ALL ON FUNCTION public.billium_p3_role_probe_apply(integer) FROM PUBLIC');
            $owner->exec('GRANT SELECT ON public.billium_p3_role_probe TO '.$role);
            $owner->exec('GRANT EXECUTE ON FUNCTION public.billium_p3_role_probe_apply(integer) TO '.$role);
            TestEnvironment::assertRuntimeSafe($runtime, ['billium_p3_role_probe']);
            foreach ([
                'INSERT INTO public.billium_p3_role_probe VALUES (2, 0)',
                'UPDATE public.billium_p3_role_probe SET value = 99 WHERE id = 1',
                'DELETE FROM public.billium_p3_role_probe WHERE id = 1',
                'TRUNCATE public.billium_p3_role_probe',
            ] as $sql) {
                $this->assertPermissionDenied($runtime, $sql);
            }
            self::assertSame(7, $runtime->query('SELECT public.billium_p3_role_probe_apply(7)')->fetchColumn());
            try {
                $runtime->query('SELECT public.billium_p3_role_probe_apply(-1)');
                self::fail('Guarded routine must reject invalid input');
            } catch (PDOException $exception) {
                self::assertSame('22023', $exception->getCode());
            }
            self::assertSame(7, $runtime->query('SELECT value FROM public.billium_p3_role_probe WHERE id = 1')->fetchColumn());
            $owner->exec('GRANT UPDATE (value) ON public.billium_p3_role_probe TO '.$role);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('direct DML grants');
            TestEnvironment::assertRuntimeSafe($runtime, ['billium_p3_role_probe']);
        } finally {
            $owner->exec('DROP FUNCTION IF EXISTS public.billium_p3_role_probe_apply(integer)');
            $owner->exec('DROP TABLE IF EXISTS public.billium_p3_role_probe');
        }
    }

    public function test_database_name_guard_refuses_non_test_database(): void
    {
        $previous = $_ENV['DB_DATABASE'] ?? null;
        try {
            $_ENV['DB_DATABASE'] = 'billium_production';
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('must end in _test');
            TestEnvironment::databaseName();
        } finally {
            if ($previous === null) {
                unset($_ENV['DB_DATABASE']);
            } else {
                $_ENV['DB_DATABASE'] = $previous;
            }
        }
    }

    private function assertPermissionDenied(PDO $runtime, string $sql): void
    {
        try {
            $runtime->exec($sql);
            self::fail('Runtime unexpectedly executed: '.$sql);
        } catch (PDOException $exception) {
            self::assertSame('42501', $exception->getCode());
        }
    }
}
