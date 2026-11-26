<?php

declare(strict_types=1);

namespace Billium\Collection\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum NotificationStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case FailedDelivery = 'failed_delivery';
}
