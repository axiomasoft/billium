<?php

declare(strict_types=1);

namespace Billium\Ledger\Data;

use Billium\Contracts\Attributes\Api;

/** One draft invoice line over an existing obligation; the line amount is the obligation's due + adjusted amount (INV-12). */
#[Api]
final readonly class InvoiceLineInput
{
    /** @param array<string, mixed> $snapshot frozen at issue */
    public function __construct(
        public string $obligationRef,
        public int $taxMinor = 0,
        public array $snapshot = [],
    ) {}
}
