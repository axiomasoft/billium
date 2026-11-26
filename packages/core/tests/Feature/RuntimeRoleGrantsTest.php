<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Core\Tests\Arch\MigrationTables;
use Billium\Core\Tests\DatabaseTestCase;
use Billium\Core\Tests\TestEnvironment;
use Illuminate\Database\DatabaseManager;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('D60')]
#[Group('pg')]
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
        self::assertSame(0, self::scalar($owner, 'SELECT count(*) FROM billium_truncate_probe'));
        self::assertSame(1, self::scalar($owner, 'INSERT INTO billium_truncate_probe DEFAULT VALUES RETURNING id'));
        self::assertNotNull($this->app);
        self::assertSame(0, $this->app->make(DatabaseManager::class)->connection('pgsql')->transactionLevel());
        self::assertSame('migrations', self::scalar($owner, "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename = 'migrations'"));
    }

    public function test_runtime_has_no_owner_powers(): void
    {
        $runtime = TestEnvironment::pdo();
        TestEnvironment::assertRuntimeSafe($runtime);
        self::assertNotSame(
            self::scalar(TestEnvironment::pdo(owner: true), 'SELECT current_user'),
            self::scalar($runtime, 'SELECT current_user'),
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('D60: runtime must not own');
        TestEnvironment::assertRuntimeSafe(TestEnvironment::pdo(owner: true));
    }

    public function test_guarded_routine_works_while_direct_dml_is_denied(): void
    {
        $owner = TestEnvironment::pdo(owner: true);
        $runtime = TestEnvironment::pdo();
        $name = self::scalar($runtime, 'SELECT current_user');
        self::assertIsString($name);
        $role = '"'.str_replace('"', '""', $name).'"';
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
            self::assertSame(7, self::scalar($runtime, 'SELECT public.billium_p3_role_probe_apply(7)'));
            try {
                $runtime->query('SELECT public.billium_p3_role_probe_apply(-1)');
                self::fail('Guarded routine must reject invalid input');
            } catch (PDOException $exception) {
                self::assertSame('22023', $exception->getCode());
            }
            self::assertSame(7, self::scalar($runtime, 'SELECT value FROM public.billium_p3_role_probe WHERE id = 1'));
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

    public function test_actual_ledger_tables_deny_raw_writes_and_routines_have_safe_owners(): void
    {
        $runtime = TestEnvironment::pdo();
        $owner = TestEnvironment::pdo(owner: true);
        TestEnvironment::assertRuntimeSafe($runtime);
        $column = $owner->prepare(<<<'SQL'
            SELECT pg_catalog.quote_ident(attname)
            FROM pg_catalog.pg_attribute
            WHERE attrelid = pg_catalog.to_regclass(:relation) AND attnum > 0 AND NOT attisdropped
            ORDER BY attnum LIMIT 1
            SQL);
        foreach (MigrationTables::ownership(dirname(__DIR__, 2).'/database/migrations') as $table => $module) {
            if ($module !== 'ledger') {
                continue;
            }
            $relation = 'public."'.str_replace('"', '""', $table).'"';
            $column->execute(['relation' => $relation]);
            $identifier = $column->fetchColumn();
            self::assertIsString($identifier, $relation);
            foreach ([
                'INSERT INTO '.$relation.' DEFAULT VALUES',
                'UPDATE '.$relation.' SET '.$identifier.' = DEFAULT WHERE false',
                'DELETE FROM '.$relation.' WHERE false',
                'TRUNCATE '.$relation.' CASCADE',
            ] as $sql) {
                $this->assertPermissionDenied($runtime, $sql);
            }
        }
        $query = $owner->prepare(<<<'SQL'
            SELECT p.proname, p.proconfig, r.rolname AS owner,
                EXISTS (SELECT FROM aclexplode(coalesce(p.proacl,acldefault('f',p.proowner))) a
                        WHERE a.grantee=0 AND a.privilege_type='EXECUTE') AS public_execute
            FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace JOIN pg_roles r ON r.oid=p.proowner
            WHERE n.nspname='public' AND p.proname LIKE 'billium_%' AND p.prosecdef
            SQL);
        $query->execute();
        $routines = $query->fetchAll(PDO::FETCH_ASSOC);
        self::assertNotEmpty($routines);
        $role = self::scalar($runtime, 'SELECT current_user');
        foreach ($routines as $routine) {
            self::assertIsArray($routine);
            self::assertIsString($routine['proname']);
            self::assertIsString($routine['proconfig']);
            self::assertNotSame($role, $routine['owner'], $routine['proname']);
            self::assertStringContainsString('search_path=pg_catalog, pg_temp', $routine['proconfig'], $routine['proname']);
            self::assertFalse($routine['public_execute'], $routine['proname']);
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

    private static function scalar(PDO $pdo, string $sql): mixed
    {
        $statement = $pdo->prepare($sql);
        $statement->execute();

        return $statement->fetchColumn();
    }
}
