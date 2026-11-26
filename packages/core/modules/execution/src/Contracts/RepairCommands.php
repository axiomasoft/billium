<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Execution\Data\RecoveryAction;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
interface RepairCommands
{
    /** Apply a keyed, privileged disposition to one immutable comparison event of the original root. */
    public function repair(RecoveryAction $action, string $reviewRef): StoredOutcome;
}
