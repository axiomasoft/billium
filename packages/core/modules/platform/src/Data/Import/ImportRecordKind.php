<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Packman\Attributes\Api;

/**
 * Normalized product-agnostic classification of an external import record.
 */
#[Api(since: '1.0')]
enum ImportRecordKind: string
{
    case ProvenObligation = 'proven_obligation';
    case ProvenInvoiceHistory = 'proven_invoice_history';
    case ProvenPaymentEvidence = 'proven_payment_evidence';
    case ProvenRefundEvidence = 'proven_refund_evidence';
    case ProvenAllocation = 'proven_allocation';
    case NonfinancialAgreementReference = 'nonfinancial_agreement_reference';
    case UnanchoredEvidence = 'unanchored_evidence';
}
