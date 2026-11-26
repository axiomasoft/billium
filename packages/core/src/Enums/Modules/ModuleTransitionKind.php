<?php

declare(strict_types=1);

namespace Billium\Core\Enums\Modules;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ModuleTransitionKind: string
{
    case Lifecycle = 'lifecycle';
    /** Composer/binary removal; never a schema purge. */
    case Uninstall = 'uninstall';
}
