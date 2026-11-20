<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Modules\ModuleManifest;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Platform\Contracts\Outbox\CommandOutbox;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Data\EventContracts\PreparedInternalCommand;
use Billium\Platform\Internal\CommandOutbox\CommandOutboxRelay;
use Billium\Platform\Tests\Outbox\OutboxTestCase;
use Billium\Platform\Tests\Outbox\RecordingPublisher;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

#[Group('EVT-03')]
#[Group('pg')]
final class CommandPublicLaneTest extends OutboxTestCase
{
    #[Group('pg-serial')]
    public function test_command_and_fact_share_root_tx_but_only_the_fact_enters_the_public_feed(): void
    {
        $this->declareCommand('ledger.test.v1');
        $eventId = (string) Str::ulid();
        $this->runner()->run($this->spec(), function (TransactionContext $context) use ($eventId): Receipt {
            $this->application()->make(Outbox::class)->record($context, self::event($eventId, 'obligation:1'));
            $this->application()->make(CommandOutbox::class)->record($context, new PreparedInternalCommand('cmd:1', 'intent:1', 'ledger', 'ledger.test.v1', 'billium.command.v1', ['x' => 1], ['execution']));

            $repeat = new PreparedInternalCommand('cmd:1', 'intent:1', 'ledger', 'ledger.test.v1', 'billium.command.v1', ['x' => 1], ['execution']);
            self::assertSame(1, $this->application()->make(CommandOutbox::class)->record($context, $repeat));

            return self::receipt();
        });
        self::assertSame($this->value('SELECT tx_id FROM billium_outbox_event'), $this->value('SELECT tx_id FROM billium_outbox_command'));
        $publisher = new RecordingPublisher;
        $this->publisher($publisher);
        self::assertSame(1, $this->application()->make(CommandOutboxRelay::class)->relay()->delivered);
        self::assertSame('billium.command.v1', $publisher->messages[0]['schema']);
        self::assertSame(0, $this->rows('billium_outbox_delivery', "status = 'delivered'"));
        self::assertSame(1, $this->relay()->relay()->delivered);
        $page = $this->feed()->read($this->selection());
        self::assertCount(1, $page->events);
        self::assertSame($eventId, $page->events[0]['eventId']);
    }

    #[TestWith([ModuleOperationClass::NewIntent])]
    #[TestWith([ModuleOperationClass::UnknownResolution])]
    public function test_recovery_can_record_public_facts_but_cannot_create_outgoing_commands(ModuleOperationClass $class): void
    {
        $this->declareCommand('ledger.collection.execute.v1');
        SimulatedFinancialAuthorityEvidence::install($this->application())->issueTransition('seal', 'seal-command-recovery', [1]);
        $this->installation()->seal('seal-command-recovery', 1);
        SimulatedFinancialAuthorityEvidence::install($this->application())->issueTransition('open', 'open-command-recovery', ['owner-1', 1, 1, 1, 'evidence:command-recovery']);
        $this->installation()->openSession('open-command-recovery', 'owner-1', 1, 1, 1, 'evidence:command-recovery');
        $this->tokens->set(FixedSessionAdmission::token(self::INSTALLATION, modes: [FinancialMode::Recovery]));
        SimulatedFinancialAuthorityEvidence::admitCurrent($this->application());

        $eventId = (string) Str::ulid();
        $this->runner()->run($this->spec(mode: FinancialMode::Recovery, class: ModuleOperationClass::ObservedFactRecording),
            function (TransactionContext $context) use ($eventId): Receipt {
                $this->application()->make(Outbox::class)->record($context, self::event($eventId, 'recovered-obligation:1'));

                return self::receipt();
            });
        self::assertSame(1, $this->rows('billium_outbox_event'));
        self::assertSame(1, $this->rows('billium_outbox_delivery'));

        $admitted = false;
        try {
            $this->runner()->run($this->spec(mode: FinancialMode::Recovery, class: $class),
                function (TransactionContext $context) use (&$admitted): Receipt {
                    self::assertSame(FinancialMode::Recovery, $context->spec()->mode);
                    self::assertSame(2, $context->sessionEpoch());
                    self::assertArrayHasKey('ledger', $context->admittedModules());
                    self::assertArrayHasKey('platform', $context->admittedModules());
                    $admitted = true;
                    $this->application()->make(CommandOutbox::class)->record($context,
                        new PreparedInternalCommand('recovery-command:1', 'collection-intent:1', 'ledger',
                            'ledger.collection.execute.v1', 'billium.command.v1', ['intentRef' => 'collection-intent:1'], ['execution']));

                    return self::receipt();
                });
            self::fail('A valid recovery root must not create an outgoing command');
        } catch (LogicException $exception) {
            self::assertSame('Outbox recording requires an admitted financial write operation class and session (D54, D55, D60)', $exception->getMessage());
        }
        self::assertTrue($admitted, 'The recorder must refuse after recovery session and module admission succeed');
        self::assertSame(0, $this->rows('billium_outbox_command'));
        self::assertSame(0, $this->rows('billium_outbox_command_delivery'));
        self::assertSame(1, $this->rows('billium_outbox_event'));
        self::assertSame(1, $this->rows('billium_outbox_delivery'));
        self::assertSame($eventId, $this->value('SELECT event_id FROM billium_outbox_event'));
    }

    private function declareCommand(string $type): void
    {
        $manifest = self::arr(json_decode((string) file_get_contents(dirname(__DIR__, 4).'/resources/modules.json'), true, 512, JSON_THROW_ON_ERROR));
        $modules = self::arr($manifest['modules']);
        $ledger = self::arr($modules[1]);
        $ledger['internalCommands'] = [['commandType' => $type, 'commandSchema' => 'billium.command.v1',
            'payloadSchemaRef' => 'test:command', 'payloadSchemaDigest' => 'sha256:'.str_repeat('a', 64)]];
        $modules[1] = $ledger;
        $manifest['modules'] = $modules;
        $this->application()->instance(ModuleRegistry::class, new ModuleCatalog(ModuleManifest::fromArray($manifest)));
    }
}
