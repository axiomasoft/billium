<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Modules\ModuleStateReader;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Core\Internal\Runner\UnboundCallerContext;
use Billium\Core\Internal\Runner\UnboundSessionAdmission;
use Billium\Platform\Tests\PlatformTestCase;
use Illuminate\Testing\PendingCommand;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

#[Group('D55')]
#[Group('pg')]
final class InstallationInitTest extends PlatformTestCase
{
    public function test_explicit_init_creates_installation_state_and_accepting_modules_once(): void
    {
        self::assertSame(0, $this->init(['--installation-ref' => 'inst-cli']));
        self::assertSame(0, $this->init(['--installation-ref' => 'inst-cli']));

        self::assertSame(1, $this->rows('billium_installation_state', 'installation_ref = ?', ['inst-cli']));
        self::assertSame(['incarnation' => 1, 'dispatch_enabled' => false], $this->row('SELECT incarnation, dispatch_enabled FROM billium_installation_state'));
        self::assertSame(1, $this->rows('billium_outbox_watermark', "id = 1 AND scope = 'installation'"));
        $modules = $this->table("SELECT module_id, state, generation, schema_version FROM billium_module_state WHERE installation_ref = 'inst-cli' ORDER BY module_id");
        self::assertSame(['ledger', 'platform'], array_column($modules, 'module_id'));
        $catalog = ModuleCatalog::packaged();
        foreach ($modules as $module) {
            self::assertSame('accepting', $module['state']);
            self::assertSame(1, $module['generation']);
            self::assertSame($catalog->descriptor(self::text($module['module_id']))?->currentSchemaVersion, $module['schema_version']);
        }
        self::assertSame(2, $this->rows('billium_module_transitions', "installation_ref = 'inst-cli' AND from_state IS NULL AND to_generation = 1"));
        $read = $this->application()->make(ModuleStateReader::class)->read('inst-cli', 'ledger');
        self::assertSame(ModuleLifecycleState::Accepting, $read?->state);
        self::assertSame(1, $read->generation);
        self::assertNull($this->application()->make(ModuleStateReader::class)->read('inst-cli', 'usage'), 'no implicit registration');
    }

    public function test_init_without_a_ref_fails(): void
    {
        config(['billium.installation_ref' => null]);
        $this->application()->forgetInstance(BilliumOptions::class);
        self::assertSame(1, $this->init([]));
        self::assertSame(0, $this->rows('billium_installation_state'));
    }

    /**
     * Runs the command again on every call.
     *
     * @param  array<string, string>  $options
     *
     * @phpstan-impure
     */
    private function init(array $options): int
    {
        $command = $this->artisan('billium:installation:init', $options);
        self::assertInstanceOf(PendingCommand::class, $command);

        return $command->run();
    }

    public function test_host_spis_fail_closed_until_bound(): void
    {
        foreach ([[new UnboundCallerContext, CallerContext::class], [new UnboundSessionAdmission, FinancialSessionAdmission::class]] as [$default, $contract]) {
            try {
                $default instanceof CallerContext ? $default->current() : $default->currentToken('x');
                self::fail('unbound host SPI must throw');
            } catch (LogicException $exception) {
                self::assertSame('bind '.$contract, $exception->getMessage());
            }
        }
    }
}
