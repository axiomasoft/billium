<?php

declare(strict_types=1);

namespace Billium\Platform\Contracts\Outbox;

use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Platform\Data\EventContracts\RegisteredPublicEvent;
use Billium\Platform\Data\Outbox\OutboxEvent;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Transactional outbox (PF-10, COV-279): the event is written on the root connection inside the same root Tx as the
 * owning state and receipt, or not at all. An afterCommit callback may only wake delivery; it never replaces this row.
 * Delivery, relay and feed belong to P3.4.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface Outbox
{
    /**
     * Returns the outbox row id; the content (and its digest) is immutable from here on.
     *
     * @throws \LogicException for a stale/foreign context or a payload beyond the size bound
     */
    public function record(TransactionContext $context, OutboxEvent|RegisteredPublicEvent $event): int;
}
