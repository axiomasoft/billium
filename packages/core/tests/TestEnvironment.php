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
use Illuminate\Database\Events\MigrationStarted;
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

    private static int $migrationRuns = 0;

    private static ?string $isolatedTokenDatabase = null;

    public const string WORKER_TEMPLATE = 'billium_worker_schema_test';

    private const string SUITE_LEASE_ENV = 'BILLIUM_TEST_SUITE_LEASE';

    private const string COLLECTION_WORK_ROUTINES = <<<'SQL'
        public.billium_lock_collection_dunning_case(bigint[],text,text,boolean),
        public.billium_collection_select_work(text,text,text,text,text,boolean,bigint,integer)
        SQL;

    // These two forward-only P5 routines are created with a new identity when ledger tables are rebuilt.
    private const string LEDGER_REBUILD_ROUTINES = <<<'SQL'
        public.billium_claim_observation_owner(text,text,text,text,text,text,text,text,jsonb),
        public.billium_prove_observation_revision(text,text,text,text,text,text,text,text,jsonb,text)
        SQL;

    // P5 routines created with plain CREATE FUNCTION and all collection routines.
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
        public.billium_fiscal_intent_guard(),
        public.billium_collection_consent_guard_check(),
        public.billium_collection_customer_action_guard(),
        public.billium_collection_recurring_owner_guard(),
        public.billium_collection_dunning_case_guard(),
        public.billium_collection_step_guard(),
        public.billium_collection_notification_guard()
        SQL.', '.self::COLLECTION_WORK_ROUTINES;

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
        self::isolateParallelDatabase();
        TestDatabaseGuard::assertSafe(self::identity(owner: true));
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', self::connection());
        $app['config']->set('database.connections.billium_owner', self::connection(owner: true));
        $app['config']->set('billium.connection', 'pgsql');

        // A fixture wiped before the fresh-rebuild cleanup can still have these standalone routines.
        $app->make(Dispatcher::class)->listen(MigrationStarted::class, static function (MigrationStarted $event) use ($app): void {
            if ($event->method !== 'up' || $event->name !== '2026_11_20_000004_create_billium_collection_work') {
                return;
            }

            $owner = $app->make(DatabaseManager::class)->connection($event->migration->getConnection());
            if ($owner->getName() !== 'billium_owner'
                || $owner->scalar("SELECT to_regclass('public.billium_collection_work') IS NULL") !== true) {
                return;
            }

            self::assertDestructiveResetAllowed();
            self::assertRebuildOwner($owner);
            $owner->unprepared('DROP FUNCTION IF EXISTS '.self::COLLECTION_WORK_ROUTINES.' RESTRICT');
        });
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
        self::isolateParallelDatabase();
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
        self::isolateParallelDatabase();
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
        self::isolateParallelDatabase();
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

        return ['DB_DATABASE' => self::databaseName(), ...$claim];
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

    public static function migrationRuns(): int
    {
        return self::$migrationRuns;
    }

    public static function noteMigrationRun(): void
    {
        self::$migrationRuns++;
    }

    /**
     * ParaTest's TEST_TOKEN is an integer, so billium_test_<token> does not end in _test and
     * TestDatabaseGuard refuses it. billium_<token>_test keeps the token and stays a test database.
     */
    public static function isolateParallelDatabase(): void
    {
        $token = getenv('TEST_TOKEN');
        if (! is_string($token) || preg_match('/^[A-Za-z0-9]+$/', $token) !== 1) {
            return;
        }
        $name = 'billium_'.$token.'_test';
        $current = getenv('DB_DATABASE');
        $current = is_string($current) ? $current : '';
        // Only the shared compose database is retargeted. An empty name is a
        // deliberate missing-database probe. A child that already inherited the
        // worker name must not rewrite that database's ACL.
        if ($current === $name) {
            self::$isolatedTokenDatabase = $name;

            return;
        }
        if ($current !== 'billium_test' || self::$isolatedTokenDatabase === $name) {
            return;
        }
        self::ensureWorkerDatabase($name);
        putenv('DB_DATABASE='.$name);
        $_ENV['DB_DATABASE'] = $name;
        $_SERVER['DB_DATABASE'] = $name;
        self::$isolatedTokenDatabase = $name;
        self::$preflightVerified = false;
    }

    public static function workerTemplateSealed(): bool
    {
        $admin = self::adminPdo();
        $statement = $admin->prepare('SELECT datallowconn FROM pg_database WHERE datname = ?');
        $statement->execute([self::WORKER_TEMPLATE]);
        $row = $statement->fetch(PDO::FETCH_NUM);

        return is_array($row) && ($row[0] === false || $row[0] === 'f' || $row[0] === 0);
    }

    public static function provisionWorkerTemplate(): void
    {
        self::assertDestructiveResetAllowed();
        $admin = self::adminPdo();
        $name = self::WORKER_TEMPLATE;
        if (self::databaseExists($admin, $name)) {
            $admin->exec('ALTER DATABASE '.self::quoteIdent($name).' WITH ALLOW_CONNECTIONS true');
            $admin->exec('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '.self::literal($admin, $name).' AND pid <> pg_backend_pid()');
            $admin->exec('DROP DATABASE '.self::quoteIdent($name).' WITH (FORCE)');
        }
        self::dropParallelDatabases($admin);
        $admin->exec('CREATE DATABASE '.self::quoteIdent($name));
        self::grantRuntime($name);
        putenv('DB_DATABASE='.$name);
        $_ENV['DB_DATABASE'] = $name;
        $_SERVER['DB_DATABASE'] = $name;
        self::$preflightVerified = false;
    }

    public static function sealWorkerTemplate(): void
    {
        self::$suiteLease = null;
        $admin = self::adminPdo();
        $quoted = self::quoteIdent(self::WORKER_TEMPLATE);
        $admin->exec('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '.self::literal($admin, self::WORKER_TEMPLATE).' AND pid <> pg_backend_pid()');
        $admin->exec('ALTER DATABASE '.$quoted.' WITH ALLOW_CONNECTIONS false');
    }

    /** @param list<string> $setIds */
    public static function adoptProfileTemplate(array $setIds, Closure $disconnect): bool
    {
        $template = self::profileTemplateName($setIds);
        $admin = self::adminPdo();
        if (! self::databaseExists($admin, $template)) {
            return false;
        }
        $profile = self::$profileDatabase ?? throw new RuntimeException('Profile database is not open');
        $disconnect();
        self::releaseSuiteLease();
        $admin->exec('DROP DATABASE '.self::quoteIdent($profile).' WITH (FORCE)');
        $admin->exec('CREATE DATABASE '.self::quoteIdent($profile).' TEMPLATE '.self::quoteIdent($template));
        $admin->exec('ALTER DATABASE '.self::quoteIdent($profile).' WITH ALLOW_CONNECTIONS true');
        self::grantDatabaseAcl($profile);
        self::acquireSuiteLease();

        return true;
    }

    /** @param list<string> $setIds */
    public static function publishProfileTemplate(array $setIds, Closure $disconnect): void
    {
        $template = self::profileTemplateName($setIds);
        $admin = self::adminPdo();
        if (self::databaseExists($admin, $template)) {
            return;
        }
        $profile = self::$profileDatabase ?? throw new RuntimeException('Profile database is not open');
        $disconnect();
        self::releaseSuiteLease();
        try {
            $admin->exec('CREATE DATABASE '.self::quoteIdent($template).' TEMPLATE '.self::quoteIdent($profile));
            $admin->exec('ALTER DATABASE '.self::quoteIdent($template).' WITH ALLOW_CONNECTIONS false');
        } catch (PDOException $exception) {
            self::acquireSuiteLease();
            if (($exception->errorInfo[0] ?? '') !== '42P04') {
                throw $exception;
            }

            return;
        }
        self::acquireSuiteLease();
    }

    public static function truncateBilliumTables(PDO $pdo): void
    {
        $rows = $pdo->query(<<<'SQL'
            SELECT format('%I.%I', schemaname, tablename)
            FROM pg_tables
            WHERE schemaname = 'public'
              AND starts_with(tablename, 'billium_')
              AND tablename <> 'billium_event_contract'
            ORDER BY 1
            SQL);
        if ($rows === false) {
            throw new RuntimeException('Unable to list billium tables for truncate');
        }
        $tables = $rows->fetchAll(PDO::FETCH_COLUMN);
        if ($tables === []) {
            return;
        }
        $pdo->exec('TRUNCATE '.implode(', ', $tables).' RESTART IDENTITY CASCADE');
    }

    private static function ensureWorkerDatabase(string $name): void
    {
        $admin = self::adminPdo();
        if (! self::databaseExists($admin, $name)) {
            if (self::databaseExists($admin, self::WORKER_TEMPLATE)) {
                $admin->exec('CREATE DATABASE '.self::quoteIdent($name).' TEMPLATE '.self::quoteIdent(self::WORKER_TEMPLATE));
                $admin->exec('ALTER DATABASE '.self::quoteIdent($name).' WITH ALLOW_CONNECTIONS true');
            } else {
                $admin->exec('CREATE DATABASE '.self::quoteIdent($name));
                self::grantRuntime($name);
            }
        }
        // CREATE DATABASE does not copy database ACLs, so a template clone would
        // keep PUBLIC TEMP and fail the runtime safety check.
        self::grantDatabaseAcl($name);
    }

    /**
     * Parallel databases whose suite lease is not held. A second template rebuild must not
     * terminate or drop a database another run still holds (D50).
     *
     * @return list<string>
     */
    public static function unleasedParallelDatabases(PDO $admin): array
    {
        $class = self::LEASE_CLASS;
        $object = self::LEASE_OBJECT;
        $statement = $admin->query(<<<SQL
            SELECT d.datname
            FROM pg_database d
            WHERE (
                d.datname ~ '^billium_[A-Za-z0-9]+_test$'
                OR d.datname ~ '^billium_profile_[a-f0-9]+_test$'
                OR d.datname ~ '^billium_ps[a-f0-9]+_test$'
            )
            AND NOT EXISTS (
                SELECT 1 FROM pg_locks l
                WHERE l.locktype = 'advisory'
                  AND l.granted
                  AND l.objsubid = 2
                  AND l.classid = {$class}
                  AND l.objid = {$object}
                  AND l.database = d.oid
            )
            ORDER BY d.datname
            SQL);
        if ($statement === false) {
            throw new RuntimeException('Unable to list parallel test databases');
        }
        $names = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (is_string($name)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private static function dropParallelDatabases(PDO $admin): void
    {
        foreach (self::unleasedParallelDatabases($admin) as $name) {
            if ($name === self::WORKER_TEMPLATE || $name === 'billium_test') {
                continue;
            }
            $admin->exec('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '.self::literal($admin, $name).' AND pid <> pg_backend_pid()');
            $admin->exec('DROP DATABASE '.self::quoteIdent($name).' WITH (FORCE)');
        }
    }

    private static function grantDatabaseAcl(string $database): void
    {
        $admin = self::adminPdo();
        $quoted = self::quoteIdent($database);
        $admin->exec('REVOKE TEMPORARY ON DATABASE '.$quoted.' FROM PUBLIC');
        $admin->exec('GRANT CONNECT ON DATABASE '.$quoted.' TO '.self::quoteIdent(self::required('DB_USERNAME')));
    }

    private static function grantRuntime(string $database): void
    {
        $runtime = self::quoteIdent(self::required('DB_USERNAME'));
        $owner = self::ownerPdoTo($database);
        $quoted = self::quoteIdent($database);
        $owner->exec('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
        $owner->exec('REVOKE TEMPORARY ON DATABASE '.$quoted.' FROM PUBLIC');
        $owner->exec('GRANT CONNECT ON DATABASE '.$quoted.' TO '.$runtime);
        $owner->exec('GRANT USAGE ON SCHEMA public TO '.$runtime);
        $owner->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO '.$runtime);
        $owner->exec('ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC');
    }

    /** @param list<string> $setIds */
    private static function profileTemplateName(array $setIds): string
    {
        $ids = array_values($setIds);
        sort($ids, SORT_STRING);

        return 'billium_ps'.substr(hash('sha256', implode("\0", $ids)), 0, 12).'_test';
    }

    private static function releaseSuiteLease(): void
    {
        self::$suiteLease = null;
        self::$suiteLeaseToken = null;
    }

    private static function adminPdo(): PDO
    {
        try {
            return self::ownerPdoTo('postgres');
        } catch (PDOException) {
            return self::ownerPdoTo(self::required('DB_DATABASE'));
        }
    }

    private static function ownerPdoTo(string $database): PDO
    {
        $config = self::connection(owner: true);

        return new PDO(
            "pgsql:host={$config['host']};port={$config['port']};dbname={$database}",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    private static function databaseExists(PDO $admin, string $name): bool
    {
        $statement = $admin->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $statement->execute([$name]);

        return $statement->fetchColumn() !== false;
    }

    private static function literal(PDO $pdo, string $value): string
    {
        $quoted = $pdo->quote($value);
        if ($quoted === false) {
            throw new RuntimeException('Unable to quote a SQL literal');
        }

        return $quoted;
    }

    private static function quoteIdent(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new RuntimeException('Unsafe PostgreSQL identifier');
        }

        return '"'.$name.'"';
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
