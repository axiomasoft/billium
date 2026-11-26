<?php

declare(strict_types=1);

namespace Billium\Execution\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum QualificationMode: string
{
    case Simulated = 'simulated';
    case ProviderVerified = 'provider_verified';
}
