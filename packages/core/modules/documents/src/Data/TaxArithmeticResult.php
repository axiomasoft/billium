<?php

declare(strict_types=1);

namespace Billium\Documents\Data;

use Billium\Documents\Enums\DiscountTaxOrder;
use Billium\Documents\Enums\TaxConvention;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class TaxArithmeticResult
{
    /** @param list<TaxArithmeticLineResult> $lines */
    public function __construct(
        public TaxConvention $convention,
        public string $currency,
        public int $currencyExponent,
        public string $roundingMode,
        public DiscountTaxOrder $order,
        public int $subtotalMinor,
        public int $taxMinor,
        public int $totalMinor,
        public array $lines,
    ) {}
}
