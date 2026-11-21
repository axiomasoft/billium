<?php

declare(strict_types=1);

namespace Billium\Contracts\Errors;

use Billium\Contracts\Enums\ProblemCode;
use Override;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;
use Packman\Errors\Enums\ErrorCategory;

/**
 * Single-dot Packman error codes paired 1:1 with wire {@see ProblemCode} values.
 */
#[Api(since: '1.0')]
enum BilliumErrorCode: string implements ErrorCode
{
    case ValidationInvalid = 'billium.validation_invalid';
    case SchemaUnsupported = 'billium.schema_unsupported';
    case MoneyScaleInvalid = 'billium.money_scale_invalid';
    case MoneyCurrencyMismatch = 'billium.money_currency_mismatch';
    case AuthDenied = 'billium.auth_denied';
    case IdempotencyConflict = 'billium.idempotency_conflict';
    case IdempotencyInFlight = 'billium.idempotency_in_flight';
    case KeyExpired = 'billium.key_expired';
    case RecoveryRequired = 'billium.recovery_required';
    case LookupUnavailable = 'billium.lookup_unavailable';
    case RefundCapacityExceeded = 'billium.refund_capacity_exceeded';
    case OperationNotCancellable = 'billium.operation_not_cancellable';
    case ObservationParked = 'billium.observation_parked';
    case ObservationConflict = 'billium.observation_conflict';
    case UsageConflict = 'billium.usage_conflict';
    case PolicyUnknown = 'billium.policy_unknown';
    case InvoiceFrozen = 'billium.invoice_frozen';
    case AllocationReallocationRequired = 'billium.allocation_reallocation_required';
    case InstallationQuarantined = 'billium.installation_quarantined';
    case TransportUnavailable = 'billium.transport_unavailable';
    case AllocationExceedsOpen = 'billium.allocation_exceeds_open';
    case AllocationExceedsAvailable = 'billium.allocation_exceeds_available';
    case InvoiceTransitionInvalid = 'billium.invoice_transition_invalid';
    case ReferenceUnknown = 'billium.reference_unknown';
    case CapabilityUnsupported = 'billium.capability_unsupported';
    case CaptureCapacityExceeded = 'billium.capture_capacity_exceeded';
    case AuthorizationCapacityExceeded = 'billium.authorization_capacity_exceeded';
    case OperationFinancialHold = 'billium.operation_financial_hold';
    case LookupAmbiguous = 'billium.lookup_ambiguous';
    case ModuleUnavailable = 'billium.module_unavailable';
    case AuthUnauthenticated = 'billium.auth_unauthenticated';

    #[Override]
    public function value(): string
    {
        return $this->value;
    }

    public static function fromProblemCode(ProblemCode $problem): self
    {
        return match ($problem) {
            ProblemCode::ValidationInvalid => self::ValidationInvalid,
            ProblemCode::SchemaUnsupported => self::SchemaUnsupported,
            ProblemCode::MoneyScaleInvalid => self::MoneyScaleInvalid,
            ProblemCode::MoneyCurrencyMismatch => self::MoneyCurrencyMismatch,
            ProblemCode::AuthDenied => self::AuthDenied,
            ProblemCode::IdempotencyConflict => self::IdempotencyConflict,
            ProblemCode::IdempotencyInFlight => self::IdempotencyInFlight,
            ProblemCode::KeyExpired => self::KeyExpired,
            ProblemCode::RecoveryRequired => self::RecoveryRequired,
            ProblemCode::LookupUnavailable => self::LookupUnavailable,
            ProblemCode::RefundCapacityExceeded => self::RefundCapacityExceeded,
            ProblemCode::OperationNotCancellable => self::OperationNotCancellable,
            ProblemCode::ObservationParked => self::ObservationParked,
            ProblemCode::ObservationConflict => self::ObservationConflict,
            ProblemCode::UsageConflict => self::UsageConflict,
            ProblemCode::PolicyUnknown => self::PolicyUnknown,
            ProblemCode::InvoiceFrozen => self::InvoiceFrozen,
            ProblemCode::AllocationReallocationRequired => self::AllocationReallocationRequired,
            ProblemCode::InstallationQuarantined => self::InstallationQuarantined,
            ProblemCode::TransportUnavailable => self::TransportUnavailable,
            ProblemCode::AllocationExceedsOpen => self::AllocationExceedsOpen,
            ProblemCode::AllocationExceedsAvailable => self::AllocationExceedsAvailable,
            ProblemCode::InvoiceTransitionInvalid => self::InvoiceTransitionInvalid,
            ProblemCode::ReferenceUnknown => self::ReferenceUnknown,
            ProblemCode::CapabilityUnsupported => self::CapabilityUnsupported,
            ProblemCode::CaptureCapacityExceeded => self::CaptureCapacityExceeded,
            ProblemCode::AuthorizationCapacityExceeded => self::AuthorizationCapacityExceeded,
            ProblemCode::OperationFinancialHold => self::OperationFinancialHold,
            ProblemCode::LookupAmbiguous => self::LookupAmbiguous,
            ProblemCode::ModuleUnavailable => self::ModuleUnavailable,
            ProblemCode::AuthUnauthenticated => self::AuthUnauthenticated,
        };
    }

