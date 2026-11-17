<?php

declare(strict_types=1);

namespace Billium\Platform\Tests;

use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\DatabaseTestCase;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Contracts\Installation\Installation;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use PDO;
use PDOException;

/**
 * Committed-data base for platform proofs (D50): a host caller and a host process-token authority are bound as doubles;
 * billium_test_effect stands for the owning module's state written in the root Tx.
 */
abstract class PlatformTestCase extends DatabaseTestCase
{
    public const string INSTALLATION = 'inst-test';

    protected FixedCallerContext $callers;

    protected FixedSessionAdmission $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->callers = FixedCallerContext::default(installationRef: self::INSTALLATION);
        $this->tokens = new FixedSessionAdmission;
        $this->application()->instance(CallerContext::class, $this->callers);
        $this->application()->instance(FinancialSessionAdmission::class, $this->tokens);
        self::createEffectTable();
    }

    protected function tearDown(): void
    {
        try {
            TestEnvironment::pdo(owner: true)->exec('DROP TABLE IF EXISTS billium_test_effect');
        } finally {
            parent::tearDown();
        }
    }

    public static function createEffectTable(): void
    {
        $owner = TestEnvironment::pdo(owner: true);
        $owner->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS billium_test_effect (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                seller_ref text NOT NULL,
                environment text NOT NULL,
                ref text NOT NULL,
                attempt integer NOT NULL DEFAULT 1,
                note text NULL,
                written_at timestamptz NOT NULL DEFAULT clock_timestamp()
            )
            SQL);
        $owner->exec('TRUNCATE billium_test_effect RESTART IDENTITY');
        $runtime = self::pdoValue(TestEnvironment::pdo(), 'SELECT current_user');
        $owner->exec('GRANT SELECT, INSERT, UPDATE (note) ON billium_test_effect TO "'.str_replace('"', '""', self::text($runtime)).'"');
    }

    /** init → open epoch 1 in recovery → activate with a certificate → hand this process a matching token. */
    protected function activeSession(): void
    {
        $installation = $this->installation();
        $installation->init(self::INSTALLATION);
        $installation->openSession('open-1', 'owner-1', 1, 1, 1, 'evidence:open-1');
        $installation->activateSession('activate-1', 1, 'certificate:1', 'admission:1', 'evidence:activate-1');
        $this->tokens->set(FixedSessionAdmission::token(self::INSTALLATION));
    }

    protected function application(): Application
    {
        return $this->app ?? self::fail('Testbench application is not booted');
    }

    protected function installation(): Installation
    {
        return $this->application()->make(Installation::class);
    }

    protected function runner(): OperationRunner
    {
        return $this->application()->make(OperationRunner::class);
    }

    /** @param list<string> $modules */
    protected function spec(
        string $kind = 'ledger.test_effect',
        ?string $key = null,
        ?string $digest = null,
        FinancialMode $mode = FinancialMode::Active,
        ModuleOperationClass $class = ModuleOperationClass::NewIntent,
        array $modules = ['ledger', 'platform'],
        ?string $commit = 'ledger.test_effect',
        ?string $seller = null,
        ?string $environment = null,
    ): OperationSpec {
        $caller = $this->callers->current();

        return new OperationSpec(
            $kind, $seller ?? $caller->sellerRef, $environment ?? $caller->environment, $mode, $class, $modules,
            $key, $key === null ? null : ($digest ?? 'sha256:'.str_repeat('a', 64)), $mode->financial() ? $commit : null,
        );
    }

    /** A module-owned write in the root Tx, through the context's own connection. */
    public static function effect(TransactionContext $context, string $ref): int
    {
        $caller = $context->caller();
        $row = self::connection($context->connectionName())->selectOne(
            'INSERT INTO billium_test_effect (seller_ref, environment, ref, attempt) VALUES (?, ?, ?, ?) RETURNING id',
            [$caller->sellerRef, $caller->environment, $ref, $context->attempt()],
        );

        return self::integer(self::field($row, 'id'));
    }

    public static function connection(string $name): Connection
    {
        return app(DatabaseManager::class)->connection($name);
    }

    /** @param array<string, mixed> $body */
    public static function receipt(array $body = ['ok' => true], string $class = 'test.receipt'): PlatformReceipt
    {
        return new PlatformReceipt($class, $body);
    }

    protected function owner(): Connection
    {
        return self::connection('billium_owner');
    }

    /** @param list<mixed> $bindings */
    protected function rows(string $table, string $where = 'true', array $bindings = []): int
    {
        return self::integer($this->value("SELECT count(*) FROM {$table} WHERE {$where}", $bindings));
    }

    /**
     * First column of the first row, read by the fixture owner.
     *
     * @param  list<mixed>  $bindings
     */
    protected function value(string $sql, array $bindings = []): mixed
    {
        $row = $this->row($sql, $bindings);

        return $row === [] ? null : array_values($row)[0];
    }

    /**
     * @param  list<mixed>  $bindings
     * @return array<string, mixed>
     */
    protected function row(string $sql, array $bindings = []): array
    {
        return self::assoc($this->owner()->selectOne($sql, $bindings));
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    protected function table(string $sql, array $bindings = []): array
    {
        return array_values(array_map(self::assoc(...), $this->owner()->select($sql, $bindings)));
    }

    /** @return array<string, mixed> */
    protected static function assoc(mixed $row): array
    {
        $assoc = [];
        foreach (is_object($row) ? get_object_vars($row) : [] as $column => $value) {
            $assoc[(string) $column] = $value;
        }

        return $assoc;
    }

    /**
     * The `ok` payload of one ConcurrencyStand result.
     *
     * @param  array{index: int, ok?: array<string, mixed>, error?: array{code: string}}  $result
     * @return array<string, mixed>
     */
    protected static function ok(array $result): array
    {
        return $result['ok'] ?? self::fail('worker refused: '.json_encode($result, JSON_THROW_ON_ERROR));
    }

    /** @return array<array-key, mixed> */
    protected static function arr(mixed $value): array
    {
        return is_array($value) ? $value : self::fail('expected an array, got '.get_debug_type($value));
    }

    protected static function integer(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : self::fail('expected an integer, got '.get_debug_type($value)));
    }

    protected static function text(mixed $value): string
    {
        return is_string($value) ? $value : self::fail('expected a string, got '.get_debug_type($value));
    }

    protected static function field(mixed $row, string $column): mixed
    {
        return is_object($row) ? (get_object_vars($row)[$column] ?? null) : self::fail('expected a row');
    }

    protected static function pdoValue(PDO $pdo, string $sql): mixed
    {
        $statement = $pdo->query($sql);

        return $statement === false ? self::fail($sql) : $statement->fetchColumn();
    }

    protected static function sqlState(PDO $pdo, string $sql): ?string
    {
        try {
            $pdo->exec($sql);

            return null;
        } catch (PDOException $exception) {
            return is_string($exception->errorInfo[0] ?? null) ? $exception->errorInfo[0] : (string) $exception->getCode();
        }
    }
}
