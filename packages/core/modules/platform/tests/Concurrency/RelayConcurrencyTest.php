<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Concurrency;

use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Platform\Tests\Outbox\OutboxTestCase;
use Billium\Platform\Tests\Outbox\RelayScenario;
use PHPUnit\Framework\Attributes\Group;

#[Group('ACC-CON-14')]
#[Group('pg')]
final class RelayConcurrencyTest extends OutboxTestCase
{
    public function test_four_relays_deliver_one_hundred_durable_events_with_receiver_dedupe(): void
    {
        $this->owner()->unprepared('CREATE TABLE billium_test_relay_receiver(event_id text PRIMARY KEY,deliveries integer NOT NULL DEFAULT 1)');
        $this->owner()->unprepared('GRANT SELECT,INSERT,UPDATE ON billium_test_relay_receiver TO billium_runtime');
        try {
            $this->events(100);
            // Simulate a worker dying after claiming; the four workers reclaim the identical durable rows.
            $this->publisher();
            $this->relay()->claim(5);
            $this->owner()->update("UPDATE billium_outbox_delivery SET claim_until = clock_timestamp() - interval '1 second'");
            $results = ConcurrencyStand::run(RelayScenario::class, 4);
            self::assertCount(4, $results);
            self::assertSame(100, $this->rows('billium_test_relay_receiver'));
            self::assertSame(100, $this->rows('billium_outbox_delivery', "status = 'delivered' AND delivered_at IS NOT NULL"));
            self::assertGreaterThanOrEqual(100, self::integer($this->value('SELECT sum(deliveries) FROM billium_test_relay_receiver')));
        } finally {
            $this->owner()->unprepared('DROP TABLE billium_test_relay_receiver');
        }
    }
}
