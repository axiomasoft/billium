<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Modules\ModuleManifest;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Internal\Modules\ModuleCatalog;
use Billium\Platform\Contracts\Outbox\CommandOutbox;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Data\EventContracts\PreparedInternalCommand;
use Billium\Platform\Internal\CommandOutbox\CommandOutboxRelay;
use Billium\Platform\Tests\Outbox\OutboxTestCase;
use Billium\Platform\Tests\Outbox\RecordingPublisher;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;

#[Group('EVT-03')]
#[Group('pg')]
final class CommandPublicLaneTest extends OutboxTestCase
{
    public function test_command_and_fact_share_root_tx_but_only_the_fact_enters_the_public_feed(): void
    {
        $manifest = self::arr(json_decode((string) file_get_contents(dirname(__DIR__, 4).'/resources/modules.json'), true, 512, JSON_THROW_ON_ERROR));
        $modules = self::arr($manifest['modules']);
        $ledger = self::arr($modules[1]);
        $ledger['internalCommands'] = [['commandType' => 'ledger.test.v1', 'commandSchema' => 'billium.command.v1',
            'payloadSchemaRef' => 'test:command', 'payloadSchemaDigest' => 'sha256:'.str_repeat('a', 64)]];
        $modules[1] = $ledger;
        $manifest['modules'] = $modules;
        $this->application()->instance(ModuleRegistry::class, new ModuleCatalog(ModuleManifest::fromArray($manifest)));
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
}
