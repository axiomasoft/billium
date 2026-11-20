<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P7;

use Billium\Collection\Contracts\CollectionConsentGuard;
use Billium\Collection\Contracts\ConsentBindingService;
use Billium\Collection\Contracts\ConsentQueries;
use Billium\Collection\Contracts\SavedMethodCapabilityValidator;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\ConsentChargeEligibilityRequest;
use Billium\Collection\Data\HostConsentAuthoritySnapshot;
use Billium\Collection\Data\RecordCustomerAction;
use Billium\Collection\Data\RecordOriginalRefundRoute;
use Billium\Collection\Data\RevokeConsentCommand;
use Billium\Collection\Data\SavedMethodCapabilityRequirement;
use Billium\Collection\Enums\ConsentGuardStatus;
use Billium\Collection\Enums\ConsentPurpose;
use Billium\Collection\Enums\CustomerActionStatus;
use Billium\Collection\Enums\CustomerActionType;
use Billium\Collection\Enums\TombstoneKind;
use Billium\Collection\Laravel\CollectionServiceProvider;
use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\AuthorizeCollection;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\BilliumCoreServiceProvider;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Diagnostics\BilliumCompatibilityDoctorCheck;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\P7\Support\CollectionTestCase;
use Billium\Core\Tests\P7\Support\ConsentGuardRaceScenario;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedExecutionReadiness;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Core\Tests\TestEnvironment;
use Billium\Execution\Contracts\AdapterQualification;
use Billium\Execution\Contracts\DispatchEgress;
use Billium\Execution\Contracts\ExecutionOriginResolver;
use Billium\Execution\Contracts\MerchantCredentials;
use Billium\Execution\Contracts\PaymentDrivers;
use Billium\Execution\Contracts\PaymentObservationSink;
use Billium\Execution\Contracts\ProviderAbsenceVerifier;
use Billium\Execution\Contracts\WriterAdmission;
use Billium\Execution\Enums\QualificationMode;
use Billium\Execution\Laravel\ExecutionServiceProvider;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Foundation\Application;
use LogicException;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\CheckStatus;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
final class CollectionConsentTest extends CollectionTestCase
{
    public function test_positive_consent_binding_registration_replay_eligibility_and_authorize_envelope_vectors(): void
    {
        [$merchant, $capabilities] = $this->savedMethodMerchant();

        $v1 = $this->consentService()->registerBinding($this->registerCommand(), $merchant, $capabilities);
        self::assertSame('seller-a', $v1->sellerRef);
        self::assertSame('test', $v1->environment);
        self::assertSame('account-a', $v1->billingAccountRef);
        self::assertSame('customer-a', $v1->customerRef);
        self::assertSame('consent-1', $v1->consentRef);
        self::assertSame(1, $v1->consentVersion);
        self::assertSame('pm_tok_alpha_01', $v1->paymentMethodRef);
        self::assertSame('vault://provider-sim/tok_alpha_01', $v1->tokenLocator);
        self::assertSame('merchant-a', $v1->merchantAccountRef);
        self::assertSame('mrev-a1', $v1->merchantConfigRevision);
        self::assertSame(10000, $v1->perChargeCapMinor);
        self::assertSame(25000, $v1->perPeriodCapMinor);
        self::assertSame('RUB', $v1->capCurrency);
        self::assertNull($v1->supersedesBindingId);

        // Idempotent replay with same commandKey returns identical immutable binding.
        $sameKey = $this->consentService()->registerBinding($this->registerCommand(), $merchant, $capabilities);
        self::assertSame($v1->id, $sameKey->id);
        self::assertSame($v1->proofDigest, $sameKey->proofDigest);

        // Replay with a distinct commandKey for the identical proof also returns the same binding without duplicating rows.
        $otherKey = $this->consentService()->registerBinding(
            $this->registerCommand(commandKey: 'reg-consent-1-retry'),
            $merchant,
            $capabilities,
        );
        self::assertSame($v1->id, $otherKey->id);
        self::assertSame(1, $this->rows('billium_collection_consent_binding'));
        self::assertSame(1, $this->rows('billium_collection_consent_guard'));

        // Guard head initialized in Active state at guardRevision=1.
        $guard = $this->consentQueries()->findGuardState($this->collectionScope(), 'consent-1');
        self::assertNotNull($guard);
        self::assertSame(ConsentGuardStatus::Active, $guard->status);
        self::assertSame($v1->id, $guard->activeBindingId);
        self::assertSame(1, $guard->activeConsentVersion);
        self::assertSame(1, $guard->guardRevision);
        self::assertSame(1, $guard->sourceRevision);
        self::assertSame(0, $guard->cancellationGeneration);
        self::assertNull($guard->revokedAt);
        self::assertNull($guard->effectiveAt);

        // Charge within caps, currency, purpose, validity window, and merchant binding is permitted.
        $verdict = $this->consentService()->evaluateChargeEligibility(new ConsentChargeEligibilityRequest(
            scope: $this->collectionScope(),
            billingAccountRef: 'account-a',
            consentRef: 'consent-1',
            consentVersion: 1,
            merchantAccountRef: 'merchant-a',
            merchantConfigRevision: 'mrev-a1',
            purpose: ConsentPurpose::RecurringCharge,
            currency: 'RUB',
            amountMinor: 8000,
            asOf: new DateTimeImmutable('2026-11-20T12:00:00Z'),
            periodCommittedMinor: 10000,
            expectedCancellationGeneration: 0,
        ));
        self::assertTrue($verdict->permitted);
        self::assertNull($verdict->denialCode);
        self::assertSame($v1->id, $verdict->bindingId);
        self::assertSame($guard->id, $verdict->consentGuardId);
        self::assertSame(1, $verdict->guardRevision);
        self::assertSame($v1->proofDigest, $verdict->proofDigest);

        // Verify existing collection.authorize.v1 fixture vectors stay green under Codec.
        $codec = new Codec;
        $validVectorPath = dirname(__DIR__, 4).'/fixtures/vectors/collection/authorize/valid.json';
        self::assertFileExists($validVectorPath);
        /** @var array{script: list<array{args: array{json_text: string}}>, expect: list<array{ok: array{schema: string, digest: string}}>} $vector */
        $vector = json_decode((string) file_get_contents($validVectorPath), true, 32, JSON_THROW_ON_ERROR);
        $decoded = $codec->decode($vector['script'][0]['args']['json_text']);
        self::assertInstanceOf(AuthorizeCollection::class, $decoded);
        self::assertSame($vector['expect'][0]['ok']['schema'], $decoded->schema);
        self::assertSame($vector['expect'][0]['ok']['digest'], $codec->digest($decoded->raw));
    }

