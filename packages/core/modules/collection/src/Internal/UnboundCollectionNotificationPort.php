<?php

declare(strict_types=1);

namespace Billium\Collection\Internal;

use Billium\Collection\Contracts\CollectionNotificationPort;
use Billium\Collection\Data\CollectionNotificationMessage;
use Billium\Collection\Data\NotificationTransportAck;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Override;
use Packman\Attributes\Internal;

#[Internal]
final readonly class UnboundCollectionNotificationPort implements CollectionNotificationPort
{
    #[Override]
    public function deliver(CollectionNotificationMessage $message): NotificationTransportAck
    {
        throw new ProblemException(
            ProblemCode::LookupUnavailable,
            'billium.collection.notification_port_unbound',
        );
    }
}
