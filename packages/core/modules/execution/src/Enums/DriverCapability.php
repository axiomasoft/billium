<?php

declare(strict_types=1);

namespace Billium\Execution\Enums;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum DriverCapability: string
{
    case InitiateWithIdempotency = 'initiate_with_idempotency';
    case InitiateWithoutIdempotency = 'initiate_without_idempotency';
    case LookupByProviderId = 'lookup_by_provider_id';
    case LookupByReference = 'lookup_by_reference';
    case Authorize = 'authorize';
    case Capture = 'capture';
    case PartialCapture = 'partial_capture';
    case MultipleCapture = 'multiple_capture';
    case VoidAuthorization = 'void_authorization';
    case PartialVoidAuthorization = 'partial_void_authorization';
    case AuthorizationExpiryEvidence = 'authorization_expiry_evidence';
    case PartialRefund = 'partial_refund';
    case MultipleRefund = 'multiple_refund';
    case RefundLookup = 'refund_lookup';
    case SavedMethod = 'saved_method';
    case RecurringConsent = 'recurring_consent';
    case WebhookAuthenticity = 'webhook_authenticity';
    case WebhookEventIdentity = 'webhook_event_identity';
    case WebhookEventLookup = 'webhook_event_lookup';
    case AsynchronousStates = 'asynchronous_states';
    case SettlementListing = 'settlement_listing';
    case SettlementExport = 'settlement_export';
    case Disputes = 'disputes';
    case FiscalPassthrough = 'fiscal_passthrough';
    case InventoryComplete = 'inventory_complete';
    case AbsenceCertification = 'absence_certification';
}
