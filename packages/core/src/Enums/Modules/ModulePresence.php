<?php

declare(strict_types=1);

namespace Billium\Core\Enums\Modules;

use Billium\Contracts\Attributes\Api;

/** Mandatory / optional / forbidden in a supported composition. */
#[Api]
enum ModulePresence: string
{
    case Mandatory = 'M';
    case Optional = 'O';
    case Forbidden = 'X';
}
