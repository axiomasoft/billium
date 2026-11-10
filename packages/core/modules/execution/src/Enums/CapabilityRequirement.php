<?php

declare(strict_types=1);

namespace Billium\Execution\Enums;

use Billium\Contracts\Attributes\Api;

#[Api]
enum CapabilityRequirement: string
{
    case Sale = 'initiate';
    case Authorize = 'authorize';
    case IdempotentCreate = 'idempotent_create';
    case FindProvider = 'find_provider';
    case FindBusiness = 'find_business';
    case Capture = 'capture';
    case PartialCapture = 'partial_capture';
    case MultipleCapture = 'multiple_capture';
    case VoidAuthorization = 'void_authorization';
    case Refund = 'refund';
    case PartialRefund = 'partial_refund';
    case MultipleRefund = 'multiple_refund';
    case FindRefund = 'find_refund';
    case VerifyCallback = 'verify_callback';
    case FindEvent = 'find_event';
    case Inventory = 'inventory';
    case Settlement = 'settlement';
    case Disputes = 'disputes';
    case SavedMethod = 'saved_method';
    case RecurringConsent = 'recurring_consent';
    case FiscalPassthrough = 'fiscal_passthrough';
}
