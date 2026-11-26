<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Core\Contracts\Runner\TransactionContext;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * record(TransactionContext $ctx,string $commandRef,string $businessRef,PaymentObservation $observation): int; DELIVERY_QUERIES implements PlatformPaymentObservationSink delegating FactualCommandOutbox. No ledger call or SQL.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface PaymentObservationSink
{
    public function record(TransactionContext $ctx, string $commandRef, string $businessRef, PaymentObservation $observation): int;
}
