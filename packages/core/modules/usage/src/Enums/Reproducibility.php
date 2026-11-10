<?php

declare(strict_types=1);

namespace Billium\Usage\Enums;

enum Reproducibility: string
{
    case Reproducible = 'reproducible';
    case RetentionExpired = 'retention_expired';
    case Erased = 'erased';
}
