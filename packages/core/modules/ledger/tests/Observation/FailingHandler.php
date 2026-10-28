<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Observation;

use Billium\Core\Contracts\TransactionContext;
use Billium\Platform\Contracts\CustodyHandler;
use Billium\Platform\Data\Inbox\HandlerOutcome;
use Billium\Platform\Data\Inbox\InboxRow;
use RuntimeException;
use stdClass;

/** PF-12 fault: the real economic apply (allocation written) succeeds, then the root fails before the inbox is applied. */
final class FailingHandler implements CustodyHandler
{
    public int $calls = 0;

    public function __construct(private readonly CustodyHandler $inner) {}

    public function handle(TransactionContext $context, InboxRow $row, stdClass $payload): HandlerOutcome
    {
        $this->calls++;
        $this->inner->handle($context, $row, $payload);

        throw new RuntimeException('injected failure after allocation, before inbox status applied');
    }
}
