<?php

declare(strict_types=1);

namespace Billium\Usage\Enums;

use Billium\Contracts\Attributes\Api;

#[Api]
enum Reproducibility: string
{
    case Reproducible = 'reproducible';
    case RetentionExpired = 'retention_expired';
    case Erased = 'erased';
}
