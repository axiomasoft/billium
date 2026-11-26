<?php

declare(strict_types=1);

namespace Billium\Documents\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum TaxCorrectionTreatment: string
{
    case OriginalRate = 'original_rate';
    case CurrentLegalRate = 'current_legal_rate';
    case OtherSupported = 'other_supported';
}
