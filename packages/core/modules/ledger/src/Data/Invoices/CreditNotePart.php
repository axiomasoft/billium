<?php

declare(strict_types=1);

namespace Billium\Ledger\Data\Invoices;

use Packman\Attributes\Api;

/** The lines of one invoice inside a credit that may span several invoices. */
#[Api(since: '1.0')]
final readonly class CreditNotePart
{
    /** @param list<CreditNoteLine> $lines */
    public function __construct(public string $invoiceRef, public array $lines) {}
}
