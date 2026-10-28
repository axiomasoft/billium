<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Contracts\EventPublisher;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\TransactionContext;
use Billium\Core\Data\Receipt;
use Billium\Platform\Contracts\Outbox;
use Billium\Platform\Data\EventContracts\EventContractRegistration;
use Billium\Platform\Data\EventContracts\RegisteredPublicEvent;
use Billium\Platform\Internal\Outbox\OutboxRelay;
use Billium\Platform\Tests\Outbox\OutboxTestCase;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;

final class OutboxBackoffTest extends OutboxTestCase
{
    public function test_claim_expiration_and_cas_keep_identity_and_digest(): void
    {
        $this->events(1);
        $this->publisher();
        $first = $this->relay()->claim(1)[0];
        self::assertSame(0, count($this->relay()->claim(1)));
        $this->owner()->update("UPDATE billium_outbox_delivery SET claim_until = clock_timestamp() - interval '1 second'");
        $second = $this->relay()->claim(1)[0];
        self::assertNotSame($first['token'], $second['token']);
        self::assertSame((new Codec)->canonicalize($first['message']), (new Codec)->canonicalize($second['message']));
        self::assertFalse($this->relay()->acknowledge($first['id'], $first['token']));
        self::assertTrue($this->relay()->acknowledge($second['id'], $second['token']));
        self::assertSame(2, $this->integer($this->value('SELECT attempts FROM billium_outbox_delivery')));
    }

    public function test_repeated_claim_expiry_counts_as_failure_and_parks_at_the_budget(): void
    {
        config(['billium.outbox.delivery_budget' => 2]);
        $this->events(1);
        $this->publisher();
        $expire = fn () => $this->owner()->update("UPDATE billium_outbox_delivery SET claim_until = clock_timestamp() - interval '1 second'");
        self::assertCount(1, $this->relay()->claim(1));
        $expire();
        $second = $this->relay()->claim(1);
        self::assertCount(1, $second);
        self::assertSame(1, $second[0]['failures']);
        self::assertSame('claim_expired', $this->value('SELECT last_error_class FROM billium_outbox_delivery'));
        $expire();
        self::assertSame([], $this->relay()->claim(1), 'a message that keeps killing its worker is parked, not reclaimed forever');
        self::assertSame('parked', $this->value('SELECT status FROM billium_outbox_delivery'));
        self::assertSame('delivery_budget_exhausted', $this->value('SELECT park_reason FROM billium_outbox_delivery'));
        self::assertSame(2, $this->integer($this->value('SELECT consecutive_failures FROM billium_outbox_delivery')));
        self::assertTrue($this->relay()->requeue(self::text($this->value('SELECT event_id FROM billium_outbox_delivery')), 'default'));
    }

    public function test_poison_backoff_parks_without_starving_later_work_and_requeue_preserves_bytes(): void
    {
        config(['billium.outbox.delivery_budget' => 2]);
        $this->events(2);
        $poison = self::text($this->value('SELECT event_id FROM billium_outbox_event ORDER BY id LIMIT 1'));
        $digest = $this->value('SELECT digest FROM billium_outbox_event WHERE event_id = ?', [$poison]);
        $this->publisher(new readonly class($poison) implements EventPublisher
        {
            public function __construct(private string $poison) {}

            public function publish(string $destinationRef, array $message): void
            {
                if ($message['eventId'] === $this->poison) {
                    throw new RuntimeException('secret should never be persisted');
                }
            }
        });
        $report = $this->relay()->relay(2);
        self::assertSame(1, $report->delivered);
        self::assertSame(1, $report->failed);
        self::assertSame(0, $this->relay()->relay(2)->claimed);
        self::assertSame('pending', $this->value('SELECT status FROM billium_outbox_delivery WHERE event_id = ?', [$poison]));
        self::assertSame(RuntimeException::class, $this->value('SELECT last_error_class FROM billium_outbox_delivery WHERE event_id = ?', [$poison]));
        $this->owner()->update("UPDATE billium_outbox_delivery SET available_at = clock_timestamp() - interval '1 second' WHERE event_id = ?", [$poison]);
        self::assertSame(1, $this->relay()->relay(2)->failed);
        self::assertSame('parked', $this->value('SELECT status FROM billium_outbox_delivery WHERE event_id = ?', [$poison]));
        self::assertTrue($this->relay()->requeue($poison, 'default'));
        $this->publisher();
        $this->application()->forgetInstance(OutboxRelay::class);
        self::assertSame(1, $this->relay()->relay(2)->delivered);
        self::assertSame($digest, $this->value('SELECT digest FROM billium_outbox_event WHERE event_id = ?', [$poison]));
    }

