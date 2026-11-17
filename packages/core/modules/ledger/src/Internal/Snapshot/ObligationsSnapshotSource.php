<?php

declare(strict_types=1);

namespace Billium\Ledger\Internal\Snapshot;

use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Platform\Contracts\Feed\FeedRecoveryQueries;
use Illuminate\Database\DatabaseManager;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ObligationsSnapshotSource extends LedgerSnapshotSource
{
    public function __construct(DatabaseManager $databases, CallerContext $callers, FeedRecoveryQueries $feedRecovery)
    {
        parent::__construct($databases, $callers, 'ledger.obligations', $feedRecovery);
    }
}
