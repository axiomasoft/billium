<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Packman\Attributes\Api;

/**
 * Audit status recorded on an append-only import receipt.
 */
#[Api(since: '1.0')]
enum ImportReceiptStatus: string
{
    case Staged = 'staged';
    case Applied = 'applied';
    case ObservationQueued = 'observation_queued';
    case UnanchoredEvidence = 'unanchored_evidence';
    case Rejected = 'rejected';
    case Conflict = 'conflict';
    case Tombstoned = 'tombstoned';
}
