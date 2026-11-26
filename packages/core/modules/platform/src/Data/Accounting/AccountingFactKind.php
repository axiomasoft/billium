<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Accounting;

use Packman\Attributes\Api;

/**
 * Canonical economic fact kinds emitted by the accounting mapper.
 */
#[Api(since: '1.0')]
enum AccountingFactKind: string
{
    case PaymentCaptured = 'payment.captured';
    case RefundSucceeded = 'refund.succeeded';
    case InvoiceIssued = 'invoice.issued';
    case CreditNoteIssued = 'credit_note.issued';
    case WriteOffRecorded = 'write_off.recorded';
    case AllocationApplied = 'allocation.applied';
    case AllocationReversed = 'allocation.reversed';
    case OverRefundExposure = 'exposure.over_refund';
    case OverCaptureExposure = 'exposure.over_capture';
    case DiscrepancyExposure = 'exposure.discrepancy';

    public function category(): AccountingFactCategory
    {
        return match ($this) {
            self::PaymentCaptured => AccountingFactCategory::CashIn,
            self::RefundSucceeded => AccountingFactCategory::CashOut,
            self::InvoiceIssued, self::CreditNoteIssued, self::WriteOffRecorded => AccountingFactCategory::Receivable,
            self::AllocationApplied, self::AllocationReversed => AccountingFactCategory::NoncashAllocation,
            self::OverRefundExposure, self::OverCaptureExposure, self::DiscrepancyExposure => AccountingFactCategory::NoncashExposure,
        };
    }
}
