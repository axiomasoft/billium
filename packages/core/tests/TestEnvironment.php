<?php

declare(strict_types=1);

namespace Billium\Core\Tests;

use Billium\Core\Tests\Arch\MigrationTables;
use Illuminate\Foundation\Application;
use PDO;
use PDOException;
use RuntimeException;

final class TestEnvironment
{
    public static function configure(Application $app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', self::connection());
        $app['config']->set('database.connections.billium_owner', self::connection(owner: true));
        $app['config']->set('billium.connection', 'pgsql');
    }

    public static function databaseName(): string
    {
        $database = self::required('DB_DATABASE');
        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException('D50: DB_DATABASE must end in _test');
        }

        return $database;
    }

    /** @return array<string, mixed> */
    public static function connection(bool $owner = false): array
    {
        return [
            'driver' => 'pgsql',
            'host' => self::required('DB_HOST'),
            'port' => self::required('DB_PORT'),
            'database' => self::databaseName(),
            'username' => self::required($owner ? 'DB_OWNER_USERNAME' : 'DB_USERNAME'),
            'password' => self::required($owner ? 'DB_OWNER_PASSWORD' : 'DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }

    public static function pdo(bool $owner = false): PDO
    {
        $config = self::connection($owner);

        return new PDO(
            "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']};connect_timeout=2",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    public static function preflight(int $timeoutSeconds = 30): void
    {
        self::databaseName();
        self::connection();
        self::connection(owner: true);
        if (! extension_loaded('pdo_pgsql')) {
            throw new RuntimeException('D50/M-33: pdo_pgsql is required');
        }
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            try {
                $runtime = self::pdo();
                $owner = self::pdo(owner: true);
                break;
            } catch (PDOException $exception) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('M-33: PostgreSQL test database unavailable', previous: $exception);
                }
                usleep(200_000);
            }
        } while (true);

        if ((int) $runtime->query("SELECT current_setting('server_version_num')")->fetchColumn() < 160000) {
            throw new RuntimeException('D50: PostgreSQL 16 or newer is required');
        }
        if ($runtime->query('SELECT current_user')->fetchColumn() === $owner->query('SELECT current_user')->fetchColumn()) {
            throw new RuntimeException('D60: runtime and fixture owner must be different roles');
        }
        self::assertRuntimeSafe($runtime);
    }

    /** @param list<string>|null $protectedTables */
    public static function assertRuntimeSafe(PDO $runtime, ?array $protectedTables = null): void
    {
        $unsafe = $runtime->query(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM pg_roles r
                WHERE pg_has_role(current_user, r.oid, 'MEMBER')
                  AND (r.rolsuper OR r.rolcreatedb OR r.rolcreaterole OR r.rolreplication OR r.rolbypassrls
                       OR r.rolname = 'pg_write_all_data')
            ) OR EXISTS (
                SELECT 1 FROM pg_namespace n WHERE n.nspname = 'public'
                  AND pg_has_role(current_user, n.nspowner, 'MEMBER')
            ) OR EXISTS (
                SELECT 1 FROM pg_database d WHERE d.datname = current_database()
                  AND pg_has_role(current_user, d.datdba, 'MEMBER')
            ) OR EXISTS (
                SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' AND pg_has_role(current_user, c.relowner, 'MEMBER')
            ) OR has_schema_privilege(current_user, 'public', 'CREATE')
              OR has_database_privilege(current_user, current_database(), 'CREATE')
              OR has_database_privilege(current_user, current_database(), 'TEMP')
            SQL)->fetchColumn();
        if ($unsafe) {
            throw new RuntimeException('D60: runtime must not own schema/objects, inherit owner powers or create objects');
        }

        $protectedTables ??= array_keys(array_filter(
            MigrationTables::ownership(dirname(__DIR__).'/database/migrations'),
            static fn (string $module): bool => $module === 'ledger',
        ));
        $probe = $runtime->prepare(<<<'SQL'
            SELECT has_table_privilege(current_user, c.oid, 'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')
                OR has_any_column_privilege(current_user, c.oid, 'INSERT,UPDATE,REFERENCES')
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relname = :table
            SQL);
        foreach ($protectedTables as $table) {
            $probe->execute(['table' => $table]);
            if ($probe->fetchColumn()) {
                throw new RuntimeException("D60: runtime has direct DML grants on protected ledger table {$table}");
            }
        }
    }

    private static function required(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if (! is_string($value) || $value === '') {
            throw new RuntimeException("M-33: required test database environment missing: {$name}");
        }

        return $value;
    }
}
