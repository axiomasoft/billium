<?php

declare(strict_types=1);

namespace Billium\Core\Enums\Modules;

use Billium\Contracts\Attributes\Api;

/** What a caller wants from a module; admission is decided per class (D55). */
#[Api]
enum ModuleOperationClass: string
{
    /** A new business intent, occurrence, usage admission or charge. */
    case NewIntent = 'new_intent';
    /** A pre-approved step of an already admitted intent under its frozen binding. */
    case FrozenIntentCompletion = 'frozen_intent_completion';
    /** A new refund command on an old charge: original owner only, by refund lock/capacity and current rights. */
    case OriginalOwnerRefund = 'original_owner_refund';
    case ReceiptReplay = 'receipt_replay';
    case HistoricalRead = 'historical_read';
    /** Lookup/reconciliation of an unknown effect; unknown is never cancelled by a timeout. */
    case UnknownResolution = 'unknown_resolution';
    /** Inbox custody and callback verification. */
    case CallbackCustody = 'callback_custody';
    /** Recording an immutable observed external fact; creates no new monetary intent. */
    case ObservedFactRecording = 'observed_fact_recording';
}