    public function test_negative_pan_cvv_prohibition_cross_merchant_cross_seller_caps_and_capability_checks(): void
    {
        [$merchantA, $capA] = $this->savedMethodMerchant();

        // 1. PAN and CVV patterns are strictly forbidden in paymentMethodRef, tokenLocator, and sourceEvidenceRef.
        foreach ([
            ['paymentMethodRef' => '4111111111111111', 'tokenLocator' => 'tok_safe_1'],
            ['paymentMethodRef' => '4111-1111-1111-1111', 'tokenLocator' => 'tok_safe_2'],
            ['paymentMethodRef' => '4111 1111 1111 1111', 'tokenLocator' => 'tok_safe_3'],
            ['paymentMethodRef' => 'pm_safe_4', 'tokenLocator' => '5500000000000004'],
            ['paymentMethodRef' => 'pm_safe_5', 'tokenLocator' => 'cvv:123'],
        ] as $idx => $bad) {
            try {
                $this->consentService()->registerBinding(
                    $this->registerCommand(
                        commandKey: 'pan-reject-'.$idx,
                        consentRef: 'consent-pan-'.$idx,
                        paymentMethodRef: $bad['paymentMethodRef'],
                        tokenLocator: $bad['tokenLocator'],
                    ),
                    $merchantA,
                    $capA,
                );
                self::fail('PAN/CVV input must be rejected');
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
                self::assertSame('billium.collection.sensitive_card_data_forbidden', $error->detail);
            }
        }
        self::assertSame(0, $this->rows('billium_collection_consent_binding'));

        // DB CHECK constraint backstop also blocks direct raw PAN insert into billium_collection_consent_binding.
        $ownerPdo = TestEnvironment::pdo(owner: true);
        try {
            $ownerPdo->exec(<<<'SQL'
                INSERT INTO public.billium_collection_consent_binding (
                    seller_ref, environment, billing_account_ref, customer_ref,
                    consent_ref, consent_version, payment_method_ref, token_locator,
                    merchant_account_ref, merchant_config_revision, provider_id, driver_id,
                    driver_contract_version, capability_snapshot_ref, capability_digest,
                    allowed_purposes, allowed_currencies, captured_at, valid_from,
                    source_evidence_ref, source_evidence_digest, proof_digest, created_at
                ) VALUES (
                    'seller-a', 'test', 'account-a', 'customer-a',
                    'consent-db-pan', 1, '4111-1111-1111-1111', 'tok_ok',
                    'merchant-a', 'mrev-a1', 'p', 'd', 'd.v1', 'cap', 'sha256:0000000000000000000000000000000000000000000000000000000000000000',
                    '["recurring_charge"]'::jsonb, '["RUB"]'::jsonb, now(), now(),
                    'ev', 'sha256:0000000000000000000000000000000000000000000000000000000000000000',
                    'sha256:0000000000000000000000000000000000000000000000000000000000000000', now()
                )
                SQL);
            self::fail('PostgreSQL CHECK constraint must reject raw PAN');
        } catch (PDOException $exception) {
            self::assertSame('23514', $exception->getCode());
        }

        // 2. Unsupported saved_method / recurring_consent / unverified live qualification are refused.
        [$noSavedMerchant, $noSavedCap] = $this->savedMethodMerchant(
            merchantAccountRef: 'merchant-nosaved',
            merchantConfigRevision: 'mrev-ns1',
            savedMethod: false,
        );
        try {
            $this->consentService()->registerBinding(
                $this->registerCommand(
                    commandKey: 'cap-nosaved',
                    consentRef: 'consent-nosaved',
                    merchantAccountRef: 'merchant-nosaved',
                    merchantConfigRevision: 'mrev-ns1',
                ),
                $noSavedMerchant,
                $noSavedCap,
            );
            self::fail('Missing SavedMethod capability must be refused');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::CapabilityUnsupported, $error->problem);
        }

