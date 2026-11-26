<?php

declare(strict_types=1);

namespace Billium\Rating\Enums;

use Packman\Attributes\Api;

#[Api]
enum BasisKind: string
{
    case Original = 'original';
    case Correction = 'correction';
}