    public function test_replay_of_a_row_with_noncanonical_legacy_scope_text_is_not_a_conflict(): void
    {
        $modules = $this->application()->make(ModuleRegistry::class);
        $ledger = $modules->descriptor('ledger') ?? self::fail('missing ledger descriptor');
        $event = new RegisteredPublicEvent(EventContractRegistration::from('ledger', $ledger->publicEvents[0]),
            self::event((string) Str::ulid(), 'obligation:legacy'), ['default']);
        $record = function () use ($event): void {
            $this->runner()->run($this->spec(), function (TransactionContext $context) use ($event): Receipt {
                $this->application()->make(Outbox::class)->record($context, $event);

                return self::receipt();
            });
        };
        $record();
        // The pre-canonical migration wrote jsonb text (spaces, jsonb key order) for existing rows.
        $owner = $this->owner();
        $owner->statement('ALTER TABLE billium_outbox_event DISABLE TRIGGER billium_outbox_event_append_only');
        $owner->update('UPDATE billium_outbox_event SET source_scope = source_scope::jsonb::text');
        $owner->statement('ALTER TABLE billium_outbox_event ENABLE TRIGGER billium_outbox_event_append_only');
        self::assertNotSame(1, preg_match('/^\{"channel":"events","environment":"test","installationRef"/', self::text($this->value('SELECT source_scope FROM billium_outbox_event'))));
        $record();
        self::assertSame(1, $this->rows('billium_outbox_event'));
    }

    public function test_relay_refuses_a_wrapping_transaction_before_claim_or_transport(): void
    {
        $this->events(1);
        $this->publisher();
        $this->expectException(LogicException::class);
        self::connection('pgsql')->transaction(fn () => $this->relay()->relay(1));
    }

    public function test_delivery_failure_and_prune_eligibility_are_per_destination(): void
    {
        $modules = $this->application()->make(ModuleRegistry::class);
        $ledger = $modules->descriptor('ledger') ?? self::fail('missing ledger descriptor');
        $contract = EventContractRegistration::from('ledger', $ledger->publicEvents[0]);
        $event = new RegisteredPublicEvent($contract,
            self::event((string) Str::ulid(), 'obligation:destinations'), ['a', 'b']);
        $this->runner()->run($this->spec(), function (TransactionContext $context) use ($event): Receipt {
            $this->application()->make(Outbox::class)->record($context, $event);

            return self::receipt();
        });
        $this->publisher(new class implements EventPublisher
        {
            public function publish(string $destinationRef, array $message): void
            {
                if ($destinationRef === 'a') {
                    throw new RuntimeException('failed destination a');
                }
            }
        });
        $report = $this->relay()->relay(2);
        self::assertSame(1, $report->delivered);
        self::assertSame(1, $report->failed);
        self::assertSame('delivered', $this->value("SELECT status FROM billium_outbox_delivery WHERE destination_ref = 'b'"));
        self::assertSame('pending', $this->value("SELECT status FROM billium_outbox_delivery WHERE destination_ref = 'a'"));
        self::assertSame(0, $this->pruner()->prune());
        self::assertSame(1, $this->rows('billium_outbox_event'));
    }
}
