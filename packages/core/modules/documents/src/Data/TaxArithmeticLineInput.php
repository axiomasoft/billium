<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class TaxArithmeticLineInput
{
    public function __construct(
        public string $lineRef,
        public string $amountDecimal,
        public string $discountDecimal,
        public string $rateDecimal,
        public string $taxCode = 'STANDARD',
    ) {}
}
