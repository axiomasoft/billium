<?php

declare(strict_types=1);

namespace Billium\Collection\Contracts;

use Billium\Collection\Data\CollectionNotificationMessage;
use Billium\Collection\Data\NotificationTransportAck;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Optional host SPI for delivering deduplicated collection outbox notifications.
 *
 * Always invoked strictly outside every database transaction; a transport delivery ACK
 * never represents business or payment success.
 */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface CollectionNotificationPort
{
    public function deliver(CollectionNotificationMessage $message): NotificationTransportAck;
}
