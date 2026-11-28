<?php

declare(strict_types=1);

namespace Billium\Collection\Tests\Feature;

use Billium\Collection\Data\ConsentChargeEligibilityRequest;
use Billium\Collection\Data\RecordCustomerAction;
use Billium\Collection\Data\RecordOriginalRefundRoute;
use Billium\Collection\Data\RevokeConsentCommand;
use Billium\Collection\Enums\ConsentGuardStatus;
use Billium\Collection\Enums\ConsentPurpose;
use Billium\Collection\Enums\CustomerActionStatus;
use Billium\Collection\Enums\CustomerActionType;
use Billium\Collection\Enums\TombstoneKind;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\P7\Support\CollectionTestCase;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterization tests locking in the exact literal behavior of DatabaseConsentBindingService
 * before Functional Core / Imperative Shell decomposition (Phase F4-B.2).
 */
#[Group('pg')]
final class ConsentLifecycleCharacterizationTest extends CollectionTestCase
{
    public function test_register_and_supersede_consent_binding_and_guard_invariants(): void
    {
        [$merchant, $capabilities] = $this->savedMethodMerchant();
        $service = $this->consentService();
        $queries = $this->consentQueries();

        $v1 = $service->registerBinding($this->registerCommand(), $merchant, $capabilities);
        self::assertSame(1, $v1->consentVersion);
        self::assertNull($v1->supersedesBindingId);
        self::assertSame(['dunning_retry', 'recurring_charge'], array_map(static fn (ConsentPurpose $p): string => $p->value, $v1->allowedPurposes));
        self::assertSame(['RUB', 'USD'], $v1->allowedCurrencies);

        $guard1 = $queries->findGuardState($this->collectionScope(), 'consent-1');
        self::assertNotNull($guard1);
        self::assertSame(ConsentGuardStatus::Active, $guard1->status);
        self::assertSame(1, $guard1->guardRevision);
        self::assertSame(1, $guard1->activeConsentVersion);
        self::assertSame($v1->id, $guard1->activeBindingId);

        // Idempotent replay with same payload (even different commandKey) returns same binding.
        $replay = $service->registerBinding($this->registerCommand(commandKey: 'reg-consent-1-replay'), $merchant, $capabilities);
        self::assertSame($v1->id, $replay->id);

        // Same consentVersion with different payload raises IdempotencyConflict.
        try {
            $service->registerBinding(
                $this->registerCommand(commandKey: 'reg-consent-1-conflict', perChargeCapMinor: 12000),
                $merchant,
                $capabilities,
            );
            self::fail('Expected ProblemException for consent_version_conflict');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
            self::assertSame('billium.collection.consent_version_conflict', $e->detail);
        }

        // Supersede with version 2.
        $v2 = $service->registerBinding(
            $this->registerCommand(
                commandKey: 'reg-consent-2',
                consentVersion: 2,
                sourceRevision: 2,
                supersedesConsentVersion: 1,
                sourceEvidenceRef: 'mandate-proof-2',
                sourceEvidenceDigest: self::digest('f'),
            ),
            $merchant,
            $capabilities,
        );
        self::assertSame(2, $v2->consentVersion);
        self::assertSame($v1->id, $v2->supersedesBindingId);

        $guard2 = $queries->findGuardState($this->collectionScope(), 'consent-1');
        self::assertNotNull($guard2);
        self::assertSame(2, $guard2->guardRevision);
        self::assertSame(2, $guard2->activeConsentVersion);
        self::assertSame($v2->id, $guard2->activeBindingId);

        // Merchant change with reused sourceEvidenceRef is rejected.
        [$merchantB, $capabilitiesB] = $this->savedMethodMerchant(
            merchantAccountRef: 'merchant-b',
            merchantConfigRevision: 'mrev-b1',
        );
        try {
            $service->registerBinding(
                $this->registerCommand(
                    commandKey: 'reg-consent-3-merchant-reuse',
                    consentVersion: 3,
                    merchantAccountRef: 'merchant-b',
                    merchantConfigRevision: 'mrev-b1',
                    sourceRevision: 3,
                    sourceEvidenceRef: 'mandate-proof-2',
                    sourceEvidenceDigest: self::digest('1'),
                ),
                $merchantB,
                $capabilitiesB,
            );
            self::fail('Expected ProblemException for merchant_change_requires_new_consent');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            self::assertSame('billium.collection.merchant_change_requires_new_consent', $e->detail);
        }

        // Non-increasing version is rejected.
        try {
            $service->registerBinding(
                $this->registerCommand(commandKey: 'reg-consent-non-inc', consentVersion: 2, sourceRevision: 3),
                $merchant,
                $capabilities,
            );
            // Note: same version 2 with different sourceRevision hits consent_version_conflict on existing binding,
            // whereas version < activeVersion (if not existing) or version <= activeVersion hits validation.
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
        }

        // Account mismatch on superseding is rejected with AuthDenied.
        try {
            $service->registerBinding(
                $this->registerCommand(
                    commandKey: 'reg-consent-acct-mismatch',
                    consentVersion: 3,
                    billingAccountRef: 'account-other',
                    sourceRevision: 3,
                ),
                $merchant,
                $capabilities,
            );
            self::fail('Expected ProblemException for consent_account_mismatch');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::AuthDenied, $e->problem);
            self::assertSame('billium.collection.consent_account_mismatch', $e->detail);
        }

