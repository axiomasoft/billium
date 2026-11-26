<?php

declare(strict_types=1);

namespace Billium\Documents\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum DiscountTaxOrder: string
{
    case DiscountBeforeTax = 'discount_before_tax';
    case TaxBeforeDiscount = 'tax_before_discount';
}
