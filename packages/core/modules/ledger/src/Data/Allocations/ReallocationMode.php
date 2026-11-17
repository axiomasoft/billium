<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Allocations;

use Packman\Attributes\Api;

/** D35: credit_note closes the reopened debt with a credit note in the same Tx; reversal leaves it open without collection. */
#[Api(since: '1.0')]
enum ReallocationMode: string
{
    case CreditNote = 'credit_note';
    case Reversal = 'reversal';
}
