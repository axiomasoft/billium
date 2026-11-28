<?php

declare(strict_types=1);

namespace Billium\Collection\Tests\Unit;

use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\ConsentBindingView;
use Billium\Collection\Data\ConsentChargeEligibilityRequest;
use Billium\Collection\Data\ConsentGuardStateView;
use Billium\Collection\Data\HostConsentAuthoritySnapshot;
use Billium\Collection\Data\OriginalRefundRouteView;
use Billium\Collection\Data\RecordCustomerAction;
use Billium\Collection\Data\RecordOriginalRefundRoute;
use Billium\Collection\Data\RegisterConsentBinding;
use Billium\Collection\Data\RevokeConsentCommand;
use Billium\Collection\Enums\ConsentGuardStatus;
use Billium\Collection\Enums\ConsentPurpose;
use Billium\Collection\Enums\CustomerActionType;
use Billium\Collection\Enums\TombstoneKind;
use Billium\Collection\Internal\Reducers\ConsentLifecycleReducer;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\MerchantRevisionPolicy;
use Billium\Execution\Data\SecretRef;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ConsentLifecycleReducerTest extends TestCase
{
    public function test_validate_and_normalize_registration_validates_bounds_and_normalizes(): void
    {
        $reducer = new ConsentLifecycleReducer;
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));

        $normalized = $reducer->validateAndNormalizeRegistration($this->makeRegisterCommand(
            $t0,
            purposes: [ConsentPurpose::DunningRetry, ConsentPurpose::RecurringCharge, ConsentPurpose::DunningRetry],
            currencies: ['USD', 'RUB', 'USD'],
            perChargeCapMinor: 1000,
            perPeriodCapMinor: 1000,
            capCurrency: 'RUB',
            validUntil: $t0->modify('+1 second'),
        ));

        self::assertSame(['dunning_retry', 'recurring_charge'], $normalized['purposeValues']);
        self::assertSame(['RUB', 'USD'], $normalized['currencies']);
        self::assertSame('RUB', $normalized['capCurrency']);

        // No caps with non-null capCurrency still normalizes capCurrency.
        $noCaps = $reducer->validateAndNormalizeRegistration($this->makeRegisterCommand(
            $t0,
            perChargeCapMinor: null,
            perPeriodCapMinor: null,
            capCurrency: 'USD',
        ));
        self::assertSame('USD', $noCaps['capCurrency']);

        // No caps with null capCurrency.
        $noCapsNull = $reducer->validateAndNormalizeRegistration($this->makeRegisterCommand(
            $t0,
            perChargeCapMinor: null,
            perPeriodCapMinor: null,
            capCurrency: null,
        ));
        self::assertNull($noCapsNull['capCurrency']);
    }

    public function test_validate_and_normalize_registration_rejects_invalid_inputs(): void
    {
        $reducer = new ConsentLifecycleReducer;
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));

        $cases = [
            [$this->makeRegisterCommand($t0, consentVersion: 0), ProblemCode::ValidationInvalid, 'consentVersion must be >= 1'],
            [$this->makeRegisterCommand($t0, sourceRevision: -1), ProblemCode::ValidationInvalid, 'sourceRevision and cancellationGeneration must be nonnegative'],
            [$this->makeRegisterCommand($t0, cancellationGeneration: -1), ProblemCode::ValidationInvalid, 'sourceRevision and cancellationGeneration must be nonnegative'],
            [$this->makeRegisterCommand($t0, validUntil: $t0), ProblemCode::ValidationInvalid, 'validUntil must be strictly after validFrom'],
            [$this->makeRegisterCommand($t0, validUntil: $t0->modify('-1 second')), ProblemCode::ValidationInvalid, 'validUntil must be strictly after validFrom'],
            [$this->makeRegisterCommand($t0, purposes: []), ProblemCode::ValidationInvalid, 'allowedPurposes must not be empty'],
            [$this->makeRegisterCommand($t0, currencies: []), ProblemCode::ValidationInvalid, 'allowedCurrencies must not be empty'],
            [$this->makeRegisterCommand($t0, perChargeCapMinor: 0), ProblemCode::ValidationInvalid, 'perChargeCapMinor must be positive'],
            [$this->makeRegisterCommand($t0, perPeriodCapMinor: 0), ProblemCode::ValidationInvalid, 'perPeriodCapMinor must be positive'],
            [$this->makeRegisterCommand($t0, perChargeCapMinor: 101, perPeriodCapMinor: 100), ProblemCode::ValidationInvalid, 'perChargeCapMinor cannot exceed perPeriodCapMinor'],
            [$this->makeRegisterCommand($t0, perChargeCapMinor: 100, perPeriodCapMinor: null, capCurrency: null), ProblemCode::ValidationInvalid, 'capCurrency is required when caps are configured'],
            [$this->makeRegisterCommand($t0, perChargeCapMinor: null, perPeriodCapMinor: 100, capCurrency: null), ProblemCode::ValidationInvalid, 'capCurrency is required when caps are configured'],
            [$this->makeRegisterCommand($t0, perChargeCapMinor: 100, perPeriodCapMinor: 200, capCurrency: 'EUR'), ProblemCode::MoneyCurrencyMismatch, 'capCurrency must be listed in allowedCurrencies'],
        ];

        foreach ($cases as [$command, $expectedCode, $expectedDetail]) {
            try {
                $reducer->validateAndNormalizeRegistration($command);
                self::fail("Expected ProblemException: {$expectedDetail}");
            } catch (ProblemException $e) {
                self::assertSame($expectedCode, $e->problem);
                self::assertSame($expectedDetail, $e->detail);
            }
        }
    }

    public function test_plan_superseding_handles_initial_superseding_and_invariants(): void
    {
        $reducer = new ConsentLifecycleReducer;
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $merchant = $this->makeMerchant($t0);

        // Initial registration without guard.
        self::assertNull($reducer->planSuperseding($this->makeRegisterCommand($t0), $merchant, null, null));

        // Initial registration with supersedesConsentVersion set -> ValidationInvalid.
        try {
            $reducer->planSuperseding($this->makeRegisterCommand($t0, supersedesConsentVersion: 1), $merchant, null, null);
            self::fail('Expected ProblemException');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            self::assertSame('supersedesConsentVersion requires an existing consent binding', $e->detail);
        }

        // Valid superseding returns activeBindingId.
        $guard = $this->makeGuard($t0, activeBindingId: 42, activeConsentVersion: 1, sourceRevision: 2, cancellationGeneration: 1);
        $activeBinding = $this->makeBinding($t0, id: 42, consentVersion: 1);
        $supersedesId = $reducer->planSuperseding(
            $this->makeRegisterCommand(
                $t0,
                consentVersion: 2,
                sourceRevision: 2,
                cancellationGeneration: 1,
                supersedesConsentVersion: 1,
            ),
            $merchant,
            $guard,
            $activeBinding,
        );
        self::assertSame(42, $supersedesId);

        // Missing active binding with non-null guard throws LogicException.
        try {
            $reducer->planSuperseding($this->makeRegisterCommand($t0, consentVersion: 2), $merchant, $guard, null);
            self::fail('Expected LogicException');
        } catch (LogicException $e) {
            self::assertSame('Active consent binding vanished', $e->getMessage());
        }

        // Merchant change with new evidence ref AND new evidence digest succeeds.
        $merchantB = $this->makeMerchant($t0, merchantAccountRef: 'merchant-b', revisionRef: 'mrev-b1');
        self::assertSame(42, $reducer->planSuperseding(
            $this->makeRegisterCommand(
                $t0,
                consentVersion: 2,
                merchantAccountRef: 'merchant-b',
                merchantConfigRevision: 'mrev-b1',
                sourceRevision: 2,
                cancellationGeneration: 1,
                sourceEvidenceRef: 'mandate-proof-new',
                sourceEvidenceDigest: 'sha256:'.str_repeat('f', 64),
            ),
            $merchantB,
            $guard,
            $activeBinding,
        ));
    }

    public function test_plan_superseding_rejects_violations(): void
    {
        $reducer = new ConsentLifecycleReducer;
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $merchant = $this->makeMerchant($t0);
        $merchantB = $this->makeMerchant($t0, merchantAccountRef: 'merchant-b', revisionRef: 'mrev-b1');
        $merchantRevB = $this->makeMerchant($t0, merchantAccountRef: 'merchant-a', revisionRef: 'mrev-a2');
        $guard = $this->makeGuard($t0, activeBindingId: 42, activeConsentVersion: 2, sourceRevision: 5, cancellationGeneration: 3);
        $binding = $this->makeBinding($t0, id: 42, consentVersion: 2);

        $cases = [
            [
                $this->makeRegisterCommand($t0, consentVersion: 3, sourceRevision: 5, cancellationGeneration: 3),
                $merchant,
                $this->makeGuard($t0, status: ConsentGuardStatus::Revoked),
                $binding,
                ProblemCode::OperationFinancialHold,
                'billium.collection.consent_revoked',
            ],
            [
                $this->makeRegisterCommand($t0, consentVersion: 3, billingAccountRef: 'other', sourceRevision: 5, cancellationGeneration: 3),
                $merchant,
                $guard,
                $binding,
                ProblemCode::AuthDenied,
                'billium.collection.consent_account_mismatch',
            ],
            [
                $this->makeRegisterCommand($t0, consentVersion: 2, sourceRevision: 5, cancellationGeneration: 3),
                $merchant,
                $guard,
                $binding,
                ProblemCode::ValidationInvalid,
                'consentVersion must strictly increase for an existing consentRef',
            ],
            [
                $this->makeRegisterCommand($t0, consentVersion: 3, customerRef: 'other', sourceRevision: 5, cancellationGeneration: 3),
                $merchant,
                $guard,
                $binding,
                ProblemCode::AuthDenied,
                'billium.collection.consent_customer_mismatch',
            ],
            [
                $this->makeRegisterCommand($t0, consentVersion: 3, sourceRevision: 5, cancellationGeneration: 3, sourceEvidenceRef: 'mandate-proof-1', sourceEvidenceDigest: 'sha256:'.str_repeat('f', 64)),
                $merchantB,
                $guard,
                $binding,
                ProblemCode::ValidationInvalid,
                'billium.collection.merchant_change_requires_new_consent',
            ],
            [
                $this->makeRegisterCommand($t0, consentVersion: 3, sourceRevision: 5, cancellationGeneration: 3, sourceEvidenceRef: 'mandate-proof-new', sourceEvidenceDigest: 'sha256:'.str_repeat('e', 64)),
                $merchantRevB,
                $guard,
                $binding,
                ProblemCode::ValidationInvalid,
                'billium.collection.merchant_change_requires_new_consent',
            ],
            [
                $this->makeRegisterCommand($t0, consentVersion: 3, sourceRevision: 5, cancellationGeneration: 3, supersedesConsentVersion: 1),
                $merchant,
                $guard,
                $binding,
                ProblemCode::ValidationInvalid,
                'supersedesConsentVersion does not match current active version',
            ],
            [
                $this->makeRegisterCommand($t0, consentVersion: 3, sourceRevision: 4, cancellationGeneration: 3),
                $merchant,
                $guard,
                $binding,
                ProblemCode::ValidationInvalid,
                'sourceRevision and cancellationGeneration cannot regress when superseding binding',
            ],
            [
                $this->makeRegisterCommand($t0, consentVersion: 3, sourceRevision: 5, cancellationGeneration: 2),
                $merchant,
                $guard,
                $binding,
                ProblemCode::ValidationInvalid,
                'sourceRevision and cancellationGeneration cannot regress when superseding binding',
            ],
        ];

        foreach ($cases as [$cmd, $m, $g, $b, $code, $detail]) {
            try {
                $reducer->planSuperseding($cmd, $m, $g, $b);
                self::fail("Expected ProblemException: {$detail}");
            } catch (ProblemException $e) {
                self::assertSame($code, $e->problem);
                self::assertSame($detail, $e->detail);
            }
        }
    }

    public function test_reduce_revocation_transitions_and_guards(): void
    {
        $reducer = new ConsentLifecycleReducer;
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $t1 = $t0->modify('+60 seconds');

        // Active -> Revoked sets initial timestamps/refs.
        $activeGuard = $this->makeGuard($t0, guardRevision: 1, sourceRevision: 2, cancellationGeneration: 0, status: ConsentGuardStatus::Active);
        $dec1 = $reducer->reduceRevocation(
            $this->makeRevokeCommand('tomb-1', 2, 0, TombstoneKind::ConsentRevoked, expectedGuardRevision: 1, reason: 'r1'),
            $activeGuard,
            $t1,
        );
        self::assertSame(2, $dec1->newGuardRevision);
        self::assertSame(ConsentGuardStatus::Revoked, $dec1->targetStatus);
        self::assertEquals($t1, $dec1->effectiveAt);
        self::assertEquals($t1, $dec1->revokedAt);
        self::assertSame('tomb-1', $dec1->revocationReceiptRef);
        self::assertSame('r1', $dec1->revocationReason);

        // Cancelled + ConsentRevoked preserves Cancelled and existing receipt metadata.
        $cancelledGuard = $this->makeGuard(
            $t0,
            guardRevision: 3,
            sourceRevision: 3,
            cancellationGeneration: 1,
            status: ConsentGuardStatus::Cancelled,
            revokedAt: $t0,
            revocationReceiptRef: 'orig-tomb',
            revocationReason: 'orig-reason',
            effectiveAt: $t0,
        );
        $dec2 = $reducer->reduceRevocation(
            $this->makeRevokeCommand('tomb-2', 3, 1, TombstoneKind::ConsentRevoked, reason: 'r2'),
            $cancelledGuard,
            $t1,
        );
        self::assertSame(4, $dec2->newGuardRevision);
        self::assertSame(ConsentGuardStatus::Cancelled, $dec2->targetStatus);
        self::assertEquals($t0, $dec2->effectiveAt);
        self::assertEquals($t0, $dec2->revokedAt);
        self::assertSame('orig-tomb', $dec2->revocationReceiptRef);
        self::assertSame('orig-reason', $dec2->revocationReason);

        // HostCancelled escalates Active -> Cancelled.
        $dec3 = $reducer->reduceRevocation(
            $this->makeRevokeCommand('tomb-3', 2, 0, TombstoneKind::HostCancelled),
            $activeGuard,
            $t1,
        );
        self::assertSame(ConsentGuardStatus::Cancelled, $dec3->targetStatus);

        // Guard revision mismatch & regression checks.
        foreach ([
            [$this->makeRevokeCommand('tomb-x', 2, 0, expectedGuardRevision: 99), ProblemCode::IdempotencyConflict, 'billium.collection.guard_revision_mismatch'],
            [$this->makeRevokeCommand('tomb-x', 1, 0), ProblemCode::ValidationInvalid, 'billium.collection.guard_revision_regression'],
            [$this->makeRevokeCommand('tomb-x', 2, -1), ProblemCode::ValidationInvalid, 'billium.collection.guard_revision_regression'],
        ] as [$cmd, $code, $detail]) {
            try {
                $reducer->reduceRevocation($cmd, $cancelledGuard, $t1);
                self::fail("Expected ProblemException: {$detail}");
            } catch (ProblemException $e) {
                self::assertSame($code, $e->problem);
                self::assertSame($detail, $e->detail);
            }
        }
    }

    public function test_decide_host_authority_sync(): void
    {
        $reducer = new ConsentLifecycleReducer;
        $scope = new CollectionScope('seller-a', 'test');
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));

        // Active snapshot -> null.
        $activeSnap = new HostConsentAuthoritySnapshot('seller-a', 'test', 'account-a', 'consent-1', 1, 1, 0, true, null, 'sha256:'.str_repeat('a', 64), $t0);
        self::assertNull($reducer->decideHostAuthoritySync($activeSnap, $scope, 'account-a', 'consent-1', 'cmd-1', 'tomb-1'));

        // Inactive with cancellationGeneration == 0 -> ConsentRevoked + default reason.
        $revSnap = new HostConsentAuthoritySnapshot('seller-a', 'test', 'account-a', 'consent-1', 1, 2, 0, false, null, 'sha256:'.str_repeat('b', 64), $t0);
        $revCmd = $reducer->decideHostAuthoritySync($revSnap, $scope, 'account-a', 'consent-1', 'cmd-2', 'tomb-2');
        self::assertNotNull($revCmd);
        self::assertSame(TombstoneKind::ConsentRevoked, $revCmd->tombstoneKind);
        self::assertSame('host_consent_inactive', $revCmd->reason);

        // Inactive with cancellationGeneration > 0 -> HostCancelled + explicit reason.
        $cancelSnap = new HostConsentAuthoritySnapshot('seller-a', 'test', 'account-a', 'consent-1', 1, 3, 1, false, 'host_cancelled', 'sha256:'.str_repeat('c', 64), $t0);
        $cancelCmd = $reducer->decideHostAuthoritySync($cancelSnap, $scope, 'account-a', 'consent-1', 'cmd-3', 'tomb-3');
        self::assertNotNull($cancelCmd);
        self::assertSame(TombstoneKind::HostCancelled, $cancelCmd->tombstoneKind);
        self::assertSame('host_cancelled', $cancelCmd->reason);

        // Scope mismatch cases.
        foreach ([
            new HostConsentAuthoritySnapshot('other', 'test', 'account-a', 'consent-1', 1, 1, 0, true, null, 'sha256:'.str_repeat('a', 64), $t0),
            new HostConsentAuthoritySnapshot('seller-a', 'live', 'account-a', 'consent-1', 1, 1, 0, true, null, 'sha256:'.str_repeat('a', 64), $t0),
            new HostConsentAuthoritySnapshot('seller-a', 'test', 'other', 'consent-1', 1, 1, 0, true, null, 'sha256:'.str_repeat('a', 64), $t0),
            new HostConsentAuthoritySnapshot('seller-a', 'test', 'account-a', 'other', 1, 1, 0, true, null, 'sha256:'.str_repeat('a', 64), $t0),
        ] as $badSnap) {
            try {
                $reducer->decideHostAuthoritySync($badSnap, $scope, 'account-a', 'consent-1', 'cmd-x', 'tomb-x');
                self::fail('Expected ProblemException');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::RecoveryRequired, $e->problem);
                self::assertSame('billium.collection.host_authority_scope_mismatch', $e->detail);
            }
        }
    }

    public function test_evaluate_eligibility_all_branches_and_boundaries(): void
    {
        $reducer = new ConsentLifecycleReducer;
        $utc = new DateTimeZone('UTC');
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', $utc);
        $validUntil = new DateTimeImmutable('2026-12-20T00:00:00Z', $utc);

        $binding = $this->makeBinding($t0, id: 10, consentVersion: 1, validFrom: $t0, validUntil: $validUntil, perChargeCapMinor: 5000, perPeriodCapMinor: 8000, capCurrency: 'RUB');
        $guard = $this->makeGuard($t0, id: 20, activeBindingId: 10, activeConsentVersion: 1, cancellationGeneration: 2);

        $req = fn (
            string $billingAccountRef = 'account-a',
            string $merchantAccountRef = 'merchant-a',
            string $merchantConfigRevision = 'mrev-a1',
            ConsentPurpose $purpose = ConsentPurpose::RecurringCharge,
            string $currency = 'RUB',
            int $amountMinor = 5000,
            int $periodCommittedMinor = 3000,
            ?DateTimeImmutable $asOf = null,
            ?int $expectedCancellationGeneration = 2,
        ): ConsentChargeEligibilityRequest => new ConsentChargeEligibilityRequest(
            scope: new CollectionScope('seller-a', 'test'),
            billingAccountRef: $billingAccountRef,
            consentRef: 'consent-1',
            consentVersion: 1,
            merchantAccountRef: $merchantAccountRef,
            merchantConfigRevision: $merchantConfigRevision,
            purpose: $purpose,
            currency: $currency,
            amountMinor: $amountMinor,
            periodCommittedMinor: $periodCommittedMinor,
            asOf: $asOf ?? $t0,
            expectedCancellationGeneration: $expectedCancellationGeneration,
        );

        // Exact boundaries: asOf === validFrom, amountMinor === perChargeCapMinor, amountMinor === perPeriodCapMinor - periodCommittedMinor, cancellationGeneration === expected.
        $ok = $reducer->evaluateEligibility($req(), $binding, $guard, false, false);
        self::assertTrue($ok->permitted);
        self::assertNull($ok->denialCode);
        self::assertSame(10, $ok->bindingId);
        self::assertSame(20, $ok->consentGuardId);

        self::assertSame('consent_binding_not_found', $reducer->evaluateEligibility($req(), null, $guard, false, false)->denialCode);
        self::assertSame('consent_guard_not_found', $reducer->evaluateEligibility($req(), $binding, null, false, false)->denialCode);
        self::assertSame('billing_account_mismatch', $reducer->evaluateEligibility($req(billingAccountRef: 'other'), $binding, $guard, false, false)->denialCode);
        self::assertSame('merchant_binding_mismatch', $reducer->evaluateEligibility($req(merchantAccountRef: 'other'), $binding, $guard, false, false)->denialCode);
        self::assertSame('merchant_binding_mismatch', $reducer->evaluateEligibility($req(merchantConfigRevision: 'other'), $binding, $guard, false, false)->denialCode);
        self::assertSame('consent_revoked', $reducer->evaluateEligibility($req(), $binding, $this->makeGuard($t0, status: ConsentGuardStatus::Revoked), false, false)->denialCode);
        self::assertSame('host_cancelled', $reducer->evaluateEligibility($req(), $binding, $this->makeGuard($t0, status: ConsentGuardStatus::Cancelled), false, false)->denialCode);
        self::assertSame('consent_version_superseded', $reducer->evaluateEligibility($req(), $binding, $this->makeGuard($t0, activeBindingId: 99, activeConsentVersion: 1), false, false)->denialCode);
        self::assertSame('consent_version_superseded', $reducer->evaluateEligibility($req(), $binding, $this->makeGuard($t0, activeBindingId: 10, activeConsentVersion: 2), false, false)->denialCode);
        self::assertSame('cancellation_generation_advanced', $reducer->evaluateEligibility($req(expectedCancellationGeneration: 1), $binding, $guard, false, false)->denialCode);
        self::assertSame('purpose_not_allowed', $reducer->evaluateEligibility($req(purpose: ConsentPurpose::OneOffSavedMethod), $binding, $guard, false, false)->denialCode);
        self::assertSame('currency_not_allowed', $reducer->evaluateEligibility($req(currency: 'EUR'), $binding, $guard, false, false)->denialCode);
        self::assertSame('invalid_amount', $reducer->evaluateEligibility($req(amountMinor: 0), $binding, $guard, false, false)->denialCode);
        self::assertSame('invalid_amount', $reducer->evaluateEligibility($req(periodCommittedMinor: -1), $binding, $guard, false, false)->denialCode);
        self::assertSame('consent_not_yet_valid', $reducer->evaluateEligibility($req(asOf: $t0->modify('-1 second')), $binding, $guard, false, false)->denialCode);
        self::assertSame('consent_expired', $reducer->evaluateEligibility($req(asOf: $validUntil), $binding, $guard, false, false)->denialCode);
        self::assertSame('cap_currency_mismatch', $reducer->evaluateEligibility($req(currency: 'USD'), $binding, $guard, false, false)->denialCode);
        self::assertSame('per_charge_cap_exceeded', $reducer->evaluateEligibility($req(amountMinor: 5001), $binding, $guard, false, false)->denialCode);
        self::assertSame('per_period_cap_exceeded', $reducer->evaluateEligibility($req(amountMinor: 5000, periodCommittedMinor: 3001), $binding, $guard, false, false)->denialCode);

        $periodOnlyBinding = $this->makeBinding($t0, id: 10, consentVersion: 1, perChargeCapMinor: null, perPeriodCapMinor: 8000, capCurrency: 'RUB');
        self::assertSame('cap_currency_mismatch', $reducer->evaluateEligibility($req(currency: 'USD'), $periodOnlyBinding, $guard, false, false)->denialCode);

        self::assertSame('authorization_tombstoned', $reducer->evaluateEligibility($req(), $binding, $guard, true, false)->denialCode);
        self::assertSame('business_intent_tombstoned', $reducer->evaluateEligibility($req(), $binding, $guard, false, true)->denialCode);
    }

    public function test_customer_action_and_refund_route_assertions(): void
    {
        $reducer = new ConsentLifecycleReducer;
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $scope = new CollectionScope('seller-a', 'test');
        $binding = $this->makeBinding($t0);
        $route = new OriginalRefundRouteView(1, 'seller-a', 'test', 'op-1', 'account-a', 10, 'consent-1', 1, 'merchant-a', 'mrev-a1', 'prov', 'drv', 'v1', 'pm', 'sha256:'.str_repeat('a', 64), $t0);

        $action = new RecordCustomerAction('cmd', $scope, 'act-1', 'account-a', 'consent-1', 1, 'intent-1', CustomerActionType::ThreeDsChallenge, 'chal-1', [], $t0);
        $reducer->assertCustomerActionBinding($binding, $action);

        $badAction = new RecordCustomerAction('cmd', $scope, 'act-1', 'other', 'consent-1', 1, 'intent-1', CustomerActionType::ThreeDsChallenge, 'chal-1', [], $t0);
        try {
            $reducer->assertCustomerActionBinding($binding, $badAction);
            self::fail('Expected ProblemException');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::AuthDenied, $e->problem);
            self::assertSame('billium.collection.consent_account_mismatch', $e->detail);
        }

        $refundCmd = new RecordOriginalRefundRoute('cmd', $scope, 'op-1', 'account-a', 'consent-1', 1);
        $reducer->assertRefundRouteBinding($binding, $refundCmd);

        $badRefundCmd = new RecordOriginalRefundRoute('cmd', $scope, 'op-1', 'other', 'consent-1', 1);
        try {
            $reducer->assertRefundRouteBinding($binding, $badRefundCmd);
            self::fail('Expected ProblemException');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::AuthDenied, $e->problem);
            self::assertSame('billium.collection.consent_account_mismatch', $e->detail);
        }

        $reducer->assertRefundRouteMatchesOriginal($route, 'merchant-a', 'mrev-a1');
        foreach ([['merchant-b', 'mrev-a1'], ['merchant-a', 'mrev-b1']] as [$mAcct, $mRev]) {
            try {
                $reducer->assertRefundRouteMatchesOriginal($route, $mAcct, $mRev);
                self::fail('Expected ProblemException');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::AuthDenied, $e->problem);
                self::assertSame('billium.collection.refund_must_use_original_binding', $e->detail);
            }
        }
    }

    /**
     * @param  list<ConsentPurpose>  $purposes
     * @param  list<string>  $currencies
     */
    private function makeRegisterCommand(
        DateTimeImmutable $t0,
        int $consentVersion = 1,
        string $billingAccountRef = 'account-a',
        string $customerRef = 'customer-a',
        string $merchantAccountRef = 'merchant-a',
        string $merchantConfigRevision = 'mrev-a1',
        array $purposes = [ConsentPurpose::RecurringCharge, ConsentPurpose::DunningRetry],
        array $currencies = ['RUB', 'USD'],
        ?int $perChargeCapMinor = 10000,
        ?int $perPeriodCapMinor = 25000,
        ?string $capCurrency = 'RUB',
        ?DateTimeImmutable $validUntil = null,
        string $sourceEvidenceRef = 'mandate-proof-1',
        ?string $sourceEvidenceDigest = null,
        int $sourceRevision = 1,
        int $cancellationGeneration = 0,
        ?int $supersedesConsentVersion = null,
    ): RegisterConsentBinding {
        return new RegisterConsentBinding(
            commandKey: 'cmd-reg',
            scope: new CollectionScope('seller-a', 'test'),
            billingAccountRef: $billingAccountRef,
            customerRef: $customerRef,
            consentRef: 'consent-1',
            consentVersion: $consentVersion,
            paymentMethodRef: 'pm_1',
            tokenLocator: 'vault://pm_1',
            merchantAccountRef: $merchantAccountRef,
            merchantConfigRevision: $merchantConfigRevision,
            allowedPurposes: $purposes,
            allowedCurrencies: $currencies,
            perChargeCapMinor: $perChargeCapMinor,
            perPeriodCapMinor: $perPeriodCapMinor,
            capCurrency: $capCurrency,
            capturedAt: $t0,
            validFrom: $t0,
            validUntil: $validUntil,
            sourceEvidenceRef: $sourceEvidenceRef,
            sourceEvidenceDigest: $sourceEvidenceDigest ?? 'sha256:'.str_repeat('e', 64),
            sourceRevision: $sourceRevision,
            cancellationGeneration: $cancellationGeneration,
            supersedesConsentVersion: $supersedesConsentVersion,
        );
    }

    private function makeRevokeCommand(
        string $tombstoneRef,
        int $sourceRevision,
        int $cancellationGeneration,
        TombstoneKind $kind = TombstoneKind::ConsentRevoked,
        ?int $expectedGuardRevision = null,
        string $reason = 'revoked',
    ): RevokeConsentCommand {
        return new RevokeConsentCommand(
            commandKey: 'cmd-rev',
            scope: new CollectionScope('seller-a', 'test'),
            consentRef: 'consent-1',
            tombstoneRef: $tombstoneRef,
            sourceRevision: $sourceRevision,
            cancellationGeneration: $cancellationGeneration,
            reason: $reason,
            evidenceDigest: 'sha256:'.str_repeat('a', 64),
            tombstoneKind: $kind,
            expectedGuardRevision: $expectedGuardRevision,
        );
    }

    private function makeMerchant(
        DateTimeImmutable $t0,
        string $merchantAccountRef = 'merchant-a',
        string $revisionRef = 'mrev-a1',
    ): MerchantRevision {
        return new MerchantRevision(
            revisionRef: $revisionRef,
            scope: new ExecutionScope('inst-1', 'seller-a', 'test', 'source-a'),
            merchantAccountRef: $merchantAccountRef,
            providerId: 'provider-sim',
            driverId: 'driver-saved',
            driverContractVersion: 'driver-saved.v1',
            capabilitySnapshotRef: 'cap-1',
            capabilityDigest: 'sha256:'.str_repeat('a', 64),
            credentialRef: new SecretRef('secret://kms-v1/merchant-a'),
            credentialVersionRef: 'cred-v1',
            currencyCodes: ['RUB', 'USD'],
            digest: 'sha256:'.str_repeat('b', 64),
            createdAt: $t0,
            policy: new MerchantRevisionPolicy($t0, null, null, 'v1', 'wh', 'cont', 'settle', 'mandate'),
        );
    }

    private function makeGuard(
        DateTimeImmutable $t0,
        int $id = 20,
        int $activeBindingId = 10,
        int $activeConsentVersion = 1,
        int $guardRevision = 1,
        int $sourceRevision = 1,
        int $cancellationGeneration = 0,
        ConsentGuardStatus $status = ConsentGuardStatus::Active,
        ?DateTimeImmutable $revokedAt = null,
        ?string $revocationReceiptRef = null,
        ?string $revocationReason = null,
        ?DateTimeImmutable $effectiveAt = null,
    ): ConsentGuardStateView {
        return new ConsentGuardStateView(
            id: $id,
            sellerRef: 'seller-a',
            environment: 'test',
            billingAccountRef: 'account-a',
            consentRef: 'consent-1',
            activeBindingId: $activeBindingId,
            activeConsentVersion: $activeConsentVersion,
            guardRevision: $guardRevision,
            sourceRevision: $sourceRevision,
            cancellationGeneration: $cancellationGeneration,
            status: $status,
            revokedAt: $revokedAt,
            revocationReceiptRef: $revocationReceiptRef,
            revocationReason: $revocationReason,
            effectiveAt: $effectiveAt,
            updatedAt: $t0,
        );
    }

    private function makeBinding(
        DateTimeImmutable $t0,
        int $id = 10,
        int $consentVersion = 1,
        ?DateTimeImmutable $validFrom = null,
        ?DateTimeImmutable $validUntil = null,
        ?int $perChargeCapMinor = 10000,
        ?int $perPeriodCapMinor = 25000,
        ?string $capCurrency = 'RUB',
    ): ConsentBindingView {
        return new ConsentBindingView(
            id: $id,
            sellerRef: 'seller-a',
            environment: 'test',
            billingAccountRef: 'account-a',
            customerRef: 'customer-a',
            consentRef: 'consent-1',
            consentVersion: $consentVersion,
            paymentMethodRef: 'pm_1',
            tokenLocator: 'vault://pm_1',
            merchantAccountRef: 'merchant-a',
            merchantConfigRevision: 'mrev-a1',
            providerId: 'provider-sim',
            driverId: 'driver-saved',
            driverContractVersion: 'driver-saved.v1',
            capabilitySnapshotRef: 'cap-1',
            capabilityDigest: 'sha256:'.str_repeat('a', 64),
            allowedPurposes: [ConsentPurpose::RecurringCharge, ConsentPurpose::DunningRetry],
            allowedCurrencies: ['RUB', 'USD'],
            perChargeCapMinor: $perChargeCapMinor,
            perPeriodCapMinor: $perPeriodCapMinor,
            capCurrency: $capCurrency,
            capturedAt: $t0,
            validFrom: $validFrom ?? $t0,
            validUntil: $validUntil,
            sourceEvidenceRef: 'mandate-proof-1',
            sourceEvidenceDigest: 'sha256:'.str_repeat('e', 64),
            proofDigest: 'sha256:'.str_repeat('d', 64),
            supersedesBindingId: null,
            createdAt: $t0,
        );
    }
}
