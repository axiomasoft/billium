<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Packman\Attributes\Api;

/**
 * Typed validation disposition of a single external import record in preview.
 */
#[Api(since: '1.0')]
enum ImportValidationStatus: string
{
    case Ready = 'ready';
    case UnanchoredEvidence = 'unanchored_evidence';
    case Rejected = 'rejected';
    case Conflict = 'conflict';
    case AlreadyApplied = 'already_applied';
}
