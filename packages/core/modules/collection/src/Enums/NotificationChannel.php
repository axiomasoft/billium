<?php

declare(strict_types=1);

namespace Billium\Collection\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum NotificationChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Webhook = 'webhook';
    case Push = 'push';
    case InApp = 'in_app';
}
