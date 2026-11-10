<?php

declare(strict_types=1);

namespace Billium\Rating\Enums;

use Billium\Contracts\Attributes\Api;

#[Api]
enum ReleasePolicy: string
{
    case OpenOnly = 'open_only';
    case ReverseAllocations = 'reverse_allocations';
}
