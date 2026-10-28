<?php

declare(strict_types=1);

namespace Billium\Core\Contracts;

use Billium\Contracts\Attributes\Api;
use Billium\Contracts\Attributes\Spi;
use Billium\Contracts\Attributes\SpiKind;

/** D63/BC-EVT-04/05: host transport, outside a Tx. ACK is delivery only; dedupe stable eventId/commandRef. */
#[Api]
#[Spi(SpiKind::Implement)]
interface EventPublisher
{
    /** @param array<string,mixed> $message Immutable public event or separate billium.command envelope. */
    public function publish(string $destinationRef, array $message): void;
}
