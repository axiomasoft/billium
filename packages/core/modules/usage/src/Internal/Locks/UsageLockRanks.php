<?php

declare(strict_types=1);

namespace Billium\Usage\Internal\Locks;

use Billium\Core\Enums\LockRank;

final class UsageLockRanks
{
    public const LockRank Series = LockRank::UsageSeries;
}
