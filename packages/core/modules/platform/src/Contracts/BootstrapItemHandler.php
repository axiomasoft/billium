<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Core\Contracts\TransactionContext;
use Billium\Platform\Data\Inbox\BootstrapItem;
use Billium\Platform\Data\Inbox\HandlerOutcome;

/**
 * Applies one verified snapshot item in a recovery-session root Tx with the same economic dedupe as a live delivery.
 * `applied` and `conflict` (a recorded discrepancy) and `parkedDependency` (an explicit pending allocation, not lost
 * capture evidence) are all durable dispositions that let the bootstrap complete.
 */
#[Api]
#[Spi(SpiKind::Implement)]
interface BootstrapItemHandler
{
    public function apply(TransactionContext $context, BootstrapItem $item): HandlerOutcome;
}
