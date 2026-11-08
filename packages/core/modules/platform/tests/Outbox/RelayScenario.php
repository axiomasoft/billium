<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Outbox;

use Billium\Core\Contracts\EventPublisher;
use Billium\Core\Tests\Support\Concurrency\Scenario;
use Billium\Platform\Internal\Outbox\OutboxRelay;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use LogicException;

final class RelayScenario implements Scenario
{
    public function run(Application $app, int $index, array $args): array
    {
        $app->instance(EventPublisher::class, new class implements EventPublisher
        {
            public function publish(string $destinationRef, array $message): void
            {
                $db = app(DatabaseManager::class)->connection('pgsql');
                if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
                    throw new LogicException('Transport in transaction');
                }
                $db->statement('INSERT INTO billium_test_relay_receiver(event_id) VALUES (?) ON CONFLICT (event_id) DO UPDATE SET deliveries = billium_test_relay_receiver.deliveries + 1', [$message['eventId']]);
            }
        });
        $sent = 0;
        do {
            $report = $app->make(OutboxRelay::class)->relay(10);
            $sent += $report->delivered;
        } while ($report->claimed > 0);

        return ['delivered' => $sent];
    }
}
