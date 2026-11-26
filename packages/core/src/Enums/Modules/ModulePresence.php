<?php

declare(strict_types=1);

namespace Billium\Core\Enums\Modules;

use Packman\Attributes\Api;

/** Mandatory / optional / forbidden in a supported composition. */
#[Api(since: '1.0')]
enum ModulePresence: string
{
    case Mandatory = 'M';
    case Optional = 'O';
    case Forbidden = 'X';
}
