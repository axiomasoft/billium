<?php

declare(strict_types=1);

namespace Billium\Documents\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum TaxConvention: string
{
    case Exclusive = 'exclusive';
    case Inclusive = 'inclusive';
    case NoneDeclared = 'none_declared';
}
