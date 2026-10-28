<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;
use Billium\Core\Contracts\TransactionContext;
use Billium\Platform\Data\EventContracts\RegisteredPublicEvent;
use Billium\Platform\Data\OutboxEvent;

/**
 * Transactional outbox (PF-10, COV-279): the event is written on the root connection inside the same root Tx as the
 * owning state and receipt, or not at all. An afterCommit callback may only wake delivery; it never replaces this row.
 * Delivery, relay and feed belong to P3.4.
 */
#[Api]
#[Spi(SpiKind::Call)]
interface Outbox
{
    /**
     * Returns the outbox row id; the content (and its digest) is immutable from here on.
     *
     * @throws \LogicException for a stale/foreign context or a payload beyond the size bound
     */
    public function record(TransactionContext $context, OutboxEvent|RegisteredPublicEvent $event): int;
}
