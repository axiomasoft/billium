<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Locks;

use Billium\Core\Enums\Runner\LockRank;
use Packman\Attributes\Internal;

#[Internal]
final class UsageLockRanks
{
    public const LockRank Series = LockRank::UsageSeries;
}
