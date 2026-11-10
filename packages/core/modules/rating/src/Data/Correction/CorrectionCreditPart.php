<?php

declare(strict_types=1);

namespace Billium\Rating\Data\Correction;

use Billium\Contracts\Attributes\Api;

#[Api]
final readonly class CorrectionCreditPart
{
    public function __construct(
        public string $obligationRef,
        public string $invoiceRef,
        public string $amountMinor,
    ) {}
}
