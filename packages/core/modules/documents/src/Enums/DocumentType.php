<?php

declare(strict_types=1);

namespace Billium\Documents\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum DocumentType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case Correction = 'correction';
}