    public function toProblemCode(): ProblemCode
    {
        return match ($this) {
            self::ValidationInvalid => ProblemCode::ValidationInvalid,
            self::SchemaUnsupported => ProblemCode::SchemaUnsupported,
            self::MoneyScaleInvalid => ProblemCode::MoneyScaleInvalid,
            self::MoneyCurrencyMismatch => ProblemCode::MoneyCurrencyMismatch,
            self::AuthDenied => ProblemCode::AuthDenied,
            self::IdempotencyConflict => ProblemCode::IdempotencyConflict,
            self::IdempotencyInFlight => ProblemCode::IdempotencyInFlight,
            self::KeyExpired => ProblemCode::KeyExpired,
            self::RecoveryRequired => ProblemCode::RecoveryRequired,
            self::LookupUnavailable => ProblemCode::LookupUnavailable,
            self::RefundCapacityExceeded => ProblemCode::RefundCapacityExceeded,
            self::OperationNotCancellable => ProblemCode::OperationNotCancellable,
            self::ObservationParked => ProblemCode::ObservationParked,
            self::ObservationConflict => ProblemCode::ObservationConflict,
            self::UsageConflict => ProblemCode::UsageConflict,
            self::PolicyUnknown => ProblemCode::PolicyUnknown,
            self::InvoiceFrozen => ProblemCode::InvoiceFrozen,
            self::AllocationReallocationRequired => ProblemCode::AllocationReallocationRequired,
            self::InstallationQuarantined => ProblemCode::InstallationQuarantined,
            self::TransportUnavailable => ProblemCode::TransportUnavailable,
            self::AllocationExceedsOpen => ProblemCode::AllocationExceedsOpen,
            self::AllocationExceedsAvailable => ProblemCode::AllocationExceedsAvailable,
            self::InvoiceTransitionInvalid => ProblemCode::InvoiceTransitionInvalid,
            self::ReferenceUnknown => ProblemCode::ReferenceUnknown,
            self::CapabilityUnsupported => ProblemCode::CapabilityUnsupported,
            self::CaptureCapacityExceeded => ProblemCode::CaptureCapacityExceeded,
            self::AuthorizationCapacityExceeded => ProblemCode::AuthorizationCapacityExceeded,
            self::OperationFinancialHold => ProblemCode::OperationFinancialHold,
            self::LookupAmbiguous => ProblemCode::LookupAmbiguous,
            self::ModuleUnavailable => ProblemCode::ModuleUnavailable,
            self::AuthUnauthenticated => ProblemCode::AuthUnauthenticated,
        };
    }

    public function category(): ErrorCategory
    {
        return match ($this) {
            self::ValidationInvalid,
            self::MoneyScaleInvalid,
            self::MoneyCurrencyMismatch,
            self::PolicyUnknown => ErrorCategory::Validation,
            self::SchemaUnsupported,
            self::CapabilityUnsupported => ErrorCategory::Unsupported,
            self::AuthDenied => ErrorCategory::Authorization,
            self::AuthUnauthenticated => ErrorCategory::Authentication,
            self::ModuleUnavailable => ErrorCategory::Conflict,
            self::ReferenceUnknown => ErrorCategory::NotFound,
            self::RecoveryRequired,
            self::LookupAmbiguous,
            self::LookupUnavailable,
            self::ObservationParked,
            self::InstallationQuarantined,
            self::TransportUnavailable => ErrorCategory::Unavailable,
            self::IdempotencyConflict,
            self::IdempotencyInFlight,
            self::KeyExpired,
            self::CaptureCapacityExceeded,
            self::AuthorizationCapacityExceeded,
            self::OperationFinancialHold,
            self::RefundCapacityExceeded,
            self::OperationNotCancellable,
            self::ObservationConflict,
            self::UsageConflict,
            self::InvoiceFrozen,
            self::AllocationReallocationRequired,
            self::AllocationExceedsOpen,
            self::AllocationExceedsAvailable,
            self::InvoiceTransitionInvalid => ErrorCategory::Conflict,
        };
    }

