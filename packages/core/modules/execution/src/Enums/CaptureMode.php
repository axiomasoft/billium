<?php

declare(strict_types=1);

namespace Billium\Execution\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum CaptureMode: string
{
    case None = 'none';
    case Full = 'full';
    case PartialSingle = 'partial_single';
    case PartialMultiple = 'partial_multiple';
}
