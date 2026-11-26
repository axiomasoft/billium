<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Accounting;

use Packman\Attributes\Api;

/**
 * Economic category separating actual cash movement from bounded receivables, noncash allocations, and noncash exposure.
 */
#[Api(since: '1.0')]
enum AccountingFactCategory: string
{
    case CashIn = 'cash_in';
    case CashOut = 'cash_out';
    case Receivable = 'receivable';
    case NoncashAllocation = 'noncash_allocation';
    case NoncashExposure = 'noncash_exposure';

    public function isCash(): bool
    {
        return $this === self::CashIn || $this === self::CashOut;
    }

    public function isNoncash(): bool
    {
        return ! $this->isCash();
    }
}
