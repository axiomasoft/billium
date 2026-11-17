<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Feature;

use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runner\LockScope;
use Billium\Core\Exceptions\Runner\LockOrderViolation;
use Billium\Core\Internal\Runner\LockSet;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use LogicException;
use Override;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Packman\Schema\Data\Compiler\TableNames;
use Packman\Schema\Enums\SchemaScope;
use Packman\Schema\Exceptions\SchemaSetException;
use Packman\Schema\Laravel\PackmanMigration;
use Packman\Testkit\PackageTestCase;
use PDO;
use RuntimeException;

final class SqliteNonPgRuntimeTest extends PackageTestCase
{
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [BilliumCoreServiceProvider::class];
    }

    public function test_sqlite_in_memory_enforces_packman_migration_tx_atomicity_and_rejects_pgsql_central_sets(): void
    {
        config(['packman.schema.table_prefix' => 'billium_']);
        $this->app->forgetInstance(TableNames::class);
        $db = $this->app->make(DatabaseManager::class)->connection('sqlite');
        self::assertSame('sqlite', $db->getDriverName());

        // Real PackmanMigration execution against SQLite in-memory database.
        $migration = new class extends PackmanMigration
        {
            public function up(): void
            {
                $this->create('idempotency_receipt', function (Blueprint $table): void {
                    $this->uuidPrimary($table);
                    $this->ref($table, 'seller');
                    $this->ref($table, 'command');
                    $this->money($table, 'amount');
                });
            }

            public function down(): void
            {
                throw new LogicException('forward-only');
            }
        };
        $migration->up();

        $db->transaction(static function () use ($db): void {
            $db->table('billium_idempotency_receipt')->insert([
                'id' => '01930000-0000-7000-8000-000000000001',
                'seller_ref' => 'seller-sqlite',
                'command_ref' => 'cmd-1',
                'amount_minor' => 1500,
                'currency' => 'RUB',
            ]);
        });
        self::assertSame(1, $db->table('billium_idempotency_receipt')->count());

        try {
            $db->transaction(static function () use ($db): void {
                $db->table('billium_idempotency_receipt')->insert([
                    'id' => '01930000-0000-7000-8000-000000000002',
                    'seller_ref' => 'seller-sqlite',
                    'command_ref' => 'cmd-2',
                    'amount_minor' => 2500,
                    'currency' => 'RUB',
                ]);
                throw new RuntimeException('abort tx');
            });
            self::fail('Expected transaction rollback');
        } catch (RuntimeException) {
        }
        self::assertSame(1, $db->table('billium_idempotency_receipt')->count());

        // Central Billium schema sets strictly require PostgreSQL and fail closed on SQLite.
        $migrator = $this->app->make(SchemaMigrator::class);
        $this->expectException(SchemaSetException::class);
        $migrator->migrate(SchemaScope::Central, 'sqlite', includeModules: false);
    }

    public function test_lock_set_queries_real_sqlite_rows_and_enforces_global_order(): void
    {
        $db = $this->app->make(DatabaseManager::class)->connection('sqlite');
        $db->statement('CREATE TABLE billium_financial_session (id INTEGER PRIMARY KEY, installation_ref TEXT NOT NULL)');
        $db->statement('CREATE TABLE billium_obligation (id INTEGER PRIMARY KEY, seller_ref TEXT NOT NULL, environment TEXT NOT NULL)');
        $db->table('billium_financial_session')->insert(['id' => 1, 'installation_ref' => 'inst-sqlite']);
        $db->table('billium_obligation')->insert([
            ['id' => 10, 'seller_ref' => 'seller-a', 'environment' => 'test'],
            ['id' => 20, 'seller_ref' => 'seller-a', 'environment' => 'test'],
            ['id' => 30, 'seller_ref' => 'other-seller', 'environment' => 'test'],
        ]);

        $pdo = $db->getPdo();
        $locks = new LockSet(
            static function (string $sql, array $bindings) use ($pdo): array {
                // Adapt PG array/FOR UPDATE clause to SQLite IN clause while executing real SQL against live SQLite rows.
                preg_match('/FROM\s+public\."([^"]+)"\s+WHERE\s+"([^"]+)"\s*=\s*ANY/', $sql, $tableMatch);
                $table = $tableMatch[1] ?? '';
                $column = $tableMatch[2] ?? 'id';
                $rawList = trim((string) ($bindings[0] ?? ''), '{}');
                $keys = $rawList === '' ? [] : array_map(static fn (string $v): string => trim($v, '"'), explode(',', $rawList));
                $placeholders = implode(', ', array_fill(0, count($keys), '?'));
                if ($table === 'billium_financial_session') {
                    $stmt = $pdo->prepare("SELECT {$column} FROM {$table} WHERE {$column} IN ({$placeholders}) AND installation_ref = ? ORDER BY {$column}");
                    $stmt->execute([...$keys, $bindings[1]]);
                } else {
                    $stmt = $pdo->prepare("SELECT {$column} FROM {$table} WHERE {$column} IN ({$placeholders}) AND seller_ref = ? AND environment = ? ORDER BY {$column}");
                    $stmt->execute([...$keys, $bindings[1], $bindings[2]]);
                }

                /** @var list<int|string> */
                return $stmt->fetchAll(PDO::FETCH_COLUMN);
            },
            new Caller('seller-a', 'test', 'inst-sqlite', 'actor-sqlite'),
        );

        $acquired = $locks->acquire(
            new LockRequest(LockRank::FinancialSession, 'billium_financial_session', [1], shared: true, scope: LockScope::Installation),
            new LockRequest(LockRank::Obligation, 'billium_obligation', [30, 20, 10]),
        );
        // Cross-seller row 30 is excluded by caller scope; only [1, 10, 20] exist for inst-sqlite/seller-a.
        self::assertSame([1, 10, 20], $acquired);

        $this->expectException(LockOrderViolation::class);
        $locks->acquire(new LockRequest(LockRank::FinancialSession, 'billium_financial_session', [1], scope: LockScope::Installation));
    }
}
