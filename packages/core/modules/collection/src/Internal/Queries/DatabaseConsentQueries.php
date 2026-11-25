<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Queries;

use Billium\Collection\Contracts\ConsentQueries;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\ConsentBindingView;
use Billium\Collection\Data\ConsentGuardStateView;
use Billium\Collection\Data\CustomerActionReceipt;
use Billium\Collection\Data\OriginalRefundRouteView;
use Billium\Collection\Data\RevocationReceipt;
use Billium\Collection\Enums\ConsentGuardStatus;
use Billium\Collection\Enums\ConsentPurpose;
use Billium\Collection\Enums\CustomerActionStatus;
use Billium\Collection\Enums\CustomerActionType;
use Billium\Collection\Enums\TombstoneKind;
use Billium\Collection\Internal\Support\RowCast;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runtime\CallerContext;
use Illuminate\Database\DatabaseManager;
use Override;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class DatabaseConsentQueries implements ConsentQueries
{
    public function __construct(
        private DatabaseManager $databases,
        private CallerContext $callers,
        private BilliumOptions $options,
    ) {}

    #[Override]
    public function findBinding(CollectionScope $scope, string $consentRef, int $consentVersion): ?ConsentBindingView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($consentRef, 'consentRef');

        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_consent_binding')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('consent_ref', $consentRef)
            ->where('consent_version', $consentVersion)
            ->first();

        return $row === null ? null : self::hydrateBinding($row);
    }

    #[Override]
    public function findGuardState(CollectionScope $scope, string $consentRef): ?ConsentGuardStateView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($consentRef, 'consentRef');

        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_consent_guard')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('consent_ref', $consentRef)
            ->first();

        return $row === null ? null : self::hydrateGuard($row);
    }

    /** @return list<RevocationReceipt> */
    #[Override]
    public function listTombstones(CollectionScope $scope, string $consentRef): array
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($consentRef, 'consentRef');

        $rows = $this->databases->connection($this->options->connection)
            ->table('billium_collection_guard_tombstone')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('consent_guard_id', ParentIdLookup::consentGuard($caller->sellerRef, $caller->environment, $consentRef))
            ->where('consent_ref', $consentRef)
            ->orderBy('guard_revision')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::hydrateTombstone($row);
        }

        return $out;
    }

    #[Override]
    public function findCustomerAction(CollectionScope $scope, string $actionRef): ?CustomerActionReceipt
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($actionRef, 'actionRef');

        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_customer_action')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('action_ref', $actionRef)
            ->first();

        return $row === null ? null : self::hydrateCustomerAction($row);
    }

    #[Override]
    public function findOriginalRefundRoute(CollectionScope $scope, string $operationRef): ?OriginalRefundRouteView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($operationRef, 'operationRef');

        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_refund_route')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('operation_ref', $operationRef)
            ->first();

        return $row === null ? null : self::hydrateRefundRoute($row);
    }

    public static function hydrateBinding(stdClass $row): ConsentBindingView
    {
        $purposes = [];
        foreach (RowCast::jsonList($row->allowed_purposes, 'allowed_purposes') as $item) {
            $purposes[] = ConsentPurpose::from(RowCast::str($item, 'allowed_purposes[]'));
        }
        $currencies = [];
        foreach (RowCast::jsonList($row->allowed_currencies, 'allowed_currencies') as $item) {
            $currencies[] = RowCast::str($item, 'allowed_currencies[]');
        }

        return new ConsentBindingView(
            id: RowCast::integer($row->id, 'id'),
            sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
            environment: RowCast::str($row->environment, 'environment'),
            billingAccountRef: RowCast::str($row->billing_account_ref, 'billing_account_ref'),
            customerRef: RowCast::str($row->customer_ref, 'customer_ref'),
            consentRef: RowCast::str($row->consent_ref, 'consent_ref'),
            consentVersion: RowCast::integer($row->consent_version, 'consent_version'),
            paymentMethodRef: RowCast::str($row->payment_method_ref, 'payment_method_ref'),
            tokenLocator: RowCast::str($row->token_locator, 'token_locator'),
            merchantAccountRef: RowCast::str($row->merchant_account_ref, 'merchant_account_ref'),
            merchantConfigRevision: RowCast::str($row->merchant_config_revision, 'merchant_config_revision'),
            providerId: RowCast::str($row->provider_id, 'provider_id'),
            driverId: RowCast::str($row->driver_id, 'driver_id'),
            driverContractVersion: RowCast::str($row->driver_contract_version, 'driver_contract_version'),
            capabilitySnapshotRef: RowCast::str($row->capability_snapshot_ref, 'capability_snapshot_ref'),
            capabilityDigest: RowCast::str($row->capability_digest, 'capability_digest'),
            allowedPurposes: $purposes,
            allowedCurrencies: $currencies,
            perChargeCapMinor: RowCast::optInt($row->per_charge_cap_minor, 'per_charge_cap_minor'),
            perPeriodCapMinor: RowCast::optInt($row->per_period_cap_minor, 'per_period_cap_minor'),
            capCurrency: RowCast::optStr($row->cap_currency, 'cap_currency'),
            capturedAt: RowCast::utcDateTime($row->captured_at, 'captured_at'),
            validFrom: RowCast::utcDateTime($row->valid_from, 'valid_from'),
            validUntil: RowCast::optUtcDateTime($row->valid_until, 'valid_until'),
            sourceEvidenceRef: RowCast::str($row->source_evidence_ref, 'source_evidence_ref'),
            sourceEvidenceDigest: RowCast::str($row->source_evidence_digest, 'source_evidence_digest'),
            proofDigest: RowCast::str($row->proof_digest, 'proof_digest'),
            supersedesBindingId: RowCast::optInt($row->supersedes_binding_id, 'supersedes_binding_id'),
            createdAt: RowCast::utcDateTime($row->created_at, 'created_at'),
        );
    }

    public static function hydrateGuard(stdClass $row): ConsentGuardStateView
    {
        return new ConsentGuardStateView(
            id: RowCast::integer($row->id, 'id'),
            sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
            environment: RowCast::str($row->environment, 'environment'),
            billingAccountRef: RowCast::str($row->billing_account_ref, 'billing_account_ref'),
            consentRef: RowCast::str($row->consent_ref, 'consent_ref'),
            activeBindingId: RowCast::integer($row->active_binding_id, 'active_binding_id'),
            activeConsentVersion: RowCast::integer($row->active_consent_version, 'active_consent_version'),
            guardRevision: RowCast::integer($row->guard_revision, 'guard_revision'),
            sourceRevision: RowCast::integer($row->source_revision, 'source_revision'),
            cancellationGeneration: RowCast::integer($row->cancellation_generation, 'cancellation_generation'),
            status: ConsentGuardStatus::from(RowCast::str($row->status, 'status')),
            revokedAt: RowCast::optUtcDateTime($row->revoked_at, 'revoked_at'),
            revocationReceiptRef: RowCast::optStr($row->revocation_receipt_ref, 'revocation_receipt_ref'),
            revocationReason: RowCast::optStr($row->revocation_reason, 'revocation_reason'),
            effectiveAt: RowCast::optUtcDateTime($row->effective_at, 'effective_at'),
            updatedAt: RowCast::utcDateTime($row->updated_at, 'updated_at'),
        );
    }

    public static function hydrateTombstone(stdClass $row, ?ConsentGuardStatus $status = null): RevocationReceipt
    {
        $kind = TombstoneKind::from(RowCast::str($row->tombstone_kind, 'tombstone_kind'));
        $resolvedStatus = $status ?? ($kind === TombstoneKind::HostCancelled
            ? ConsentGuardStatus::Cancelled
            : ConsentGuardStatus::Revoked);

        return new RevocationReceipt(
            tombstoneRef: RowCast::str($row->tombstone_ref, 'tombstone_ref'),
            consentGuardId: RowCast::integer($row->consent_guard_id, 'consent_guard_id'),
            consentRef: RowCast::str($row->consent_ref, 'consent_ref'),
            consentVersion: RowCast::integer($row->consent_version, 'consent_version'),
            tombstoneKind: $kind,
            status: $resolvedStatus,
            guardRevision: RowCast::integer($row->guard_revision, 'guard_revision'),
            sourceRevision: RowCast::integer($row->source_revision, 'source_revision'),
            cancellationGeneration: RowCast::integer($row->cancellation_generation, 'cancellation_generation'),
            targetAuthorizationRef: RowCast::optStr($row->target_authorization_ref, 'target_authorization_ref'),
            targetBusinessIntentRef: RowCast::optStr($row->target_business_intent_ref, 'target_business_intent_ref'),
            reason: RowCast::str($row->reason, 'reason'),
            evidenceDigest: RowCast::str($row->evidence_digest, 'evidence_digest'),
            effectiveAt: RowCast::utcDateTime($row->effective_at, 'effective_at'),
            recordedAt: RowCast::utcDateTime($row->recorded_at, 'recorded_at'),
        );
    }

    public static function hydrateCustomerAction(stdClass $row): CustomerActionReceipt
    {
        return new CustomerActionReceipt(
            id: RowCast::integer($row->id, 'id'),
            sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
            environment: RowCast::str($row->environment, 'environment'),
            actionRef: RowCast::str($row->action_ref, 'action_ref'),
            billingAccountRef: RowCast::str($row->billing_account_ref, 'billing_account_ref'),
            consentBindingId: RowCast::integer($row->consent_binding_id, 'consent_binding_id'),
            consentRef: RowCast::str($row->consent_ref, 'consent_ref'),
            consentVersion: RowCast::integer($row->consent_version, 'consent_version'),
            operationRef: RowCast::optStr($row->operation_ref, 'operation_ref'),
            businessPaymentIntentRef: RowCast::str($row->business_payment_intent_ref, 'business_payment_intent_ref'),
            actionType: CustomerActionType::from(RowCast::str($row->action_type, 'action_type')),
            status: CustomerActionStatus::from(RowCast::str($row->status, 'status')),
            challengeRef: RowCast::str($row->challenge_ref, 'challenge_ref'),
            actionPayload: RowCast::jsonObject($row->action_payload, 'action_payload'),
            expiresAt: RowCast::utcDateTime($row->expires_at, 'expires_at'),
            receiptDigest: RowCast::str($row->receipt_digest, 'receipt_digest'),
            recordedAt: RowCast::utcDateTime($row->recorded_at, 'recorded_at'),
            paid: false,
            terminalFailure: false,
        );
    }

    public static function hydrateRefundRoute(stdClass $row): OriginalRefundRouteView
    {
        return new OriginalRefundRouteView(
            id: RowCast::integer($row->id, 'id'),
            sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
            environment: RowCast::str($row->environment, 'environment'),
            operationRef: RowCast::str($row->operation_ref, 'operation_ref'),
            billingAccountRef: RowCast::str($row->billing_account_ref, 'billing_account_ref'),
            originalBindingId: RowCast::integer($row->original_binding_id, 'original_binding_id'),
            originalConsentRef: RowCast::str($row->original_consent_ref, 'original_consent_ref'),
            originalConsentVersion: RowCast::integer($row->original_consent_version, 'original_consent_version'),
            originalMerchantAccountRef: RowCast::str($row->original_merchant_account_ref, 'original_merchant_account_ref'),
            originalMerchantConfigRevision: RowCast::str($row->original_merchant_config_revision, 'original_merchant_config_revision'),
            originalProviderId: RowCast::str($row->original_provider_id, 'original_provider_id'),
            originalDriverId: RowCast::str($row->original_driver_id, 'original_driver_id'),
            originalDriverContractVersion: RowCast::str($row->original_driver_contract_version, 'original_driver_contract_version'),
            originalPaymentMethodRef: RowCast::str($row->original_payment_method_ref, 'original_payment_method_ref'),
            routeDigest: RowCast::str($row->route_digest, 'route_digest'),
            recordedAt: RowCast::utcDateTime($row->recorded_at, 'recorded_at'),
        );
    }
}
