<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Invoices;

use Packman\Attributes\Api;

/**
 * open_only credits at most the open amount; reverse_allocations first releases live allocations of the line
 * (last first, full reversal plus remainder) and never credits beyond due + adjusted − credited.
 */
#[Api(since: '1.0')]
enum CreditNoteMode: string
{
    case OpenOnly = 'open_only';
    case ReverseAllocations = 'reverse_allocations';
}
