<?php

declare(strict_types=1);

namespace Billium\Execution\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum EffectKind: string
{
    case Charge = 'charge';
    case Authorize = 'authorize';
    case Capture = 'capture';
    case VoidAuthorization = 'void_authorization';
    case Refund = 'refund';
}
