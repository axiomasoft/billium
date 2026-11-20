<?php

declare(strict_types=1);

namespace Billium\Collection\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum CollectionChannel: string
{
    case SavedMethod = 'saved_method';
    case Mandate = 'mandate';
    case PaymentLink = 'payment_link';
}
