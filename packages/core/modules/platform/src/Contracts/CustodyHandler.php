<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Core\Contracts\TransactionContext;
use Billium\Platform\Data\Inbox\HandlerOutcome;
use Billium\Platform\Data\Inbox\InboxRow;
use stdClass;

/**
 * The owning module's economic application of ONE accounted delivery (INV-08). It runs inside the root Tx of that single
 * event, after the partition lock (rank 30): it plans and locks its obligation/invoice/funds rows through the context in
 * the global order and writes its own state, receipt and outbox. Platform never loops over further events inside it.
 */
#[Api]
#[Spi(SpiKind::Implement)]
interface CustodyHandler
{
    /** @param stdClass $payload the decoded envelope as stored (objects stay objects) */
    public function handle(TransactionContext $context, InboxRow $row, stdClass $payload): HandlerOutcome;
}