    public function defaultStatus(): int
    {
        return match ($this) {
            self::AuthUnauthenticated => 401,
            self::AuthDenied => 403,
            self::ReferenceUnknown => 404,
            self::KeyExpired => 410,
            self::ValidationInvalid,
            self::SchemaUnsupported,
            self::CapabilityUnsupported,
            self::MoneyScaleInvalid,
            self::MoneyCurrencyMismatch,
            self::PolicyUnknown => 422,
            self::RecoveryRequired,
            self::LookupAmbiguous,
            self::LookupUnavailable,
            self::ObservationParked,
            self::InstallationQuarantined,
            self::TransportUnavailable => 503,
            self::IdempotencyConflict,
            self::IdempotencyInFlight,
            self::CaptureCapacityExceeded,
            self::AuthorizationCapacityExceeded,
            self::OperationFinancialHold,
            self::RefundCapacityExceeded,
            self::OperationNotCancellable,
            self::ObservationConflict,
            self::UsageConflict,
            self::InvoiceFrozen,
            self::AllocationReallocationRequired,
            self::AllocationExceedsOpen,
            self::AllocationExceedsAvailable,
            self::InvoiceTransitionInvalid,
            self::ModuleUnavailable => 409,
        };
    }

    public function isRetryable(): bool
    {
        return $this->toProblemCode()->retryable();
    }

    public function title(): string
    {
        return match ($this) {
            self::ValidationInvalid => 'Validation failed.',
            self::SchemaUnsupported => 'Schema version is unsupported.',
            self::MoneyScaleInvalid => 'Money amount scale or currency format is invalid.',
            self::MoneyCurrencyMismatch => 'Money currencies do not match.',
            self::AuthDenied => 'Authorization or scope boundary denied.',
            self::IdempotencyConflict => 'Idempotency key reused with conflicting payload or scope.',
            self::IdempotencyInFlight => 'Idempotency key is currently in flight.',
            self::KeyExpired => 'Idempotency key has expired.',
            self::RecoveryRequired => 'Recovery barrier requires operator reconciliation.',
            self::LookupUnavailable => 'Lookup read store is temporarily unavailable.',
            self::RefundCapacityExceeded => 'Refund amount exceeds available payment capacity.',
            self::OperationNotCancellable => 'Operation can no longer be cancelled.',
            self::ObservationParked => 'Payment observation parked pending missing prerequisite.',
            self::ObservationConflict => 'Conflicting payment observation digest or revision.',
            self::UsageConflict => 'Conflicting usage fact digest or revision.',
            self::PolicyUnknown => 'Required monetary allocation or ledger policy is unknown.',
            self::InvoiceFrozen => 'Issued invoice cannot be modified directly.',
            self::AllocationReallocationRequired => 'Payment requires explicit reallocation after refund.',
            self::InstallationQuarantined => 'Installation admission quarantined pending verification.',
            self::TransportUnavailable => 'Transport is temporarily unavailable.',
            self::AllocationExceedsOpen => 'Allocation or adjustment exceeds open obligation balance.',
            self::AllocationExceedsAvailable => 'Allocation exceeds available payment or credit balance.',
            self::InvoiceTransitionInvalid => 'Invoice transition is invalid in its current state.',
            self::ReferenceUnknown => 'Referenced entity was not found within caller scope.',
            self::CapabilityUnsupported => 'Required provider capability is unsupported or unqualified.',
            self::CaptureCapacityExceeded => 'Capture amount exceeds available authorization capacity.',
            self::AuthorizationCapacityExceeded => 'Void amount exceeds available authorization capacity.',
            self::OperationFinancialHold => 'Operation has an unresolved financial hold.',
            self::LookupAmbiguous => 'Provider lookup evidence is ambiguous.',
            self::ModuleUnavailable => 'Requested module is not enabled.',
            self::AuthUnauthenticated => 'Authentication is missing or invalid.',
        };
    }
}
