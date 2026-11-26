<?php

declare(strict_types=1);

namespace Billium\Documents\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum RenderState: string
{
    case Pending = 'pending';
    case Rendering = 'rendering';
    case Ready = 'ready';
    case Failed = 'failed';
    case Superseded = 'superseded';
}
