<?php

declare(strict_types=1);

namespace Billium\Platform\Data\Import;

use Packman\Attributes\Api;

/**
 * Per-record application status across the dependency-ordered import DAG.
 */
#[Api(since: '1.0')]
enum ImportRecordApplyStatus: string
{
    case Applied = 'applied';
    case AlreadyApplied = 'already_applied';
    case ObservationQueued = 'observation_queued';
    case ObservationParked = 'observation_parked';
    case UnanchoredEvidence = 'unanchored_evidence';
    case NonMonetaryMapped = 'non_monetary_mapped';
    case HistoricalInvoiceMapped = 'historical_invoice_mapped';
    case SettlementIntentDeclared = 'settlement_intent_declared';
    case Rejected = 'rejected';
    case Conflict = 'conflict';
}
