<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\Receipt;
use Billium\Core\Tests\TestEnvironment;
use Billium\Platform\Contracts\Outbox;
use Billium\Platform\Data\OutboxEvent;
use Billium\Platform\Internal\Installation\RuntimeGrants;
use Billium\Platform\Tests\PlatformTestCase;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Testing\PendingCommand;

final class RuntimeGrantsTest extends PlatformTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app->make(Repository::class)->set('billium.connection', null);
    }

    public function test_fresh_owner_migrations_grant_to_the_default_restricted_runtime(): void
    {
        self::assertNull(config('billium.connection'));
        $runtime = self::connection('pgsql')->getPdo();
        $runtimeRole = self::pdoValue($runtime, 'SELECT current_user');
        self::assertNotSame($runtimeRole, self::pdoValue(TestEnvironment::pdo(owner: true), 'SELECT current_user'));

        // The suite lease isolates this throwaway test database; rebuild so old grants cannot hide an omission.
        // Laravel's migrate:fresh drops tables, but retains standalone PostgreSQL trigger functions.
        $this->owner()->unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS public.billium_append_only(), public.billium_idempotency_receipt_guard(),
                public.billium_financial_session_guard(), public.billium_module_state_guard() CASCADE
            SQL);
        $migration = $this->artisan('migrate:fresh', ['--database' => 'billium_owner', '--force' => true]);
        self::assertInstanceOf(PendingCommand::class, $migration);
        $migration->assertExitCode(0);
        self::assertSame('pgsql', config('database.default'));
        self::assertSame($runtimeRole, RuntimeGrants::role());
        TestEnvironment::assertRuntimeSafe($runtime);
        self::createEffectTable();
        $this->activeSession();
        $outcome = $this->runner()->run($this->spec(key: 'k-default-runtime'), function (TransactionContext $context): Receipt {
            self::assertSame('pgsql', $context->connectionName());
            self::effect($context, 'default runtime');
            $this->application()->make(Outbox::class)->record($context, new OutboxEvent(
                'evt-default-runtime', 'ledger.default_runtime', 'billium.event.v1', 'x:1', 1, new DateTimeImmutable, [],
            ));

            return self::receipt();
        });

        self::assertTrue($outcome->accepted);
        self::assertSame(1, $this->rows('billium_test_effect'));
        self::assertSame(1, $this->rows('billium_outbox_event'));
        self::assertSame(1, $this->rows('billium_money_commit_log'));
        self::assertSame(1, $this->rows('billium_idempotency_receipt', 'command_key = ?', ['k-default-runtime']));
        self::assertSame('42501', self::sqlState($runtime, "UPDATE billium_outbox_event SET type = 'ledger.changed'"));
    }
}
