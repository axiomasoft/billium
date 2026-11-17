<?php

declare(strict_types=1);

namespace Billium\Core\Contracts\Runtime;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** D63/BC-EVT-04/05: host transport, outside a Tx. ACK is delivery only; dedupe stable eventId/commandRef. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface EventPublisher
{
    /** @param array<string,mixed> $message Immutable public event or separate billium.command envelope. */
    public function publish(string $destinationRef, array $message): void;
}
