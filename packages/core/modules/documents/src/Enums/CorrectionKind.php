<?php

declare(strict_types=1);

namespace Billium\Documents\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum CorrectionKind: string
{
    case CreditNote = 'credit_note';
    case DocumentCorrection = 'document_correction';
    case VoidReissue = 'void_reissue';
    case WriteOff = 'write_off';
}
