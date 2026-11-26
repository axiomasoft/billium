<?php

declare(strict_types=1);

namespace Billium\Documents\Contracts;

use Billium\Documents\Data\TaxArithmeticLineInput;
use Billium\Documents\Data\TaxArithmeticResult;
use Billium\Documents\Enums\DiscountTaxOrder;
use Billium\Documents\Enums\TaxConvention;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
interface DocumentTaxArithmetic
{
    /** @param list<TaxArithmeticLineInput> $lines */
    public function calculate(
        array $lines,
        TaxConvention $convention,
        string $currency,
        string $roundingMode,
        DiscountTaxOrder $order,
        ?string $noTaxReason = null,
    ): TaxArithmeticResult;
}
