<?php

declare(strict_types=1);

namespace Billium\Execution\Enums;

use Billium\Contracts\Attributes\Api;

#[Api]
enum EffectKind: string
{
    case Sale = 'sale';
    case Authorize = 'authorize';
    case Capture = 'capture';
    case VoidAuthorization = 'void_authorization';
    case Refund = 'refund';
}
