<?php

declare(strict_types=1);

namespace Billium\Execution\Data;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Enums\CaptureMode;
use Billium\Execution\Enums\DriverCapability;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ProviderEvidenceMode;
use Billium\Execution\Enums\QualificationMode;
use DateTimeImmutable;
use Packman\Attributes\Api;

/** Immutable declaration only. Host-authenticated provider qualification binds the exact account, scope, version and evidence semantics before admission. No boolean or constructor grants execution authority. */
#[Api(since: '1.0')]
final readonly class CapabilitySnapshot
{
    /** @var list<EffectKind> */
    public array $supportedEffects;

    /** @var list<string> */
    public array $currencies;

    /**
     * @param  array<mixed>  $supportedEffects
     * @param  array<mixed>  $currencies
     */
    public function __construct(
        public string $snapshotRef,
        public string $driverId,
        public string $driverContractVersion,
        public string $capabilityVersion,
        public string $merchantAccountRef,
        array $supportedEffects,
        array $currencies,
        public ProviderEvidenceMode $evidenceMode,
        public bool $partialCapture,
        public bool $partialRefund,
        public bool $partialVoidAuthorization,
        public bool $authorizationExpiryEvidence,
        public bool $lookupByReference,
        public bool $inventoryComplete,
        public bool $absenceCertification,
        public ?DateTimeImmutable $idempotencyValidUntil,
        public QualificationMode $qualificationMode,
        public string $qualificationEvidenceRef,
        public string $digest,
        public CapabilityDetails $details,
    ) {
        if ($snapshotRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'snapshotRef must not be empty');
        }
        if ($driverId === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'driverId must not be empty');
        }
        if ($driverContractVersion === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'driverContractVersion must not be empty');
        }
        if ($capabilityVersion === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'capabilityVersion must not be empty');
        }
        if ($merchantAccountRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'merchantAccountRef must not be empty');
        }
        if (! array_is_list($supportedEffects)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'supportedEffects must be a list');
        }
        $supportedEffects = array_map(static function (mixed $item): EffectKind {
            if (! $item instanceof EffectKind) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'supportedEffects has an invalid element');
            }

            return $item;
        }, $supportedEffects);
        $this->supportedEffects = $supportedEffects;
        if (! array_is_list($currencies)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'currencies must be a list');
        }
        $currencies = array_map(static function (mixed $item): string {
            if (! is_string($item) || $item === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'currencies has an invalid element');
            }

            return $item;
        }, $currencies);
        $this->currencies = $currencies;
        if ($qualificationEvidenceRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'qualificationEvidenceRef must not be empty');
        }
        if ($digest === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'digest must not be empty');
        }
        if ($supportedEffects === [] || $currencies === []) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Snapshot requires explicit effects and currencies');
        }
        foreach ($currencies as $currency) {
            if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Capability currency must be an ISO code');
            }
        }
        $capture = in_array(EffectKind::Capture, $supportedEffects, true);
        $partial = in_array($details->captureMode, [CaptureMode::PartialSingle, CaptureMode::PartialMultiple], true);
        if ($partialCapture !== $partial || $capture !== ($details->captureMode !== CaptureMode::None)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Capture mode and effect flags disagree');
        }
        if (($partialRefund || $details->multipleRefund || $details->refundLookup) && ! in_array(EffectKind::Refund, $supportedEffects, true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Refund features require refund effect');
        }
        if (($partialVoidAuthorization || $authorizationExpiryEvidence) && ! in_array(EffectKind::Authorize, $supportedEffects, true)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Authorization features require authorization effect');
        }
        if ($absenceCertification && (! $inventoryComplete || ! $lookupByReference)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Absence capability requires complete reference inventory');
        }
    }

    /** Shape checks do not attest provider behavior; production additionally requires verified host qualification. */
    public function requireEffect(EffectKind $kind, string $currency, bool $production): void
    {
        if (! in_array($kind, $this->supportedEffects, true) || ! in_array($currency, $this->currencies, true) || ($production && $this->qualificationMode !== QualificationMode::ProviderVerified)) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Exact effect, currency and qualification are required');
        }
    }

    /** Requires a declared workflow feature; the host must still authenticate its exact qualification before effects. */
    public function requireCapability(DriverCapability $capability, bool $production): void
    {
        $supported = match ($capability) {
            DriverCapability::InitiateWithIdempotency => $this->details->initiateWithIdempotency,
            DriverCapability::InitiateWithoutIdempotency => $this->details->initiateWithoutIdempotency,
            DriverCapability::LookupByProviderId => $this->details->lookupByProviderId,
            DriverCapability::LookupByReference => $this->lookupByReference,
            DriverCapability::Authorize => in_array(EffectKind::Authorize, $this->supportedEffects, true),
            DriverCapability::Capture => in_array(EffectKind::Capture, $this->supportedEffects, true),
            DriverCapability::PartialCapture => $this->partialCapture,
            DriverCapability::MultipleCapture => $this->details->captureMode === CaptureMode::PartialMultiple,
            DriverCapability::VoidAuthorization => in_array(EffectKind::VoidAuthorization, $this->supportedEffects, true),
            DriverCapability::PartialVoidAuthorization => $this->partialVoidAuthorization,
            DriverCapability::AuthorizationExpiryEvidence => $this->authorizationExpiryEvidence,
            DriverCapability::PartialRefund => $this->partialRefund,
            DriverCapability::MultipleRefund => $this->details->multipleRefund,
            DriverCapability::RefundLookup => $this->details->refundLookup,
            DriverCapability::SavedMethod => $this->details->savedMethod,
            DriverCapability::RecurringConsent => $this->details->recurringConsent,
            DriverCapability::WebhookAuthenticity => $this->details->signedCallbacks,
            DriverCapability::WebhookEventIdentity => $this->details->callbackEventIdentity,
            DriverCapability::WebhookEventLookup => $this->details->callbackEventLookup,
            DriverCapability::AsynchronousStates => $this->details->asynchronousStates,
            DriverCapability::SettlementListing => $this->details->settlementListing,
            DriverCapability::SettlementExport => $this->details->settlementExport,
            DriverCapability::Disputes => $this->details->disputes,
            DriverCapability::FiscalPassthrough => $this->details->fiscalPassthrough,
            DriverCapability::InventoryComplete => $this->inventoryComplete,
            DriverCapability::AbsenceCertification => $this->absenceCertification,
        };
        if (! $supported || ($production && $this->qualificationMode !== QualificationMode::ProviderVerified)) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Exact workflow capability and qualification are required');
        }
    }
}
