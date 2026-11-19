<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class CreditNoteLineRequest
{
    public function __construct(
        public string $originalInvoiceLineId,
        public string $creditedQuantity,
        public int $requestedGrossMinor,
        public string $taxCode = 'STANDARD',
    ) {}
}
