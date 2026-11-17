<?php

declare(strict_types=1);

namespace Billium\Execution\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ExecutionPhase: string
{
    case Admission = 'admission';
    case Dispatch = 'dispatch';
}
