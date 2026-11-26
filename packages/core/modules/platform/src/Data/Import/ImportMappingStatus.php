<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Packman\Attributes\Api;

/**
 * State of a durable source identity mapping entry.
 */
#[Api(since: '1.0')]
enum ImportMappingStatus: string
{
    case Active = 'active';
    case Superseded = 'superseded';
    case Tombstoned = 'tombstoned';
    case Quarantined = 'quarantined';
}
