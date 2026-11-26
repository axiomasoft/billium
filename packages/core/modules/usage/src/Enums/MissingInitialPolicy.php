<?php

declare(strict_types=1);

namespace Billium\Usage\Enums;

use Packman\Attributes\Api;

#[Api]
enum MissingInitialPolicy: string
{
    case Refuse = 'refuse';
    case DeclaredZero = 'declared_zero';
}
