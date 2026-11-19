<?php

declare(strict_types=1);

namespace Billium\Core\Tests;

use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Core\Tests\Arch\MigrationTables;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Foundation\Application;
use Packman\Testkit\Data\Database\TestDatabaseIdentity;
use Packman\Testkit\Support\Database\TestDatabaseGuard;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class TestEnvironment
{
    /** Two-int advisory key ('BILL', 'TEST'): objsubid 2 never collides with the single-key ConcurrencyStand barrier. */
    public const int LEASE_CLASS = 0x42494C4C;

    public const int LEASE_OBJECT = 0x54455354;

    private static ?PDO $suiteLease = null;

    private static ?string $suiteLeaseToken = null;

    private static ?string $profileDatabase = null;

    private static bool $preflightVerified = false;

    private const string SUITE_LEASE_ENV = 'BILLIUM_TEST_SUITE_LEASE';

    // These two forward-only P5 routines are created with a new identity when ledger tables are rebuilt.
    private const string LEDGER_REBUILD_ROUTINES = <<<'SQL'
        public.billium_claim_observation_owner(text,text,text,text,text,text,text,text,jsonb),
        public.billium_prove_observation_revision(text,text,text,text,text,text,text,text,jsonb,text)
        SQL;

    // Only P5 routines whose migrations use plain CREATE FUNCTION. All replaceable routines retain their OIDs.
    private const string FRESH_REBUILD_ROUTINES = <<<'SQL'
        public.billium_qualified_retirement_guard(),
        public.billium_idempotency_scope_immutable(),
        public.billium_idempotency_input_digest_immutable(),
        public.billium_receipt_original_execution_binding_immutable(),
        public.billium_execution_immutable(),
        public.billium_execution_reservation_guard(),
        public.billium_execution_merchant_policy_guard(),
        public.billium_execution_operation_binding_guard(),
        public.billium_execution_credential_identity_guard(),
        public.billium_execution_attempt_identity_guard(),
        public.billium_execution_capability_identity_guard(),
        public.billium_execution_emission_root_guard(),
        public.billium_execution_emission_complete_guard(),
        public.billium_execution_reservation_proof_guard(),
        public.billium_execution_root_identity_guard(),
        public.billium_execution_business_identity_digest(text,text,text,text,text),
        public.billium_execution_provider_identity_key(text,text,text,text,text),
        public.billium_execution_accept_provider_fact(jsonb,jsonb),
        public.billium_execution_reservation_actual_guard(),
        public.billium_execution_child_operation_guard(),
        public.billium_execution_authorization_release_guard(),
        public.billium_fiscal_append_only(),
        public.billium_fiscal_intent_guard()
        SQL;

    public static function prepareFreshRebuild(Application $app): void
    {
        // migrate:fresh drops every table but keeps standalone PostgreSQL functions. Wait for the actual wipe.
        $pending = true;
        $app->make(Dispatcher::class)->listen(MigrationsStarted::class, static function () use ($app, &$pending): void {
            if (! $pending) {
                return;
            }
            $pending = false;
            $owner = $app->make(DatabaseManager::class)->connection('billium_owner');
            self::assertRebuildOwner($owner);
            if ($owner->scalar(<<<'SQL'
                SELECT (to_regclass('public.migrations') IS NOT NULL AND EXISTS (SELECT FROM public.migrations)) OR EXISTS (
                    SELECT FROM pg_tables WHERE schemaname='public' AND tablename <> 'migrations')
                SQL) !== false) {
                throw new RuntimeException('Fresh rebuild requires an empty migration repository after the table wipe');
            }
            $owner->unprepared('DROP FUNCTION IF EXISTS '.self::LEDGER_REBUILD_ROUTINES.', '.self::FRESH_REBUILD_ROUTINES.' RESTRICT');
        });
    }

    public static function prepareLedgerRebuild(Connection $owner): void
    {
        self::assertRebuildOwner($owner);
        if ($owner->scalar(<<<'SQL'
            SELECT to_regclass('public.billium_observation_owner_binding') IS NOT NULL
                OR to_regclass('public.billium_observed_revision_owner_proof') IS NOT NULL
            SQL) !== false) {
            throw new RuntimeException('Ledger rebuild requires the owner and revision proof tables to have been dropped');
        }
        $owner->unprepared('DROP FUNCTION IF EXISTS '.self::LEDGER_REBUILD_ROUTINES.' RESTRICT');
    }

    private static function assertRebuildOwner(Connection $owner): void
    {
        self::acquireSuiteLease();
        if ($owner->scalar('SELECT current_database() = ? AND current_user = ?', [
            self::databaseName(), self::required('DB_OWNER_USERNAME'),
        ]) !== true) {
            throw new RuntimeException('Fixture rebuild requires the owner of the leased test database');
        }
    }

    public static function restoreSuiteMigrations(Application $app): void
    {
        $owner = $app->make(DatabaseManager::class)->connection('billium_owner');
        self::assertRebuildOwner($owner);
        $paths = [];
        $expected = [];
        foreach (ModuleCatalog::packaged()->manifest()->modules as $module) {
            foreach ($module->migrationIdentities as $migration) {
                $paths[] = dirname(__DIR__).'/database/migrations/'.$module->moduleId.'/'.$migration->id.'.php';
                $expected[] = $migration->id;
            }
        }
        // End the host-specific grant probe before restoring the shared fixture. Laravel switches its default
        // connection to the migration owner while the P4 migrations choose their grant recipient.
        $app->make(Repository::class)->set('billium.connection', 'pgsql');
        $exit = $app->make(Kernel::class)->call('migrate', [
            '--database' => 'billium_owner', '--force' => true, '--realpath' => true, '--path' => $paths,
        ]);
        sort($expected, SORT_STRING);
        if ($exit !== 0 || $owner->table('migrations')->orderBy('migration')->pluck('migration')->all() !== $expected) {
            throw new RuntimeException('Fixture rebuild did not restore the complete registered migration set');
        }
        self::assertRuntimeSafe($app->make(DatabaseManager::class)->connection('pgsql')->getPdo());
    }

    public static function configure(Application $app): void
    {
        TestDatabaseGuard::assertSafe(self::identity(owner: true));
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', self::connection());
        $app['config']->set('database.connections.billium_owner', self::connection(owner: true));
        $app['config']->set('billium.connection', 'pgsql');
    }

    public static function assertDestructiveResetAllowed(bool $owner = true): void
    {
        TestDatabaseGuard::assertDestructiveSafe(self::identity(owner: $owner));
    }

    public static function identity(bool $owner = false): TestDatabaseIdentity
    {
        $database = self::databaseName();

        return new TestDatabaseIdentity(
            driver: 'pgsql',
            host: self::required('DB_HOST'),
            port: (int) self::required('DB_PORT'),
            database: $database,
            username: self::required($owner ? 'DB_OWNER_USERNAME' : 'DB_USERNAME'),
        );
    }

    public static function hasProfileDatabase(): bool
    {
        return self::$profileDatabase !== null;
    }

    public static function databaseName(): string
    {
        $database = self::$profileDatabase ?? self::required('DB_DATABASE');
        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException('D50: DB_DATABASE must end in _test');
        }

        return $database;
    }

    /** Creates an empty owner/runtime database for a proof that requires physically absent module tables. */
    public static function openProfileDatabase(): Closure
    {
        if (self::$profileDatabase !== null) {
            throw new RuntimeException('A profile test database is already open');
        }
        self::preflight();
        self::acquireSuiteLease();
        $admin = self::pdo(owner: true);
        $sourceLease = self::$suiteLease;
        $sourceToken = self::$suiteLeaseToken;
        $name = 'billium_profile_'.bin2hex(random_bytes(8)).'_test';
        $admin->exec('CREATE DATABASE "'.$name.'" TEMPLATE template0');
        $close = static function () use ($name, $admin, $sourceLease, $sourceToken): void {
            if (self::$profileDatabase !== $name) {
                return;
            }
            self::$suiteLease = $sourceLease;
            self::$suiteLeaseToken = $sourceToken;
            self::$profileDatabase = null;
            $admin->exec('DROP DATABASE "'.$name.'" WITH (FORCE)');
        };
        try {
            self::$profileDatabase = $name;
            $owner = self::pdo(owner: true);
            $lock = $owner->prepare('SELECT pg_try_advisory_lock(?, ?)');
            $lock->execute([self::LEASE_CLASS, self::LEASE_OBJECT]);
            if ($lock->fetchColumn() !== true) {
                throw new RuntimeException('Fresh profile database already has a suite lease');
            }
            self::$suiteLease = $owner;
            self::$suiteLeaseToken = null;
            // Match the shared stand's role bootstrap before running the selected module migrations.
            $runtime = '"'.str_replace('"', '""', self::required('DB_USERNAME')).'"';
            $owner->exec('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
            $owner->exec('REVOKE TEMPORARY ON DATABASE "'.$name.'" FROM PUBLIC');
            $owner->exec('GRANT CONNECT ON DATABASE "'.$name.'" TO '.$runtime);
            $owner->exec('GRANT USAGE ON SCHEMA public TO '.$runtime);
            $owner->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO '.$runtime);
            $owner->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC');
            self::assertRuntimeSafe(self::pdo());
        } catch (Throwable $error) {
            $close();
            throw $error;
        }

        return $close;
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
        TestDatabaseGuard::assertSafe(self::identity(owner: true));
        self::assertDestructiveResetAllowed(owner: true);
        if (! extension_loaded('pdo_pgsql')) {
            throw new RuntimeException('D50/M-33: pdo_pgsql is required');
        }
        if (self::$preflightVerified) {
            return;
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
        self::$preflightVerified = true;
    }

    /**
     * D50: one test run per database. Committed-data isolation TRUNCATEs and drops fixtures, so a second runner
     * on the same database (parallel checks, another shell) would destroy this run's state and exhaust its
     * connection slots. The first DatabaseTestCase of a process waits for this session-level advisory lock and
     * keeps it until the process exits; ConcurrencyStand workers belong to the holder's run and never take it.
     */
    public static function acquireSuiteLease(int $timeoutSeconds = 1800): void
    {
        if (self::$suiteLease !== null) {
            return;
        }
        $inherited = getenv(self::SUITE_LEASE_ENV);
        if (is_string($inherited) && $inherited !== '') {
            self::assertInheritedSuiteLease($inherited);

            return;
        }
        $lease = self::pdo(owner: true);
        $lease->exec("SET lock_timeout = '{$timeoutSeconds}s'");
        try {
            $lease->query('SELECT pg_advisory_lock('.self::LEASE_CLASS.', '.self::LEASE_OBJECT.')');
        } catch (PDOException $exception) {
            throw new RuntimeException("D50: another test run kept the test database for {$timeoutSeconds}s", previous: $exception);
        }
        $lease->exec('SET lock_timeout = 0');
        self::$suiteLease = $lease;
    }

    /**
     * D50: the parent waits before starting a timed runner; its child adapters join that same exclusive run.
     * The claim is checked against a live advisory lock and a random holder token, never a bare skip flag.
     *
     * @return array<string, string>
     */
    public static function suiteLeaseEnvironment(): array
    {
        self::acquireSuiteLease();
        if (self::$suiteLease === null) {
            return [self::SUITE_LEASE_ENV => self::required(self::SUITE_LEASE_ENV)];
        }
        if (self::$suiteLeaseToken === null) {
            self::$suiteLeaseToken = 'billium-suite:'.bin2hex(random_bytes(16));
            $statement = self::$suiteLease->prepare("SELECT set_config('application_name', ?, false)");
            $statement->execute([self::$suiteLeaseToken]);
        }
        $statement = self::$suiteLease->query('SELECT pg_backend_pid()');
        if ($statement === false) {
            throw new RuntimeException('D50: cannot identify the test database lease holder');
        }
        $pid = $statement->fetchColumn();

        $claim = [self::SUITE_LEASE_ENV => json_encode([
            'database' => self::databaseName(), 'pid' => $pid, 'token' => self::$suiteLeaseToken,
        ], JSON_THROW_ON_ERROR)];

        return self::$profileDatabase === null ? $claim : ['DB_DATABASE' => self::$profileDatabase, ...$claim];
    }

    private static function assertInheritedSuiteLease(string $claim): void
    {
        $holder = json_decode($claim, true);
        if (! is_array($holder) || ($holder['database'] ?? null) !== self::databaseName()
            || ! is_int($holder['pid'] ?? null) || ! is_string($holder['token'] ?? null)) {
            throw new RuntimeException('D50: invalid inherited test database lease');
        }
        $statement = self::pdo(owner: true)->prepare(<<<'SQL'
            SELECT 1 WHERE EXISTS (SELECT 1 FROM pg_locks l JOIN pg_stat_activity a ON a.pid=l.pid
                WHERE l.locktype='advisory' AND l.granted AND l.objsubid=2
                  AND l.classid=? AND l.objid=? AND l.pid=?
                  AND l.database=(SELECT oid FROM pg_database WHERE datname=current_database())
                  AND a.usename=current_user AND a.application_name=?)
            SQL);
        $statement->execute([self::LEASE_CLASS, self::LEASE_OBJECT, $holder['pid'], $holder['token']]);
        if ($statement->fetchColumn() !== 1) {
            throw new RuntimeException('D50: inherited test database lease is no longer held or does not match');
        }
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
            SELECT c.relname
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relname = ANY(CAST(:tables AS text[]))
              AND (has_table_privilege(current_user, c.oid, 'INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER')
                   OR has_any_column_privilege(current_user, c.oid, 'INSERT,UPDATE,REFERENCES'))
            ORDER BY c.relname LIMIT 1
            SQL);
        $probe->execute(['tables' => '{'.implode(',', array_map(
            static fn (string $table): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $table).'"',
            $protectedTables,
        )).'}']);
        $table = $probe->fetchColumn();
        if ($table !== false) {
            throw new RuntimeException("D60: runtime has direct DML grants on protected ledger table {$table}");
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
