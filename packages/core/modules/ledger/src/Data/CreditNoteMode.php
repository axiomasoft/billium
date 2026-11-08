<?php

declare(strict_types=1);

namespace Billium\Ledger\Data;

use Billium\Contracts\Attributes\Api;

/**
 * open_only credits at most the open amount; reverse_allocations first releases live allocations of the line
 * (last first, full reversal plus remainder) and never credits beyond due + adjusted − credited.
 */
#[Api]
enum CreditNoteMode: string
{
    case OpenOnly = 'open_only';
    case ReverseAllocations = 'reverse_allocations';
}
