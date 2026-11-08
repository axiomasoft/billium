<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\CommandOutbox;

use Billium\Core\Contracts\EventPublisher;
use Billium\Platform\Data\Feed\RelayReport;
use Billium\Platform\Internal\Outbox\OutboxRelay;
use Illuminate\Database\Connection;

final readonly class CommandOutboxRelay
{
    private OutboxRelay $relay;

    public function __construct(Connection $db, EventPublisher $publisher, int $claimSeconds = 60, int $backoffSeconds = 5, int $budget = 10)
    {
        $this->relay = new OutboxRelay($db, $publisher, $claimSeconds, $backoffSeconds, $budget, 'command');
    }

    public function relay(int $limit = 100): RelayReport
    {
        return $this->relay->relay($limit);
    }

    public function requeue(string $commandRef, string $destinationRef): bool
    {
        return $this->relay->requeue($commandRef, $destinationRef);
    }
}
