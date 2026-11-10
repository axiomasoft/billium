<?php

declare(strict_types=1);

namespace Billium\Rating\Enums;

use Billium\Contracts\Attributes\Api;

#[Api]
enum CorrectionKind: string
{
    case LateUsage = 'late_usage';
    case PartialCancel = 'partial_cancel';
}
