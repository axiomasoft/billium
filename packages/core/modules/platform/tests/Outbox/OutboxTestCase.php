<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Outbox;

use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\EventPublisher;
use Billium\Core\Data\Runner\Receipt;
use Billium\Platform\Contracts\Feed\EventFeed;
use Billium\Platform\Contracts\Outbox\Outbox;
use Billium\Platform\Data\EventContracts\EventContractRegistration;
use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Data\Feed\FeedSelection;
use Billium\Platform\Data\Outbox\OutboxEvent;
use Billium\Platform\Internal\EventContracts\DatabaseEventContractRegistrar;
use Billium\Platform\Internal\Outbox\OutboxPruner;
use Billium\Platform\Internal\Outbox\OutboxRelay;
use Billium\Platform\Tests\PlatformTestCase;
use DateTimeImmutable;
use Illuminate\Support\Str;

abstract class OutboxTestCase extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['billium.feed.signing_key' => str_repeat('k', 32)]);
        $this->activeSession();
    }

    public static function event(string $id, string $ref, string $type = 'obligation.created.v1'): OutboxEvent
    {
        return new OutboxEvent($id, $type, 'billium.event.v1', $ref, 1, new DateTimeImmutable('2026-10-28T10:00:00Z'), ['ref' => $ref]);
    }

    protected function events(int $count, string $type = 'obligation.created.v1'): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->runner()->run($this->spec(), function (TransactionContext $context) use ($type): Receipt {
                $id = self::effect($context, (string) Str::ulid());
                $this->application()->make(Outbox::class)->record($context, self::event((string) Str::ulid(), 'obligation:'.$id, $type));

                return self::receipt();
            });
        }
    }

    protected function feed(): EventFeed
    {
        return $this->application()->make(EventFeed::class);
    }

    /** @param list<string> $types */
    protected function selection(array $types = ['obligation.created.v1'], bool $required = false): FeedSelection
    {
        return $this->feed()->openFeed(new FeedScope(self::INSTALLATION, 'seller-a', 'test', '1'), 'events-2026-10-v1', ['billium.event.v1'], $types, $required);
    }

    protected function publisher(?EventPublisher $publisher = null): EventPublisher
    {
        $publisher ??= new RecordingPublisher;
        $this->application()->instance(EventPublisher::class, $publisher);

        return $publisher;
    }

    protected function relay(): OutboxRelay
    {
        return $this->application()->make(OutboxRelay::class);
    }

    protected function pruner(): OutboxPruner
    {
        return new OutboxPruner($this->owner(), 0);
    }

    protected function source(string $key = 'ledger.obligations'): TestSnapshotSource
    {
        $source = new TestSnapshotSource($key);
        // Replace the production ledger stream, rather than registering a second source with the same key.
        $this->application()->instance('billium.snapshot.'.$key, $source);

        return $source;
    }

    protected function registerExecution(): EventContractRegistration
    {
        $modules = $this->application()->make(ModuleRegistry::class);
        $execution = $modules->descriptor('execution') ?? self::fail('execution descriptor missing');
        $registrar = new DatabaseEventContractRegistrar($this->owner(), $modules);
        $registrar->register($registrar->plan($execution, true));

        return EventContractRegistration::from('execution', $execution->publicEvents[0]);
    }

    /** @return array<string,mixed> */
    public static function effectPayload(): array
    {
        $fixture = self::arr(json_decode((string) file_get_contents(dirname(__DIR__, 6).'/fixtures/vectors/payment/effects/valid-capture.json'), true, 512, JSON_THROW_ON_ERROR));
        $script = self::arr($fixture['script']);
        $step = self::arr($script[0]);
        $args = self::arr($step['args']);
        $payload = json_decode(self::text($args['json_text']), false, 512, JSON_THROW_ON_ERROR);
        if (! $payload instanceof \stdClass) {
            self::fail('Effect fixture must be a JSON object');
        }
        $body = [];
        foreach (get_object_vars($payload) as $name => $value) {
            if (! is_string($name)) {
                self::fail('Effect fixture has a non-string member');
            }
            $body[$name] = $value;
        }

        return $body;
    }
}
