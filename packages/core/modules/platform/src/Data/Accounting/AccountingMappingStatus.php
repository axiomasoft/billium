<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Accounting;

use Packman\Attributes\Api;

/**
 * Outcome of mapping an authenticated public source record into an accounting fact.
 */
#[Api(since: '1.0')]
enum AccountingMappingStatus: string
{
    case Mapped = 'mapped';
    case IgnoredNonMonetary = 'ignored_non_monetary';
    case QuarantinedUnsupportedSchema = 'quarantined_unsupported_schema';
}
