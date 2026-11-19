<?php

declare(strict_types=1);

namespace Billium\Execution\Enums\Fiscal;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum FiscalCallbackTrustStrategy: string
{
    case HmacSignature = 'hmac_signature';
    case DriverVerified = 'driver_verified';
    case MutualTls = 'mutual_tls';
}