        [$noRecurMerchant, $noRecurCap] = $this->savedMethodMerchant(
            merchantAccountRef: 'merchant-norecur',
            merchantConfigRevision: 'mrev-nr1',
            savedMethod: true,
            recurringConsent: false,
        );
        try {
            $this->capabilityValidator()->assertMerchantSupportsSavedMethod(
                $noRecurMerchant,
                $noRecurCap,
                new SavedMethodCapabilityRequirement(
                    scope: $this->collectionScope(),
                    merchantAccountRef: 'merchant-norecur',
                    merchantConfigRevision: 'mrev-nr1',
                    currency: 'RUB',
                    purpose: ConsentPurpose::RecurringCharge,
                    production: false,
                ),
            );
            self::fail('Missing RecurringConsent capability must be refused for recurring_charge');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::CapabilityUnsupported, $error->problem);
        }

        // Live environment refuses Simulated qualification.
        [$liveUnverifiedMerchant, $liveUnverifiedCap] = $this->savedMethodMerchant(
            environment: 'live',
            qualificationMode: QualificationMode::Simulated,
        );
        try {
            $this->capabilityValidator()->assertMerchantSupportsSavedMethod(
                $liveUnverifiedMerchant,
                $liveUnverifiedCap,
                new SavedMethodCapabilityRequirement(
                    scope: new CollectionScope('seller-a', 'live'),
                    merchantAccountRef: 'merchant-a',
                    merchantConfigRevision: 'mrev-a1',
                    currency: 'RUB',
                    purpose: ConsentPurpose::RecurringCharge,
                    production: true,
                ),
            );
            self::fail('Unverified qualification must fail in live mode');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::CapabilityUnsupported, $error->problem);
        }

        // 3. Register valid consent for seller-a / merchant-a, then verify negative isolation & cap checks.
        $this->consentService()->registerBinding(
            $this->registerCommand(
                commandKey: 'reg-neg-base',
                consentRef: 'consent-neg',
                validFrom: new DateTimeImmutable('2026-11-20T00:00:00Z'),
                validUntil: new DateTimeImmutable('2026-12-01T00:00:00Z'),
            ),
            $merchantA,
            $capA,
        );

        // Conflicting payload for same (consentRef, consentVersion) under new key -> IdempotencyConflict.
        try {
            $this->consentService()->registerBinding(
                $this->registerCommand(
                    commandKey: 'reg-neg-conflict',
                    consentRef: 'consent-neg',
                    paymentMethodRef: 'pm_tok_different_99',
                ),
                $merchantA,
                $capA,
            );
            self::fail('Conflicting consent version must throw IdempotencyConflict');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::IdempotencyConflict, $error->problem);
        }

        // Token of another merchant forbids new charges.
        $wrongMerchant = $this->consentService()->evaluateChargeEligibility(new ConsentChargeEligibilityRequest(
            scope: $this->collectionScope(),
            billingAccountRef: 'account-a',
            consentRef: 'consent-neg',
            consentVersion: 1,
            merchantAccountRef: 'merchant-b',
            merchantConfigRevision: 'mrev-b1',
            purpose: ConsentPurpose::RecurringCharge,
            currency: 'RUB',
            amountMinor: 5000,
            asOf: new DateTimeImmutable('2026-11-21T00:00:00Z'),
        ));
        self::assertFalse($wrongMerchant->permitted);
        self::assertSame('merchant_binding_mismatch', $wrongMerchant->denialCode);

        // Exceeding perChargeCapMinor and perPeriodCapMinor, disallowed purpose, disallowed currency, and expiry.
        $capExceeded = $this->consentService()->evaluateChargeEligibility(new ConsentChargeEligibilityRequest(
            scope: $this->collectionScope(),
            billingAccountRef: 'account-a',
            consentRef: 'consent-neg',
            consentVersion: 1,
            merchantAccountRef: 'merchant-a',
            merchantConfigRevision: 'mrev-a1',
            purpose: ConsentPurpose::RecurringCharge,
            currency: 'RUB',
            amountMinor: 10001,
            asOf: new DateTimeImmutable('2026-11-21T00:00:00Z'),
        ));
        self::assertFalse($capExceeded->permitted);
        self::assertSame('per_charge_cap_exceeded', $capExceeded->denialCode);

        $periodExceeded = $this->consentService()->evaluateChargeEligibility(new ConsentChargeEligibilityRequest(
            scope: $this->collectionScope(),
            billingAccountRef: 'account-a',
            consentRef: 'consent-neg',
            consentVersion: 1,
            merchantAccountRef: 'merchant-a',
            merchantConfigRevision: 'mrev-a1',
            purpose: ConsentPurpose::RecurringCharge,
            currency: 'RUB',
            amountMinor: 6000,
            asOf: new DateTimeImmutable('2026-11-21T00:00:00Z'),
            periodCommittedMinor: 20000,
        ));
        self::assertFalse($periodExceeded->permitted);
        self::assertSame('per_period_cap_exceeded', $periodExceeded->denialCode);

        $badPurpose = $this->consentService()->evaluateChargeEligibility(new ConsentChargeEligibilityRequest(
            scope: $this->collectionScope(),
            billingAccountRef: 'account-a',
            consentRef: 'consent-neg',
            consentVersion: 1,
            merchantAccountRef: 'merchant-a',
            merchantConfigRevision: 'mrev-a1',
            purpose: ConsentPurpose::UsageSettlement,
            currency: 'RUB',
            amountMinor: 1000,
            asOf: new DateTimeImmutable('2026-11-21T00:00:00Z'),
        ));
        self::assertFalse($badPurpose->permitted);
        self::assertSame('purpose_not_allowed', $badPurpose->denialCode);

        $badCurrency = $this->consentService()->evaluateChargeEligibility(new ConsentChargeEligibilityRequest(
            scope: $this->collectionScope(),
            billingAccountRef: 'account-a',
            consentRef: 'consent-neg',
            consentVersion: 1,
            merchantAccountRef: 'merchant-a',
            merchantConfigRevision: 'mrev-a1',
            purpose: ConsentPurpose::RecurringCharge,
            currency: 'EUR',
            amountMinor: 1000,
            asOf: new DateTimeImmutable('2026-11-21T00:00:00Z'),
        ));
        self::assertFalse($badCurrency->permitted);
        self::assertSame('currency_not_allowed', $badCurrency->denialCode);

        $expired = $this->consentService()->evaluateChargeEligibility(new ConsentChargeEligibilityRequest(
            scope: $this->collectionScope(),
            billingAccountRef: 'account-a',
            consentRef: 'consent-neg',
            consentVersion: 1,
            merchantAccountRef: 'merchant-a',
            merchantConfigRevision: 'mrev-a1',
            purpose: ConsentPurpose::RecurringCharge,
            currency: 'RUB',
            amountMinor: 1000,
            asOf: new DateTimeImmutable('2026-12-01T00:00:00Z'),
        ));
        self::assertFalse($expired->permitted);
        self::assertSame('consent_expired', $expired->denialCode);

        // Cross-seller isolation: seller-b cannot read or charge seller-a's consent.
        $this->callers->as('seller-b');
        try {
            $this->consentQueries()->findBinding(new CollectionScope('seller-a', 'test'), 'consent-neg', 1);
            self::fail('Cross-seller scope must be denied');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::AuthDenied, $error->problem);
        }
        $foreignVerdict = $this->consentService()->evaluateChargeEligibility(new ConsentChargeEligibilityRequest(
            scope: new CollectionScope('seller-b', 'test'),
            billingAccountRef: 'account-a',
            consentRef: 'consent-neg',
            consentVersion: 1,
            merchantAccountRef: 'merchant-a',
            merchantConfigRevision: 'mrev-a1',
            purpose: ConsentPurpose::RecurringCharge,
            currency: 'RUB',
            amountMinor: 1000,
            asOf: new DateTimeImmutable('2026-11-21T00:00:00Z'),
        ));
        self::assertFalse($foreignVerdict->permitted);
        self::assertSame('consent_binding_not_found', $foreignVerdict->denialCode);
    }

    public function test_binding_rotation_preserves_original_refund_route_and_requires_action_never_reports_success(): void
    {
        [$merchantA, $capA] = $this->savedMethodMerchant(merchantAccountRef: 'merchant-a', merchantConfigRevision: 'mrev-a1');
        [$merchantB, $capB] = $this->savedMethodMerchant(merchantAccountRef: 'merchant-b', merchantConfigRevision: 'mrev-b1');

        $v1 = $this->consentService()->registerBinding(
            $this->registerCommand(
                commandKey: 'route-v1',
                consentRef: 'consent-route',
                consentVersion: 1,
                merchantAccountRef: 'merchant-a',
                merchantConfigRevision: 'mrev-a1',
                sourceEvidenceRef: 'mandate-a1',
                sourceEvidenceDigest: self::digest('a'),
            ),
            $merchantA,
            $capA,
        );

        // Freeze original refund route for charge op-charge-001 on binding v1 / merchant-a.
        $route = $this->consentService()->recordOriginalRefundRoute(new RecordOriginalRefundRoute(
            commandKey: 'freeze-route-1',
            scope: $this->collectionScope(),
            operationRef: 'op-charge-001',
            billingAccountRef: 'account-a',
            consentRef: 'consent-route',
            consentVersion: 1,
        ));
        self::assertSame('merchant-a', $route->originalMerchantAccountRef);
        self::assertSame('mrev-a1', $route->originalMerchantConfigRevision);
        self::assertSame($v1->id, $route->originalBindingId);

        // Switching to merchant-b while reusing old consent evidence is refused.
        try {
            $this->consentService()->registerBinding(
                $this->registerCommand(
                    commandKey: 'route-v2-stale-evidence',
                    consentRef: 'consent-route',
                    consentVersion: 2,
                    merchantAccountRef: 'merchant-b',
                    merchantConfigRevision: 'mrev-b1',
                    sourceEvidenceRef: 'mandate-a1',
                    sourceEvidenceDigest: self::digest('a'),
                    sourceRevision: 2,
                    supersedesConsentVersion: 1,
                ),
                $merchantB,
                $capB,
            );
            self::fail('Merchant switch must require new verified consent evidence');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            self::assertSame('billium.collection.merchant_change_requires_new_consent', $error->detail);
        }

        // Switching to merchant-b with fresh verified consent evidence succeeds as version 2.
        $v2 = $this->consentService()->registerBinding(
            $this->registerCommand(
                commandKey: 'route-v2-fresh',
                consentRef: 'consent-route',
                consentVersion: 2,
                paymentMethodRef: 'pm_tok_beta_02',
                tokenLocator: 'vault://provider-sim/tok_beta_02',
                merchantAccountRef: 'merchant-b',
                merchantConfigRevision: 'mrev-b1',
                sourceEvidenceRef: 'mandate-b2',
                sourceEvidenceDigest: self::digest('b'),
                sourceRevision: 2,
                supersedesConsentVersion: 1,
            ),
            $merchantB,
            $capB,
        );
        self::assertSame($v1->id, $v2->supersedesBindingId);
        self::assertSame('merchant-b', $v2->merchantAccountRef);

        // Old version v1 is immutable, cannot be used for new charges, while v2 is eligible on merchant-b.
        $v1Reloaded = $this->consentQueries()->findBinding($this->collectionScope(), 'consent-route', 1);
        self::assertNotNull($v1Reloaded);
        self::assertSame($v1->proofDigest, $v1Reloaded->proofDigest);

        $v1Verdict = $this->consentService()->evaluateChargeEligibility(new ConsentChargeEligibilityRequest(
            scope: $this->collectionScope(),
            billingAccountRef: 'account-a',
            consentRef: 'consent-route',
            consentVersion: 1,
            merchantAccountRef: 'merchant-a',
            merchantConfigRevision: 'mrev-a1',
            purpose: ConsentPurpose::RecurringCharge,
            currency: 'RUB',
            amountMinor: 2000,
            asOf: new DateTimeImmutable('2026-11-20T12:00:00Z'),
        ));
        self::assertFalse($v1Verdict->permitted);
        self::assertSame('consent_version_superseded', $v1Verdict->denialCode);

        $v2Verdict = $this->consentService()->evaluateChargeEligibility(new ConsentChargeEligibilityRequest(
            scope: $this->collectionScope(),
            billingAccountRef: 'account-a',
            consentRef: 'consent-route',
            consentVersion: 2,
            merchantAccountRef: 'merchant-b',
            merchantConfigRevision: 'mrev-b1',
            purpose: ConsentPurpose::RecurringCharge,
            currency: 'RUB',
            amountMinor: 2000,
            asOf: new DateTimeImmutable('2026-11-20T12:00:00Z'),
        ));
        self::assertTrue($v2Verdict->permitted);

        // Changing binding does NOT change the original refund route for op-charge-001!
        $resolved = $this->consentQueries()->findOriginalRefundRoute($this->collectionScope(), 'op-charge-001');
        self::assertNotNull($resolved);
        self::assertSame('merchant-a', $resolved->originalMerchantAccountRef);
        self::assertSame('mrev-a1', $resolved->originalMerchantConfigRevision);
        self::assertSame($route->routeDigest, $resolved->routeDigest);

        $matched = $this->consentService()->assertRefundRouteMatchesOriginal(
            $this->collectionScope(),
            'op-charge-001',
            'merchant-a',
            'mrev-a1',
        );
        self::assertSame($route->id, $matched->id);

        try {
            $this->consentService()->assertRefundRouteMatchesOriginal(
                $this->collectionScope(),
                'op-charge-001',
                'merchant-b',
                'mrev-b1',
            );
            self::fail('Refund against rotated merchant must be refused');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::AuthDenied, $error->problem);
            self::assertSame('billium.collection.refund_must_use_original_binding', $error->detail);
        }

        // requires_action returns a durable CustomerActionReceipt without reporting paid or terminal failure.
        $action = $this->consentService()->recordCustomerAction(new RecordCustomerAction(
            commandKey: 'act-3ds-1',
            scope: $this->collectionScope(),
            actionRef: 'cact-3ds-001',
            billingAccountRef: 'account-a',
            consentRef: 'consent-route',
            consentVersion: 2,
            businessPaymentIntentRef: 'bpi-001',
            actionType: CustomerActionType::ThreeDsChallenge,
            challengeRef: 'chal_3ds_9988',
            actionPayload: [
                'redirectUrl' => 'https://acs.example.test/challenge/chal_3ds_9988',
                'method' => '3ds2_challenge',
            ],
            expiresAt: new DateTimeImmutable('2026-11-20T13:00:00Z', new DateTimeZone('UTC')),
            operationRef: 'op-charge-002',
        ));
        self::assertSame(CustomerActionStatus::RequiresAction, $action->status);
        self::assertFalse($action->paid);
        self::assertFalse($action->terminalFailure);
        self::assertSame('chal_3ds_9988', $action->challengeRef);
        $actionsBeforeIdentityConflict = $this->rows('billium_collection_customer_action');
        try {
            $this->consentService()->recordCustomerAction(new RecordCustomerAction(
                commandKey: 'act-3ds-identity',
                scope: $this->collectionScope(),
                actionRef: 'cact-3ds-002',
                billingAccountRef: 'account-a',
                consentRef: 'consent-route',
                consentVersion: 2,
                businessPaymentIntentRef: 'bpi-001',
                actionType: CustomerActionType::ThreeDsChallenge,
                challengeRef: 'chal_3ds_9988',
                actionPayload: ['method' => '3ds2_challenge'],
                expiresAt: new DateTimeImmutable('2026-11-20T13:00:00Z', new DateTimeZone('UTC')),
            ));
            self::fail('A second action for the same challenge identity must conflict');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::IdempotencyConflict, $error->problem);
            self::assertSame('billium.collection.customer_action_conflict', $error->detail);
        }
        self::assertSame($actionsBeforeIdentityConflict, $this->rows('billium_collection_customer_action'));

        $reloadedAction = $this->consentQueries()->findCustomerAction($this->collectionScope(), 'cact-3ds-001');
        self::assertNotNull($reloadedAction);
        self::assertSame($action->receiptDigest, $reloadedAction->receiptDigest);
        self::assertFalse($reloadedAction->paid);
        self::assertFalse($reloadedAction->terminalFailure);

        // Sensitive card fields inside actionPayload are rejected.
        try {
            $this->consentService()->recordCustomerAction(new RecordCustomerAction(
                commandKey: 'act-3ds-bad',
                scope: $this->collectionScope(),
                actionRef: 'cact-3ds-bad',
                billingAccountRef: 'account-a',
                consentRef: 'consent-route',
                consentVersion: 2,
                businessPaymentIntentRef: 'bpi-002',
                actionType: CustomerActionType::ScaChallenge,
                challengeRef: 'chal_bad',
                actionPayload: ['cvv' => '123'],
                expiresAt: new DateTimeImmutable('2026-11-20T13:00:00Z', new DateTimeZone('UTC')),
            ));
            self::fail('Sensitive key in actionPayload must be rejected');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            self::assertSame('billium.collection.sensitive_card_data_forbidden', $error->detail);
        }

        $actionsBeforeIntegerPan = $this->rows('billium_collection_customer_action');
        try {
            $this->consentService()->recordCustomerAction(new RecordCustomerAction(
                commandKey: 'act-3ds-int-pan',
                scope: $this->collectionScope(),
                actionRef: 'cact-3ds-int-pan',
                billingAccountRef: 'account-a',
                consentRef: 'consent-route',
                consentVersion: 2,
                businessPaymentIntentRef: 'bpi-002',
                actionType: CustomerActionType::ScaChallenge,
                challengeRef: 'chal_int_pan',
                actionPayload: ['value' => 4111111111111111],
                expiresAt: new DateTimeImmutable('2026-11-20T13:00:00Z', new DateTimeZone('UTC')),
            ));
            self::fail('An integer PAN in actionPayload must be rejected');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            self::assertSame('billium.collection.sensitive_card_data_forbidden', $error->detail);
        }
        self::assertSame($actionsBeforeIntegerPan, $this->rows('billium_collection_customer_action'));
    }

    public function test_pre_operation_revocation_tombstone_local_receipt_effectiveness_and_host_authority_guard(): void
    {
        [$merchant, $capabilities] = $this->savedMethodMerchant();
        $binding = $this->consentService()->registerBinding(
            $this->registerCommand(commandKey: 'reg-rev-1', consentRef: 'consent-rev'),
            $merchant,
            $capabilities,
        );

        // Before local revocation acceptance, consent is active.
        $before = $this->consentQueries()->findGuardState($this->collectionScope(), 'consent-rev');
        self::assertNotNull($before);
        self::assertSame(ConsentGuardStatus::Active, $before->status);
        self::assertSame(1, $before->guardRevision);

        // Unbound CollectionConsentGuard fails closed on host authority sync.
        try {
            $this->consentService()->syncHostAuthority(
                $this->collectionScope(),
                'account-a',
                'consent-rev',
                'sync-unbound-1',
                'tomb-unbound-1',
            );
            self::fail('Unbound CollectionConsentGuard must fail closed');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::LookupUnavailable, $error->problem);
            self::assertSame('billium.collection.consent_guard_unbound', $error->detail);
        }

        // Calling syncHostAuthority inside an active transaction (including raw PDO transaction) fails closed.
        $this->bindHostConsentGuard(new HostConsentAuthoritySnapshot(
            sellerRef: 'seller-a',
            environment: 'test',
            billingAccountRef: 'account-a',
            consentRef: 'consent-rev',
            consentVersion: 1,
            sourceRevision: 2,
            cancellationGeneration: 1,
            active: false,
            reason: 'host_cancelled_subscription',
            authorityDigest: self::digest('f'),
            evaluatedAt: new DateTimeImmutable('2026-11-20T12:05:00Z'),
        ));

        $conn = $this->application()->make(DatabaseManager::class)->connection('pgsql');
        $conn->getPdo()->beginTransaction();
        try {
            $this->consentService()->syncHostAuthority(
                $this->collectionScope(),
                'account-a',
                'consent-rev',
                'sync-in-tx',
                'tomb-in-tx',
            );
            self::fail('syncHostAuthority inside raw PDO transaction must fail closed');
        } catch (LogicException $error) {
            self::assertStringContainsString('outside every database transaction', $error->getMessage());
        } finally {
            $conn->getPdo()->rollBack();
        }

        // Disconnected connection does not crash outside-Tx guard (uses getRawPdo/getRawReadPdo).
        $conn->disconnect();
        $receipt = $this->consentService()->syncHostAuthority(
            $this->collectionScope(),
            'account-a',
            'consent-rev',
            'sync-host-cancel-1',
            'tomb-host-cancel-1',
        );
        self::assertNotNull($receipt);
        self::assertSame('tomb-host-cancel-1', $receipt->tombstoneRef);
        self::assertSame(TombstoneKind::HostCancelled, $receipt->tombstoneKind);
        self::assertSame(ConsentGuardStatus::Cancelled, $receipt->status);
        self::assertSame(2, $receipt->guardRevision);
        self::assertSame(2, $receipt->sourceRevision);
        self::assertSame(1, $receipt->cancellationGeneration);

        // Immutable consent proof is unchanged while mutable guard state reflects durable local cancellation!
        $proofAfter = $this->consentQueries()->findBinding($this->collectionScope(), 'consent-rev', 1);
        self::assertNotNull($proofAfter);
        self::assertSame($binding->proofDigest, $proofAfter->proofDigest);

        $guardAfter = $this->consentQueries()->findGuardState($this->collectionScope(), 'consent-rev');
        self::assertNotNull($guardAfter);
        self::assertSame(ConsentGuardStatus::Cancelled, $guardAfter->status);
        self::assertSame('tomb-host-cancel-1', $guardAfter->revocationReceiptRef);
        self::assertNotNull($guardAfter->effectiveAt);

        // Pre-operation authorization tombstone can also be appended without reviving guard.
        $tomb2 = $this->consentService()->revokeOrCancel(new RevokeConsentCommand(
            commandKey: 'revoke-preop-auth-1',
            scope: $this->collectionScope(),
            consentRef: 'consent-rev',
            tombstoneRef: 'tomb-preop-auth-1',
            sourceRevision: 3,
            cancellationGeneration: 2,
            reason: 'pre_operation_authorization_tombstone',
            evidenceDigest: self::digest('d'),
            tombstoneKind: TombstoneKind::AuthorizationTombstone,
            targetAuthorizationRef: 'step-auth-preop-1',
            targetBusinessIntentRef: 'bpi-preop-1',
            expectedGuardRevision: 2,
        ));
        self::assertSame(3, $tomb2->guardRevision);
        self::assertSame('step-auth-preop-1', $tomb2->targetAuthorizationRef);
        self::assertCount(2, $this->consentQueries()->listTombstones($this->collectionScope(), 'consent-rev'));

        // New charges and new binding versions are forbidden on the revoked/cancelled consent.
        $verdict = $this->consentService()->evaluateChargeEligibility(new ConsentChargeEligibilityRequest(
            scope: $this->collectionScope(),
            billingAccountRef: 'account-a',
            consentRef: 'consent-rev',
            consentVersion: 1,
            merchantAccountRef: 'merchant-a',
            merchantConfigRevision: 'mrev-a1',
            purpose: ConsentPurpose::RecurringCharge,
            currency: 'RUB',
            amountMinor: 1000,
            asOf: new DateTimeImmutable('2026-11-20T12:10:00Z'),
            authorizationRef: 'step-auth-preop-1',
        ));
        self::assertFalse($verdict->permitted);
        self::assertSame('host_cancelled', $verdict->denialCode);

        try {
            $this->consentService()->registerBinding(
                $this->registerCommand(
                    commandKey: 'revive-attempt',
                    consentRef: 'consent-rev',
                    consentVersion: 2,
                    sourceRevision: 4,
                    cancellationGeneration: 2,
                    supersedesConsentVersion: 1,
                ),
                $merchant,
                $capabilities,
            );
            self::fail('Revoked/cancelled consent cannot be superseded back into active state');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::OperationFinancialHold, $error->problem);
            self::assertSame('billium.collection.consent_revoked', $error->detail);
        }

        // PostgreSQL immutability & non-revival triggers reject raw SQL tampering even as owner.
        $ownerPdo = TestEnvironment::pdo(owner: true);
        foreach ([
            ["UPDATE public.billium_collection_consent_binding SET payment_method_ref = 'pm_tampered' WHERE consent_ref = 'consent-rev'", '55000'],
            ["UPDATE public.billium_collection_guard_tombstone SET reason = 'tampered' WHERE consent_ref = 'consent-rev'", '55000'],
            ["UPDATE public.billium_collection_consent_guard SET status = 'active', guard_revision = guard_revision + 1, revoked_at = NULL, revocation_receipt_ref = NULL, effective_at = NULL WHERE consent_ref = 'consent-rev'", '23514'],
            ["DELETE FROM public.billium_collection_consent_guard WHERE consent_ref = 'consent-rev'", '23514'],
        ] as [$sql, $expectedState]) {
            try {
                $ownerPdo->exec($sql);
                self::fail('DB trigger must forbid: '.$sql);
            } catch (PDOException $exception) {
                self::assertSame($expectedState, $exception->getCode());
            }
        }
    }

    public function test_concurrency_dunning_case_lock_rollback_and_billium_connection_isolation(): void
    {
        [$merchant, $capabilities] = $this->savedMethodMerchant();
        $this->consentService()->registerBinding(
            $this->registerCommand(commandKey: 'reg-race', consentRef: 'consent-race'),
            $merchant,
            $capabilities,
        );

        // 1. Multi-process CAS race on the same DunningCase-rank consent guard row (expectedGuardRevision = 1).
        $results = ConcurrencyStand::run(
            ConsentGuardRaceScenario::class,
            4,
            ['consentRef' => 'consent-race', 'expectedGuardRevision' => 1],
        );
        $accepted = 0;
        $conflicts = 0;
        foreach ($results as $worker) {
            $body = self::ok($worker);
            if ($body['accepted'] === true) {
                $accepted++;
                self::assertSame(2, $body['guardRevision']);
            } else {
                $conflicts++;
                self::assertSame(ProblemCode::IdempotencyConflict->value, $body['problem']);
            }
        }
        self::assertSame(1, $accepted, 'Exactly one concurrent CAS revocation must win at guardRevision=1');
        self::assertSame(3, $conflicts, 'All competing CAS revocations must be refused with conflict');
        self::assertSame(1, $this->rows('billium_collection_guard_tombstone', "consent_ref = 'consent-race'"));
        self::assertSame(2, $this->value("SELECT guard_revision FROM billium_collection_consent_guard WHERE consent_ref = 'consent-race'"));

        // 2. Refusal/rollback: a root transaction locking the guard at LockRank::DunningCase that fails rolls back atomically.
        $this->consentService()->registerBinding(
            $this->registerCommand(commandKey: 'reg-rb', consentRef: 'consent-rb'),
            $merchant,
            $capabilities,
        );
        try {
            $this->application()->make(OperationRunner::class)->run(
                new OperationSpec(
                    commandKind: 'collection.test.lock_rollback',
                    sellerRef: 'seller-a',
                    environment: 'test',
                    mode: FinancialMode::Active,
                    operationClass: ModuleOperationClass::FrozenIntentCompletion,
                    modules: ['platform', 'ledger', 'execution', 'collection'],
                    commandKey: 'rb-guard-key',
                    digest: self::digest('c'),
                ),
                function (TransactionContext $context): Receipt {
                    $locked = $this->consentService()->lockGuard($context, $this->collectionScope(), 'consent-rb');
                    self::assertSame(ConsentGuardStatus::Active, $locked->status);
                    $verdict = $this->consentService()->evaluateChargeEligibility(
                        new ConsentChargeEligibilityRequest(
                            scope: $this->collectionScope(),
                            billingAccountRef: 'account-a',
                            consentRef: 'consent-rb',
                            consentVersion: 1,
                            merchantAccountRef: 'merchant-a',
                            merchantConfigRevision: 'mrev-a1',
                            purpose: ConsentPurpose::RecurringCharge,
                            currency: 'RUB',
                            amountMinor: 5000,
                            asOf: new DateTimeImmutable('2026-11-20T12:00:00Z'),
                        ),
                        $context,
                    );
                    self::assertTrue($verdict->permitted);

                    throw new RuntimeException('simulated_downstream_failure');
                },
            );
            self::fail('Simulated failure must roll back the root transaction');
        } catch (RuntimeException $error) {
            self::assertSame('simulated_downstream_failure', $error->getMessage());
        }
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_key = 'rb-guard-key'"));
        self::assertSame(1, $this->consentQueries()->findGuardState($this->collectionScope(), 'consent-rb')?->guardRevision);

        // 3. BilliumOptions connection isolation: unavailable host default connection is never touched.
        $config = $this->application()->make(Repository::class);
        $config->set('database.default', 'unavailable_host');
        $config->set('database.connections.unavailable_host', [
            ...TestEnvironment::connection(),
            'database' => 'absent_host_database',
        ]);
        $found = $this->consentQueries()->findBinding($this->collectionScope(), 'consent-rb', 1);
        self::assertNotNull($found);
        self::assertNotNull($this->consentQueries()->findGuardState($this->collectionScope(), 'consent-rb'));
        self::assertArrayNotHasKey('unavailable_host', $this->application()->make(DatabaseManager::class)->getConnections());
    }

    public function test_clean_host_resolves_collection_only_when_dependencies_active_and_disabled_profile_registers_nothing(): void
    {
        $containerBackup = Container::getInstance();
        try {
            // 1. Disabled profile (embedded-commerz, collection disabled): no CollectionServiceProvider, no bindings, ledger works.
            $disabledHost = self::bootCleanHost([
                'modules' => ['composition' => 'embedded-commerz', 'enabled' => ['platform', 'ledger']],
                'collection' => ['enabled' => false],
            ]);
            try {
                self::assertSame([], $disabledHost->getProviders(CollectionServiceProvider::class));
                self::assertFalse($disabledHost->bound(ConsentBindingService::class));
                self::assertFalse($disabledHost->bound(ConsentQueries::class));
                self::assertFalse($disabledHost->bound(SavedMethodCapabilityValidator::class));

                /** @var Ledger $ledger */
                $ledger = $disabledHost->make(Ledger::class);
                $ob = $ledger->recordObligation(new RecordObligation(
                    commandKey: 'disabled-coll-ob',
                    scope: new BillingAccountScope('seller-a', 'test', 'account-a'),
                    obligationRef: 'ob-disabled-coll',
                    basisRef: 'basis-disabled-coll',
                    customerRef: 'cust-1',
                    componentRef: 'comp-1',
                    money: new Money(1500, Currency::of('RUB')),
                    termsDigest: self::digest('a'),
                ));
                self::assertTrue($ob->accepted);
            } finally {
                self::purgeHostConnections($disabledHost);
            }

            // 2. Missing required dependency (collection enabled WITHOUT execution): Packman plugin graph fails closed.
            try {
                self::bootCleanHost([
                    'modules' => ['composition' => 'standalone-billium', 'enabled' => ['platform', 'ledger', 'collection']],
                    'collection' => ['enabled' => true],
                ]);
                self::fail('Collection must not boot without active execution dependency');
            } catch (InvalidConfiguration $error) {
                self::assertStringContainsString('axiomasoft.billium.execution', $error->getMessage());
            }

            // 3. Clean host with platform + ledger + execution + collection active resolves public API and checks doctor port.
            $cleanHost = self::bootCleanHost([
                'modules' => ['composition' => 'standalone-billium', 'enabled' => ['platform', 'ledger', 'execution', 'collection']],
                'collection' => ['enabled' => true],
                'feed' => ['signing_key' => str_repeat('a', 32)],
            ]);
            try {
                self::assertNotEmpty($cleanHost->getProviders(ExecutionServiceProvider::class));
                self::assertNotEmpty($cleanHost->getProviders(CollectionServiceProvider::class));
                self::assertInstanceOf(ConsentBindingService::class, $cleanHost->make(ConsentBindingService::class));
                self::assertInstanceOf(ConsentQueries::class, $cleanHost->make(ConsentQueries::class));
                self::assertInstanceOf(SavedMethodCapabilityValidator::class, $cleanHost->make(SavedMethodCapabilityValidator::class));
                self::assertInstanceOf(CollectionConsentGuard::class, $cleanHost->make(CollectionConsentGuard::class));

                // Bind required execution ports, leaving CollectionConsentGuard unbound -> doctor flags missing port.
                foreach ([
                    PaymentDrivers::class,
                    WriterAdmission::class,
                    PaymentObservationSink::class,
                    DispatchEgress::class,
                    MerchantCredentials::class,
                    ProviderAbsenceVerifier::class,
                    ExecutionOriginResolver::class,
                    AdapterQualification::class,
                ] as $port) {
                    $cleanHost->instance($port, new \stdClass);
                }
                $beforeGuard = $cleanHost->make(BilliumCompatibilityDoctorCheck::class)->run(new DoctorContext(production: false));
                self::assertNotContains(CheckStatus::Ok, array_map(static fn ($f): CheckStatus => $f->status, $beforeGuard));

                // After host binds CollectionConsentGuard, doctor check passes with CheckStatus::Ok.
                $cleanHost->instance(CollectionConsentGuard::class, new class implements CollectionConsentGuard
                {
                    public function currentAuthority(
                        CollectionScope $scope,
                        string $billingAccountRef,
                        string $consentRef,
                    ): HostConsentAuthoritySnapshot {
                        return new HostConsentAuthoritySnapshot(
                            sellerRef: $scope->sellerRef,
                            environment: $scope->environment,
                            billingAccountRef: $billingAccountRef,
                            consentRef: $consentRef,
                            consentVersion: 1,
                            sourceRevision: 1,
                            cancellationGeneration: 0,
                            active: true,
                            reason: null,
                            authorityDigest: 'sha256:'.str_repeat('1', 64),
                            evaluatedAt: new DateTimeImmutable('2026-11-20T00:00:00Z'),
                        );
                    }
                });
                $afterGuard = $cleanHost->make(BilliumCompatibilityDoctorCheck::class)->run(new DoctorContext(production: false));
                self::assertCount(1, $afterGuard);
                self::assertSame(CheckStatus::Ok, $afterGuard[0]->status);
            } finally {
                self::purgeHostConnections($cleanHost);
            }
        } finally {
            Container::setInstance($containerBackup);
        }
    }

    /** @param array<string, mixed> $billiumConfig */
    private static function bootCleanHost(array $billiumConfig): Application
    {
        $app = new Application(dirname(__DIR__, 3));
        Container::setInstance($app);
        $baseBillium = require dirname(__DIR__, 2).'/config/billium.php';
        $mergedBillium = array_replace_recursive($baseBillium, ['connection' => 'pgsql'], $billiumConfig);
        $app->instance('config', new ConfigRepository([
            'app' => [
                'key' => 'base64:'.base64_encode(str_repeat('k', 32)),
                'cipher' => 'AES-256-CBC',
            ],
            'database' => [
                'default' => 'pgsql',
                'connections' => [
                    'pgsql' => TestEnvironment::connection(),
                    'billium_owner' => TestEnvironment::connection(owner: true),
                ],
            ],
            'billium' => $mergedBillium,
        ]));
        $app->alias('config', Repository::class);
        $app->register(DatabaseServiceProvider::class);
        $app->register(EncryptionServiceProvider::class);
        $app->register(BilliumCoreServiceProvider::class);
        $app->instance(CallerContext::class, FixedCallerContext::default());
        $app->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token()));
        SimulatedFinancialAuthorityEvidence::admitCurrent($app);
        SimulatedExecutionReadiness::install($app);
        $app->boot();

        return $app;
    }

    private static function purgeHostConnections(Application $app): void
    {
        $databases = $app->make(DatabaseManager::class);
        foreach (array_keys($databases->getConnections()) as $name) {
            $databases->purge($name);
        }
    }
}