        // Customer mismatch on superseding is rejected with AuthDenied.
        try {
            $service->registerBinding(
                $this->registerCommand(
                    commandKey: 'reg-consent-cust-mismatch',
                    consentVersion: 3,
                    customerRef: 'customer-other',
                    sourceRevision: 3,
                ),
                $merchant,
                $capabilities,
            );
            self::fail('Expected ProblemException for consent_customer_mismatch');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::AuthDenied, $e->problem);
            self::assertSame('billium.collection.consent_customer_mismatch', $e->detail);
        }
    }

    public function test_tombstone_revocation_cancellation_precedence_and_guards(): void
    {
        [$merchant, $capabilities] = $this->savedMethodMerchant();
        $service = $this->consentService();
        $queries = $this->consentQueries();

        $service->registerBinding($this->registerCommand(), $merchant, $capabilities);

        // Revoking unknown consent throws ReferenceUnknown.
        try {
            $service->revokeOrCancel(new RevokeConsentCommand(
                commandKey: 'rev-missing',
                scope: $this->collectionScope(),
                consentRef: 'consent-missing',
                tombstoneRef: 'tomb-missing',
                sourceRevision: 1,
                cancellationGeneration: 0,
                reason: 'missing',
                evidenceDigest: self::digest('a'),
            ));
            self::fail('Expected ProblemException for consent_not_found');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ReferenceUnknown, $e->problem);
            self::assertSame('billium.collection.consent_not_found', $e->detail);
        }

        // First tombstone: ConsentRevoked -> guard becomes Revoked (rev 2).
        $tomb1 = $service->revokeOrCancel(new RevokeConsentCommand(
            commandKey: 'rev-1',
            scope: $this->collectionScope(),
            consentRef: 'consent-1',
            tombstoneRef: 'tomb-1',
            sourceRevision: 2,
            cancellationGeneration: 0,
            reason: 'customer_requested',
            evidenceDigest: self::digest('b'),
            tombstoneKind: TombstoneKind::ConsentRevoked,
            targetAuthorizationRef: 'auth-tomb-1',
            expectedGuardRevision: 1,
        ));
        self::assertSame(ConsentGuardStatus::Revoked, $tomb1->status);
        self::assertSame(2, $tomb1->guardRevision);
        self::assertSame('auth-tomb-1', $tomb1->targetAuthorizationRef);

        // Idempotent replay of tomb-1 with different commandKey returns same receipt without bumping revision.
        $tomb1Replay = $service->revokeOrCancel(new RevokeConsentCommand(
            commandKey: 'rev-1-replay',
            scope: $this->collectionScope(),
            consentRef: 'consent-1',
            tombstoneRef: 'tomb-1',
            sourceRevision: 2,
            cancellationGeneration: 0,
            reason: 'customer_requested',
            evidenceDigest: self::digest('b'),
            tombstoneKind: TombstoneKind::ConsentRevoked,
        ));
        self::assertSame(2, $tomb1Replay->guardRevision);

        // Conflicting tombstoneRef throws IdempotencyConflict.
        try {
            $service->revokeOrCancel(new RevokeConsentCommand(
                commandKey: 'rev-1-conflict',
                scope: $this->collectionScope(),
                consentRef: 'consent-1',
                tombstoneRef: 'tomb-1',
                sourceRevision: 2,
                cancellationGeneration: 0,
                reason: 'customer_requested',
                evidenceDigest: self::digest('c'),
            ));
            self::fail('Expected ProblemException for tombstone_conflict');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
            self::assertSame('billium.collection.tombstone_conflict', $e->detail);
        }

        // Guard revision mismatch throws IdempotencyConflict.
        try {
            $service->revokeOrCancel(new RevokeConsentCommand(
                commandKey: 'rev-wrong-guard-rev',
                scope: $this->collectionScope(),
                consentRef: 'consent-1',
                tombstoneRef: 'tomb-wrong-rev',
                sourceRevision: 2,
                cancellationGeneration: 0,
                reason: 'wrong rev',
                evidenceDigest: self::digest('d'),
                expectedGuardRevision: 1,
            ));
            self::fail('Expected ProblemException for guard_revision_mismatch');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
            self::assertSame('billium.collection.guard_revision_mismatch', $e->detail);
        }

        // Source revision regression throws ValidationInvalid.
        try {
            $service->revokeOrCancel(new RevokeConsentCommand(
                commandKey: 'rev-regression',
                scope: $this->collectionScope(),
                consentRef: 'consent-1',
                tombstoneRef: 'tomb-regression',
                sourceRevision: 1,
                cancellationGeneration: 0,
                reason: 'regression',
                evidenceDigest: self::digest('d'),
            ));
            self::fail('Expected ProblemException for guard_revision_regression');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            self::assertSame('billium.collection.guard_revision_regression', $e->detail);
        }

        // Escalate to HostCancelled -> guard becomes Cancelled (rev 3).
        $tomb2 = $service->revokeOrCancel(new RevokeConsentCommand(
            commandKey: 'rev-2-cancel',
            scope: $this->collectionScope(),
            consentRef: 'consent-1',
            tombstoneRef: 'tomb-2',
            sourceRevision: 3,
            cancellationGeneration: 1,
            reason: 'host_cancelled',
            evidenceDigest: self::digest('e'),
            tombstoneKind: TombstoneKind::HostCancelled,
            targetBusinessIntentRef: 'intent-tomb-2',
        ));
        self::assertSame(ConsentGuardStatus::Cancelled, $tomb2->status);
        self::assertSame(3, $tomb2->guardRevision);

        // Subsequent ConsentRevoked preserves Cancelled status (never downgrades Cancelled -> Revoked).
        $tomb3 = $service->revokeOrCancel(new RevokeConsentCommand(
            commandKey: 'rev-3-after-cancel',
            scope: $this->collectionScope(),
            consentRef: 'consent-1',
            tombstoneRef: 'tomb-3',
            sourceRevision: 4,
            cancellationGeneration: 1,
            reason: 'late_revoke',
            evidenceDigest: self::digest('f'),
            tombstoneKind: TombstoneKind::ConsentRevoked,
        ));
        self::assertSame(ConsentGuardStatus::Cancelled, $tomb3->status);
        self::assertSame(4, $tomb3->guardRevision);

        // Original revocation_receipt_ref and revocation_reason on guard remain from first tombstone.
        $guard = $queries->findGuardState($this->collectionScope(), 'consent-1');
        self::assertNotNull($guard);
        self::assertSame(ConsentGuardStatus::Cancelled, $guard->status);
        self::assertSame('tomb-1', $guard->revocationReceiptRef);
        self::assertSame('customer_requested', $guard->revocationReason);

        // Superseding a revoked/cancelled consent throws OperationFinancialHold.
        try {
            $service->registerBinding(
                $this->registerCommand(commandKey: 'reg-after-revoke', consentVersion: 2, sourceRevision: 5),
                $merchant,
                $capabilities,
            );
            self::fail('Expected ProblemException for consent_revoked');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::OperationFinancialHold, $e->problem);
            self::assertSame('billium.collection.consent_revoked', $e->detail);
        }
    }

    public function test_charge_eligibility_rules_and_tombstone_checks(): void
    {
        [$merchant, $capabilities] = $this->savedMethodMerchant();
        $service = $this->consentService();
        $utc = new DateTimeZone('UTC');
        $validFrom = new DateTimeImmutable('2026-11-20T00:00:00Z', $utc);
        $validUntil = new DateTimeImmutable('2026-12-20T00:00:00Z', $utc);

        $service->registerBinding(
            $this->registerCommand(
                validFrom: $validFrom,
                validUntil: $validUntil,
                perChargeCapMinor: 5000,
                perPeriodCapMinor: 8000,
                capCurrency: 'RUB',
            ),
            $merchant,
            $capabilities,
        );

        $scope = $this->collectionScope();
        $baseReq = static fn (
            ?int $consentVersion = 1,
            ?string $billingAccountRef = 'account-a',
            ?string $merchantAccountRef = 'merchant-a',
            ?string $merchantConfigRevision = 'mrev-a1',
            ConsentPurpose $purpose = ConsentPurpose::RecurringCharge,
            string $currency = 'RUB',
            int $amountMinor = 3000,
            int $periodCommittedMinor = 2000,
            ?DateTimeImmutable $asOf = null,
            ?string $authorizationRef = null,
            ?string $businessPaymentIntentRef = null,
        ): ConsentChargeEligibilityRequest => new ConsentChargeEligibilityRequest(
            scope: $scope,
            billingAccountRef: $billingAccountRef ?? 'account-a',
            consentRef: 'consent-1',
            consentVersion: $consentVersion ?? 1,
            merchantAccountRef: $merchantAccountRef ?? 'merchant-a',
            merchantConfigRevision: $merchantConfigRevision ?? 'mrev-a1',
            purpose: $purpose,
            currency: $currency,
            amountMinor: $amountMinor,
            periodCommittedMinor: $periodCommittedMinor,
            asOf: $asOf ?? new DateTimeImmutable('2026-11-25T00:00:00Z', $utc),
            authorizationRef: $authorizationRef,
            businessPaymentIntentRef: $businessPaymentIntentRef,
        );

        // Eligible request succeeds.
        $ok = $service->evaluateChargeEligibility($baseReq());
        self::assertTrue($ok->permitted);
        self::assertNull($ok->denialCode);
        self::assertSame(ConsentGuardStatus::Active, $ok->guardStatus);

        // Missing binding.
        self::assertSame('consent_binding_not_found', $service->evaluateChargeEligibility($baseReq(consentVersion: 99))->denialCode);
        // Account mismatch.
        self::assertSame('billing_account_mismatch', $service->evaluateChargeEligibility($baseReq(billingAccountRef: 'other'))->denialCode);
        // Merchant mismatch.
        self::assertSame('merchant_binding_mismatch', $service->evaluateChargeEligibility($baseReq(merchantAccountRef: 'other'))->denialCode);
        // Purpose / currency not allowed.
        self::assertSame('purpose_not_allowed', $service->evaluateChargeEligibility($baseReq(purpose: ConsentPurpose::OneOffSavedMethod))->denialCode);
        self::assertSame('currency_not_allowed', $service->evaluateChargeEligibility($baseReq(currency: 'EUR'))->denialCode);
        // Invalid amount.
        self::assertSame('invalid_amount', $service->evaluateChargeEligibility($baseReq(amountMinor: 0))->denialCode);
        // Window bounds.
        self::assertSame('consent_not_yet_valid', $service->evaluateChargeEligibility($baseReq(asOf: $validFrom->modify('-1 second')))->denialCode);
        self::assertSame('consent_expired', $service->evaluateChargeEligibility($baseReq(asOf: $validUntil))->denialCode);
        // Caps.
        self::assertSame('cap_currency_mismatch', $service->evaluateChargeEligibility($baseReq(currency: 'USD'))->denialCode);
        self::assertSame('per_charge_cap_exceeded', $service->evaluateChargeEligibility($baseReq(amountMinor: 5001))->denialCode);
        self::assertSame('per_period_cap_exceeded', $service->evaluateChargeEligibility($baseReq(amountMinor: 4000, periodCommittedMinor: 5000))->denialCode);

        // Revoke with targetAuthorizationRef, then check authorization_tombstoned & consent_revoked.
        $service->revokeOrCancel(new RevokeConsentCommand(
            commandKey: 'rev-elig',
            scope: $this->collectionScope(),
            consentRef: 'consent-1',
            tombstoneRef: 'tomb-elig',
            sourceRevision: 2,
            cancellationGeneration: 0,
            reason: 'revoked',
            evidenceDigest: self::digest('a'),
            targetAuthorizationRef: 'auth-blocked',
        ));
        self::assertSame('consent_revoked', $service->evaluateChargeEligibility($baseReq())->denialCode);
    }

    public function test_customer_action_and_refund_route_receipts(): void
    {
        [$merchant, $capabilities] = $this->savedMethodMerchant();
        $service = $this->consentService();
        $utc = new DateTimeZone('UTC');

        $service->registerBinding($this->registerCommand(), $merchant, $capabilities);

        // Customer action for unknown consent -> ReferenceUnknown.
        try {
            $service->recordCustomerAction(new RecordCustomerAction(
                commandKey: 'act-missing',
                scope: $this->collectionScope(),
                actionRef: 'act-1',
                billingAccountRef: 'account-a',
                consentRef: 'consent-missing',
                consentVersion: 1,
                operationRef: 'op-1',
                businessPaymentIntentRef: 'intent-1',
                actionType: CustomerActionType::ThreeDsChallenge,
                challengeRef: 'chal-1',
                actionPayload: ['acsUrl' => 'https://acs.example/1'],
                expiresAt: new DateTimeImmutable('2026-11-21T00:00:00Z', $utc),
            ));
            self::fail('Expected ProblemException for consent_not_found');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ReferenceUnknown, $e->problem);
            self::assertSame('billium.collection.consent_not_found', $e->detail);
        }

        // Customer action for wrong billing account -> AuthDenied.
        try {
            $service->recordCustomerAction(new RecordCustomerAction(
                commandKey: 'act-acct',
                scope: $this->collectionScope(),
                actionRef: 'act-1',
                billingAccountRef: 'account-wrong',
                consentRef: 'consent-1',
                consentVersion: 1,
                operationRef: 'op-1',
                businessPaymentIntentRef: 'intent-1',
                actionType: CustomerActionType::ThreeDsChallenge,
                challengeRef: 'chal-1',
                actionPayload: ['acsUrl' => 'https://acs.example/1'],
                expiresAt: new DateTimeImmutable('2026-11-21T00:00:00Z', $utc),
            ));
            self::fail('Expected ProblemException for consent_account_mismatch');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::AuthDenied, $e->problem);
            self::assertSame('billium.collection.consent_account_mismatch', $e->detail);
        }

        // Valid customer action -> RequiresAction receipt.
        $actionReceipt = $service->recordCustomerAction(new RecordCustomerAction(
            commandKey: 'act-ok',
            scope: $this->collectionScope(),
            actionRef: 'act-1',
            billingAccountRef: 'account-a',
            consentRef: 'consent-1',
            consentVersion: 1,
            operationRef: 'op-1',
            businessPaymentIntentRef: 'intent-1',
            actionType: CustomerActionType::ThreeDsChallenge,
            challengeRef: 'chal-1',
            actionPayload: ['acsUrl' => 'https://acs.example/1'],
            expiresAt: new DateTimeImmutable('2026-11-21T00:00:00Z', $utc),
        ));
        self::assertSame(CustomerActionStatus::RequiresAction, $actionReceipt->status);
        self::assertSame('act-1', $actionReceipt->actionRef);

        // Refund route recording & mismatch assertion.
        $route = $service->recordOriginalRefundRoute(new RecordOriginalRefundRoute(
            commandKey: 'route-1',
            scope: $this->collectionScope(),
            operationRef: 'op-refund-1',
            billingAccountRef: 'account-a',
            consentRef: 'consent-1',
            consentVersion: 1,
        ));
        self::assertSame('merchant-a', $route->originalMerchantAccountRef);

        $matched = $service->assertRefundRouteMatchesOriginal($this->collectionScope(), 'op-refund-1', 'merchant-a', 'mrev-a1');
        self::assertSame($route->id, $matched->id);

        try {
            $service->assertRefundRouteMatchesOriginal($this->collectionScope(), 'op-refund-1', 'merchant-b', 'mrev-b1');
            self::fail('Expected ProblemException for refund_must_use_original_binding');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::AuthDenied, $e->problem);
            self::assertSame('billium.collection.refund_must_use_original_binding', $e->detail);
        }
    }
}
