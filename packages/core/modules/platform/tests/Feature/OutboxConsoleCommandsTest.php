<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Platform\Internal\Outbox\OutboxRelay;
use Billium\Platform\Tests\Outbox\OutboxTestCase;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

/** Operator entry points of the outbox relay and the explicit event-contract registration. */
#[Group('pg')]
final class OutboxConsoleCommandsTest extends OutboxTestCase
{
    public function test_relay_delivers_at_most_the_requested_limit_and_reports_counts(): void
    {
        $this->events(3);
        $this->publisher();

        $this->artisan('billium:outbox:relay', ['--limit' => '2'])
            ->expectsOutput('claimed=2 delivered=2 failed=0 stale=0')
            ->assertExitCode(0);
        self::assertSame(1, $this->rows('billium_outbox_delivery', "status <> 'delivered'"));
    }

    public function test_relay_defaults_to_the_configured_batch(): void
    {
        config(['billium.outbox.relay_batch' => 1]);
        $this->application()->forgetInstance(BilliumOptions::class);
        $this->events(2);
        $this->publisher();

        $this->artisan('billium:outbox:relay')
            ->expectsOutput('claimed=1 delivered=1 failed=0 stale=0')
            ->assertExitCode(0);
    }

    public function test_relay_selects_the_internal_command_lane_on_request(): void
    {
        $this->events(1);
        $this->publisher();

        // Public facts are not on the command lane, so nothing is claimed there.
        $this->artisan('billium:outbox:relay', ['--commands' => true])
            ->expectsOutput('claimed=0 delivered=0 failed=0 stale=0')
            ->assertExitCode(0);
        self::assertSame(1, $this->rows('billium_outbox_delivery', "status <> 'delivered'"));
    }

    #[TestWith(['0'])]
    #[TestWith(['1001'])]
    #[TestWith(['-1'])]
    #[TestWith(['ten'])]
    #[TestWith([''])]
    public function test_relay_rejects_a_limit_outside_one_to_a_thousand(string $limit): void
    {
        $this->artisan('billium:outbox:relay', ['--limit' => $limit])
            ->expectsOutput('limit must be between 1 and 1000')
            ->assertExitCode(1);
    }

    public function test_relay_failure_shows_only_the_class_and_reports_the_message_to_the_host(): void
    {
        Exceptions::fake();
        $this->application()->bind(OutboxRelay::class, static fn (): never => throw new RuntimeException('dsn=postgres://user:secret@db'));

        $this->artisan('billium:outbox:relay', ['--limit' => '1'])
            ->expectsOutput('Relay unavailable (RuntimeException); verify host publisher and outbox configuration')
            ->doesntExpectOutputToContain('secret')
            ->assertExitCode(1);
        Exceptions::assertReported(static fn (RuntimeException $error): bool => str_contains($error->getMessage(), 'secret'));
    }

    public function test_register_event_contracts_requires_an_explicit_owner_connection(): void
    {
        $this->artisan('billium:event-contracts:register')
            ->expectsOutput('An explicit owner database connection and module configuration are required')
            ->assertExitCode(1);
    }

    public function test_register_event_contracts_refuses_the_runtime_connection(): void
    {
        $before = $this->rows('billium_event_contract');

        $this->artisan('billium:event-contracts:register', ['--database' => $this->application()['db']->getDefaultConnection()])
            ->expectsOutput('Event registration requires the installation/upgrade owner connection')
            ->assertExitCode(1);
        self::assertSame($before, $this->rows('billium_event_contract'));
    }

    public function test_register_event_contracts_follows_the_enabled_modules_idempotently(): void
    {
        $execution = $this->application()->make(ModuleRegistry::class)->descriptor('execution') ?? self::fail('execution descriptor missing');

        $this->artisan('billium:event-contracts:register', ['--database' => 'billium_owner'])->assertExitCode(0);
        self::assertSame(0, $this->rows('billium_event_contract', "owner_module_id = 'execution'"), 'a disabled, never installed module has no rows');

        config(['billium.modules.enabled' => ['platform', 'ledger', 'execution']]);
        $this->application()->forgetInstance(BilliumOptions::class);
        $this->artisan('billium:event-contracts:register', ['--database' => 'billium_owner'])->assertExitCode(0);
        $registered = $this->rows('billium_event_contract', "status = 'registered'");
        self::assertSame(count($execution->publicEvents), $this->rows('billium_event_contract', "owner_module_id = 'execution' AND status = 'registered'"));

        $this->artisan('billium:event-contracts:register', ['--database' => 'billium_owner'])->assertExitCode(0);
        self::assertSame($registered, $this->rows('billium_event_contract', "status = 'registered'"));
    }
}
