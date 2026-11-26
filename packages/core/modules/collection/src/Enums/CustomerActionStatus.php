<?php

declare(strict_types=1);

namespace Billium\Collection\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum CustomerActionStatus: string
{
    case RequiresAction = 'requires_action';
    case Expired = 'expired';
    case Superseded = 'superseded';
}
