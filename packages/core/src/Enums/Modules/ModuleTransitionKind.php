<?php

declare(strict_types=1);

namespace Billium\Core\Enums\Modules;

use Billium\Contracts\Attributes\Api;

#[Api]
enum ModuleTransitionKind: string
{
    case Lifecycle = 'lifecycle';
    /** Composer/binary removal; never a schema purge. */
    case Uninstall = 'uninstall';
}
