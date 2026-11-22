<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Packman\Attributes\Api;

/**
 * Lifecycle state of a platform-owned external import batch.
 */
#[Api(since: '1.0')]
enum ImportBatchStatus: string
{
    case Staged = 'staged';
    case Validated = 'validated';
    case Approved = 'approved';
    case Applying = 'applying';
    case Reconciled = 'reconciled';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Quarantined = 'quarantined';
}
