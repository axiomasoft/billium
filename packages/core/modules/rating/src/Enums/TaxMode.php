<?php

declare(strict_types=1);

namespace Billium\Rating\Enums;

use Billium\Contracts\Attributes\Api;

#[Api]
enum TaxMode: string
{
    case Exclusive = 'exclusive';
    case Inclusive = 'inclusive';
    case NoneDeclared = 'none_declared';
}
