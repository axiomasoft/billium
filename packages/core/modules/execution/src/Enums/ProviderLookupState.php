<?php

declare(strict_types=1);

namespace Billium\Execution\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ProviderLookupState: string
{
    case Found = 'found';
    case Pending = 'pending';
    case CertifiedAbsent = 'certified_absent';
    case Unavailable = 'unavailable';
    case Ambiguous = 'ambiguous';
    case Unsupported = 'unsupported';
}
