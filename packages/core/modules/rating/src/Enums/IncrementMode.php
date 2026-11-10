<?php

declare(strict_types=1);

namespace Billium\Rating\Enums;

use Billium\Contracts\Attributes\Api;

#[Api]
enum IncrementMode: string
{
    case None = 'none';
    case Ceil = 'ceil';
    case Floor = 'floor';
}
