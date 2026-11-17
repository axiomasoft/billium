<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Inbox;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Platform\Data\Inbox\BootstrapItem;
use Billium\Platform\Data\Inbox\HandlerOutcome;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Applies one verified snapshot item in a recovery-session root Tx with the same economic dedupe as a live delivery.
 * `applied` and `conflict` (a recorded discrepancy) and `parkedDependency` (an explicit pending allocation, not lost
 * capture evidence) are all durable dispositions that let the bootstrap complete.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface BootstrapItemHandler
{
    public function apply(TransactionContext $context, BootstrapItem $item): HandlerOutcome;
}
