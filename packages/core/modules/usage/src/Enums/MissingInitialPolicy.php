<?php

declare(strict_types=1);

namespace Billium\Usage\Enums;

enum MissingInitialPolicy: string
{
    case Refuse = 'refuse';
    case DeclaredZero = 'declared_zero';
}
