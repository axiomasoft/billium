<?php

declare(strict_types=1);

namespace Billium\Ledger\Data;

use Billium\Contracts\Attributes\Api;

/** D35: credit_note closes the reopened debt with a credit note in the same Tx; reversal leaves it open without collection. */
#[Api]
enum ReallocationMode: string
{
    case CreditNote = 'credit_note';
    case Reversal = 'reversal';
}
