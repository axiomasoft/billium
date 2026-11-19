<?php

declare(strict_types=1);

namespace Billium\Execution\Enums\Fiscal;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum FiscalReceiptKind: string
{
    case Sale = 'sale';
    case Refund = 'refund';
    case CorrectionSale = 'correction_sale';
    case CorrectionRefund = 'correction_refund';

    public function correctionKind(): self
    {
        return match ($this) {
            self::Sale, self::CorrectionSale => self::CorrectionSale,
            self::Refund, self::CorrectionRefund => self::CorrectionRefund,
        };
    }
}
