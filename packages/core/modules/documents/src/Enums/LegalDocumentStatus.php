<?php

declare(strict_types=1);

namespace Billium\Documents\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum LegalDocumentStatus: string
{
    case Issued = 'issued';
    case Voided = 'voided';
}
