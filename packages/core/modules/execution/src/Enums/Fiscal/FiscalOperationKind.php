<?php

declare(strict_types=1);

namespace Billium\Execution\Enums\Fiscal;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum FiscalOperationKind: string
{
    case Charge = 'charge';
    case Capture = 'capture';
    case Refund = 'refund';
    case Correction = 'correction';
}
