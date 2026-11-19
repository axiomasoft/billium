<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

/** Commercial line basis entered into document finalization. */
#[Api(since: '1.0')]
final readonly class DocumentLineBasis
{
    public function __construct(
        public string $lineRef,
        public string $obligationRef,
        public string $componentRef,
        public string $quantity,
        public string $unitPriceDecimal,
        public int $netMinor,
        public int $discountMinor,
        public string $taxCode,
        public string $taxRate,
        public string $description = '',
    ) {}
}
