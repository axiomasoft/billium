<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Reducers;

use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\ConsentBindingView;
use Billium\Collection\Data\ConsentChargeEligibilityRequest;
use Billium\Collection\Data\ConsentEligibilityVerdict;
use Billium\Collection\Data\ConsentGuardStateView;
use Billium\Collection\Data\HostConsentAuthoritySnapshot;
use Billium\Collection\Data\OriginalRefundRouteView;
use Billium\Collection\Data\RecordCustomerAction;
use Billium\Collection\Data\RecordOriginalRefundRoute;
use Billium\Collection\Data\RegisterConsentBinding;
use Billium\Collection\Data\RevokeConsentCommand;
use Billium\Collection\Enums\ConsentGuardStatus;
use Billium\Collection\Enums\ConsentPurpose;
use Billium\Collection\Enums\TombstoneKind;
use Billium\Collection\Internal\Support\RowCast;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Data\MerchantRevision;
use DateTimeImmutable;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class ConsentLifecycleReducer
{
    /**
     * Validate and normalize consent registration parameters.
     *
     * @return array{purposeValues: list<string>, currencies: list<string>, capCurrency: ?string}
     *
     * @throws ProblemException
     */
    public function validateAndNormalizeRegistration(RegisterConsentBinding $command): array
    {
        if ($command->consentVersion < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'consentVersion must be >= 1');
        }
        if ($command->sourceRevision < 0 || $command->cancellationGeneration < 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'sourceRevision and cancellationGeneration must be nonnegative');
        }
        if ($command->validUntil !== null && $command->validUntil <= $command->validFrom) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'validUntil must be strictly after validFrom');
        }
        if ($command->allowedPurposes === []) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'allowedPurposes must not be empty');
        }
        if ($command->allowedCurrencies === []) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'allowedCurrencies must not be empty');
        }

        $purposeValues = array_values(array_unique(array_map(
            static fn (ConsentPurpose $purpose): string => $purpose->value,
            $command->allowedPurposes,
        )));
        sort($purposeValues, SORT_STRING);

        $currencies = [];
        foreach ($command->allowedCurrencies as $currency) {
            $currencies[] = RowCast::currency($currency, 'allowedCurrencies[]');
        }
        $currencies = array_values(array_unique($currencies));
        sort($currencies, SORT_STRING);

        if ($command->perChargeCapMinor !== null && $command->perChargeCapMinor <= 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'perChargeCapMinor must be positive');
        }
        if ($command->perPeriodCapMinor !== null && $command->perPeriodCapMinor <= 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'perPeriodCapMinor must be positive');
        }
        if ($command->perChargeCapMinor !== null && $command->perPeriodCapMinor !== null
            && $command->perChargeCapMinor > $command->perPeriodCapMinor) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'perChargeCapMinor cannot exceed perPeriodCapMinor');
        }

        $capCurrency = $command->capCurrency;
        if ($command->perChargeCapMinor !== null || $command->perPeriodCapMinor !== null) {
            if ($capCurrency === null) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'capCurrency is required when caps are configured');
            }
            $capCurrency = RowCast::currency($capCurrency, 'capCurrency');
            if (! in_array($capCurrency, $currencies, true)) {
                throw new ProblemException(ProblemCode::MoneyCurrencyMismatch, 'capCurrency must be listed in allowedCurrencies');
            }
        } elseif ($capCurrency !== null) {
            $capCurrency = RowCast::currency($capCurrency, 'capCurrency');
        }

        return [
            'purposeValues' => $purposeValues,
            'currencies' => $currencies,
            'capCurrency' => $capCurrency,
        ];
    }

    /**
     * Validate superseding invariants against existing guard & active binding and return supersedesBindingId.
     *
     * @throws ProblemException
     */
    public function planSuperseding(
        RegisterConsentBinding $command,
        MerchantRevision $merchant,
        ?ConsentGuardStateView $existingGuard,
        ?ConsentBindingView $activeBinding,
    ): ?int {
        if ($existingGuard === null) {
            if ($command->supersedesConsentVersion !== null) {
                throw new ProblemException(
                    ProblemCode::ValidationInvalid,
                    'supersedesConsentVersion requires an existing consent binding',
                );
            }

            return null;
        }

        if ($existingGuard->status !== ConsentGuardStatus::Active) {
            throw new ProblemException(
                ProblemCode::OperationFinancialHold,
                'billium.collection.consent_revoked',
            );
        }
        if ($existingGuard->billingAccountRef !== $command->billingAccountRef) {
            throw new ProblemException(
                ProblemCode::AuthDenied,
                'billium.collection.consent_account_mismatch',
            );
        }
        if ($command->consentVersion <= $existingGuard->activeConsentVersion) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'consentVersion must strictly increase for an existing consentRef',
            );
        }
        if ($activeBinding === null) {
            throw new LogicException('Active consent binding vanished');
        }
        if ($activeBinding->customerRef !== $command->customerRef) {
            throw new ProblemException(
                ProblemCode::AuthDenied,
                'billium.collection.consent_customer_mismatch',
            );
        }

        $merchantChanged = $activeBinding->merchantAccountRef !== $merchant->merchantAccountRef
            || $activeBinding->merchantConfigRevision !== $merchant->revisionRef;
        if (
            $merchantChanged
            && (
                $activeBinding->sourceEvidenceRef === $command->sourceEvidenceRef
                || $activeBinding->sourceEvidenceDigest === $command->sourceEvidenceDigest
            )
        ) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'billium.collection.merchant_change_requires_new_consent',
            );
        }

        if ($command->supersedesConsentVersion !== null && $command->supersedesConsentVersion !== $existingGuard->activeConsentVersion) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'supersedesConsentVersion does not match current active version',
            );
        }

        if ($command->sourceRevision < $existingGuard->sourceRevision || $command->cancellationGeneration < $existingGuard->cancellationGeneration) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'sourceRevision and cancellationGeneration cannot regress when superseding binding',
            );
        }

        return $existingGuard->activeBindingId;
    }

    /**
     * Pure deterministic transition for guard revocation / host cancellation tombstones.
     *
     * @throws ProblemException
     */
    public function reduceRevocation(
        RevokeConsentCommand $command,
        ConsentGuardStateView $guard,
        DateTimeImmutable $nowUtc,
    ): ConsentRevocationDecision {
        if ($command->expectedGuardRevision !== null && $command->expectedGuardRevision !== $guard->guardRevision) {
            throw new ProblemException(
                ProblemCode::IdempotencyConflict,
                'billium.collection.guard_revision_mismatch',
            );
        }
        if ($command->sourceRevision < $guard->sourceRevision || $command->cancellationGeneration < $guard->cancellationGeneration) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'billium.collection.guard_revision_regression',
            );
        }

        $targetStatus = match (true) {
            $command->tombstoneKind === TombstoneKind::HostCancelled => ConsentGuardStatus::Cancelled,
            $guard->status === ConsentGuardStatus::Cancelled => ConsentGuardStatus::Cancelled,
            default => ConsentGuardStatus::Revoked,
        };

        return new ConsentRevocationDecision(
            newGuardRevision: $guard->guardRevision + 1,
            targetStatus: $targetStatus,
            effectiveAt: $guard->effectiveAt ?? $nowUtc,
            revokedAt: $guard->revokedAt ?? $nowUtc,
            revocationReceiptRef: $guard->revocationReceiptRef ?? $command->tombstoneRef,
            revocationReason: $guard->revocationReason ?? $command->reason,
        );
    }

    /**
     * Pure decision for host consent authority synchronization.
     *
     * @throws ProblemException
     */
    public function decideHostAuthoritySync(
        HostConsentAuthoritySnapshot $snapshot,
        CollectionScope $scope,
        string $billingAccountRef,
        string $consentRef,
        string $commandKey,
        string $tombstoneRef,
    ): ?RevokeConsentCommand {
        if (
            $snapshot->sellerRef !== $scope->sellerRef
            || $snapshot->environment !== $scope->environment
            || $snapshot->billingAccountRef !== $billingAccountRef
            || $snapshot->consentRef !== $consentRef
        ) {
            throw new ProblemException(
                ProblemCode::RecoveryRequired,
                'billium.collection.host_authority_scope_mismatch',
            );
        }

        if ($snapshot->active) {
            return null;
        }

        $kind = $snapshot->cancellationGeneration > 0
            ? TombstoneKind::HostCancelled
            : TombstoneKind::ConsentRevoked;

        return new RevokeConsentCommand(
            commandKey: $commandKey,
            scope: $scope,
            consentRef: $consentRef,
            tombstoneRef: $tombstoneRef,
            sourceRevision: $snapshot->sourceRevision,
            cancellationGeneration: $snapshot->cancellationGeneration,
            reason: $snapshot->reason ?? 'host_consent_inactive',
            evidenceDigest: $snapshot->authorityDigest,
            tombstoneKind: $kind,
        );
    }

    /**
     * Pure deterministic charge eligibility evaluation against binding, guard, and tombstone flags.
     */
    public function evaluateEligibility(
        ConsentChargeEligibilityRequest $request,
        ?ConsentBindingView $binding,
        ?ConsentGuardStateView $guard,
        bool $authorizationTombstoned,
        bool $businessIntentTombstoned,
    ): ConsentEligibilityVerdict {
        if ($binding === null) {
            return new ConsentEligibilityVerdict(false, 'consent_binding_not_found', null, null, null, null, null, null, null);
        }
        if ($guard === null) {
            return new ConsentEligibilityVerdict(false, 'consent_guard_not_found', $binding->id, null, null, null, null, null, $binding->proofDigest);
        }

        $deny = static fn (string $code): ConsentEligibilityVerdict => new ConsentEligibilityVerdict(
            permitted: false,
            denialCode: $code,
            bindingId: $binding->id,
            consentGuardId: $guard->id,
            guardRevision: $guard->guardRevision,
            sourceRevision: $guard->sourceRevision,
            cancellationGeneration: $guard->cancellationGeneration,
            guardStatus: $guard->status,
            proofDigest: $binding->proofDigest,
        );

        if ($binding->billingAccountRef !== $request->billingAccountRef) {
            return $deny('billing_account_mismatch');
        }
        if ($binding->merchantAccountRef !== $request->merchantAccountRef
            || $binding->merchantConfigRevision !== $request->merchantConfigRevision) {
            return $deny('merchant_binding_mismatch');
        }
        if ($guard->status === ConsentGuardStatus::Revoked) {
            return $deny('consent_revoked');
        }
        if ($guard->status === ConsentGuardStatus::Cancelled) {
            return $deny('host_cancelled');
        }
        if ($guard->activeBindingId !== $binding->id || $guard->activeConsentVersion !== $binding->consentVersion) {
            return $deny('consent_version_superseded');
        }
        if ($request->expectedCancellationGeneration !== null
            && $guard->cancellationGeneration > $request->expectedCancellationGeneration) {
            return $deny('cancellation_generation_advanced');
        }
        if (! in_array($request->purpose, $binding->allowedPurposes, true)) {
            return $deny('purpose_not_allowed');
        }
        if (! in_array($request->currency, $binding->allowedCurrencies, true)) {
            return $deny('currency_not_allowed');
        }
        if ($request->amountMinor <= 0 || $request->periodCommittedMinor < 0) {
            return $deny('invalid_amount');
        }
        if ($request->asOf < $binding->validFrom) {
            return $deny('consent_not_yet_valid');
        }
        if ($binding->validUntil !== null && $request->asOf >= $binding->validUntil) {
            return $deny('consent_expired');
        }
        if ($binding->perChargeCapMinor !== null) {
            if ($binding->capCurrency !== $request->currency) {
                return $deny('cap_currency_mismatch');
            }
            if ($request->amountMinor > $binding->perChargeCapMinor) {
                return $deny('per_charge_cap_exceeded');
            }
        }
        if ($binding->perPeriodCapMinor !== null) {
            if ($binding->capCurrency !== $request->currency) {
                return $deny('cap_currency_mismatch');
            }
            if ($request->amountMinor > $binding->perPeriodCapMinor - $request->periodCommittedMinor) {
                return $deny('per_period_cap_exceeded');
            }
        }
        if ($authorizationTombstoned) {
            return $deny('authorization_tombstoned');
        }
        if ($businessIntentTombstoned) {
            return $deny('business_intent_tombstoned');
        }

        return new ConsentEligibilityVerdict(
            permitted: true,
            denialCode: null,
            bindingId: $binding->id,
            consentGuardId: $guard->id,
            guardRevision: $guard->guardRevision,
            sourceRevision: $guard->sourceRevision,
            cancellationGeneration: $guard->cancellationGeneration,
            guardStatus: $guard->status,
            proofDigest: $binding->proofDigest,
        );
    }

    /**
     * @throws ProblemException
     */
    public function assertCustomerActionBinding(ConsentBindingView $binding, RecordCustomerAction $command): void
    {
        if ($binding->billingAccountRef !== $command->billingAccountRef) {
            throw new ProblemException(ProblemCode::AuthDenied, 'billium.collection.consent_account_mismatch');
        }
    }

    /**
     * @throws ProblemException
     */
    public function assertRefundRouteBinding(ConsentBindingView $binding, RecordOriginalRefundRoute $command): void
    {
        if ($binding->billingAccountRef !== $command->billingAccountRef) {
            throw new ProblemException(ProblemCode::AuthDenied, 'billium.collection.consent_account_mismatch');
        }
    }

    /**
     * @throws ProblemException
     */
    public function assertRefundRouteMatchesOriginal(
        OriginalRefundRouteView $route,
        string $merchantAccountRef,
        string $merchantConfigRevision,
    ): void {
        if (
            $route->originalMerchantAccountRef !== $merchantAccountRef
            || $route->originalMerchantConfigRevision !== $merchantConfigRevision
        ) {
            throw new ProblemException(
                ProblemCode::AuthDenied,
                'billium.collection.refund_must_use_original_binding',
            );
        }
    }
}
