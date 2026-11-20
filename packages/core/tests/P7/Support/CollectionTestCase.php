<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P7\Support;

use Billium\Collection\Contracts\CollectionConsentGuard;
use Billium\Collection\Contracts\ConsentBindingService;
use Billium\Collection\Contracts\ConsentQueries;
use Billium\Collection\Contracts\SavedMethodCapabilityValidator;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\HostConsentAuthoritySnapshot;
use Billium\Collection\Data\RegisterConsentBinding;
use Billium\Collection\Enums\ConsentPurpose;
use Billium\Collection\Laravel\CollectionServiceProvider;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Data\CapabilityDetails;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\MerchantRevisionPolicy;
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Enums\CaptureMode;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ProviderEvidenceMode;
use Billium\Execution\Enums\QualificationMode;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Packman\Schema\Contracts\Migration\SchemaMigrator;

/**
 * Real PostgreSQL test base for Phase P7 (Collection + Execution + Ledger + Platform).
 */
abstract class CollectionTestCase extends ExecutionTestCase
{
    /** @param Application $app */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), CollectionServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $config = $app->make(Repository::class);
        $config->set('billium.modules.composition', 'standalone-billium');
        $config->set('billium.modules.enabled', ['platform', 'ledger', 'execution', 'collection']);
        $config->set('billium.collection.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->application()->make(SchemaMigrator::class)->migrateSets(
            ['axiomasoft/billium-core:billium-collection'],
            'billium_owner',
        );
    }

    protected function collectionScope(?string $sellerRef = null, ?string $environment = null): CollectionScope
    {
        $caller = $this->callers->current();

        return new CollectionScope($sellerRef ?? $caller->sellerRef, $environment ?? $caller->environment);
    }

    protected function consentService(): ConsentBindingService
    {
        return $this->application()->make(ConsentBindingService::class);
    }

    protected function consentQueries(): ConsentQueries
    {
        return $this->application()->make(ConsentQueries::class);
    }

    protected function capabilityValidator(): SavedMethodCapabilityValidator
    {
        return $this->application()->make(SavedMethodCapabilityValidator::class);
    }

    protected function bindHostConsentGuard(HostConsentAuthoritySnapshot $snapshot): void
    {
        $this->application()->instance(CollectionConsentGuard::class, new readonly class($snapshot) implements CollectionConsentGuard
        {
            public function __construct(private HostConsentAuthoritySnapshot $snapshot) {}

            public function currentAuthority(
                CollectionScope $scope,
                string $billingAccountRef,
                string $consentRef,
            ): HostConsentAuthoritySnapshot {
                return $this->snapshot;
            }
        });
    }

    protected static function digest(string $char = 'a'): string
    {
        return 'sha256:'.str_repeat($char, 64);
    }

    /**
     * @param  list<string>  $currencies
     * @return array{0: MerchantRevision, 1: CapabilitySnapshot}
     */
    protected function savedMethodMerchant(
        string $sellerRef = 'seller-a',
        string $environment = 'test',
        string $merchantAccountRef = 'merchant-a',
        string $merchantConfigRevision = 'mrev-a1',
        array $currencies = ['RUB', 'USD'],
        bool $savedMethod = true,
        bool $recurringConsent = true,
        QualificationMode $qualificationMode = QualificationMode::Simulated,
    ): array {
        $snapshotRef = 'cap-'.$merchantAccountRef.'-'.$merchantConfigRevision;
        $capDigest = 'sha256:'.hash('sha256', $snapshotRef.':'.($savedMethod ? '1' : '0').':'.($recurringConsent ? '1' : '0').':'.implode(',', $currencies));
        $capabilities = new CapabilitySnapshot(
            snapshotRef: $snapshotRef,
            driverId: 'driver-saved',
            driverContractVersion: 'driver-saved.v1',
            capabilityVersion: '2026-11-v1',
            merchantAccountRef: $merchantAccountRef,
            supportedEffects: [EffectKind::Charge, EffectKind::Refund],
            currencies: $currencies,
            evidenceMode: ProviderEvidenceMode::StableEffectId,
            partialCapture: false,
            partialRefund: true,
            partialVoidAuthorization: false,
            authorizationExpiryEvidence: false,
            lookupByReference: true,
            inventoryComplete: true,
            absenceCertification: true,
            idempotencyValidUntil: new DateTimeImmutable('2027-01-01T00:00:00Z'),
            qualificationMode: $qualificationMode,
            qualificationEvidenceRef: 'qual-'.$merchantAccountRef,
            digest: $capDigest,
            details: new CapabilityDetails(
                version: '1',
                initiateWithIdempotency: true,
                initiateWithoutIdempotency: false,
                idempotencyKeyScope: 'merchant_account',
                idempotencyRetentionSeconds: 86400,
                lookupByProviderId: true,
                lookupVisibility: 'complete',
                lookupRetentionSeconds: 86400,
                captureMode: CaptureMode::None,
                multipleRefund: true,
                refundLookup: true,
                signedCallbacks: true,
                callbackEventIdentity: true,
                callbackEventLookup: true,
                asynchronousStates: true,
                settlementListing: false,
                settlementExport: false,
                disputes: false,
                savedMethod: $savedMethod,
                recurringConsent: $recurringConsent,
                fiscalPassthrough: false,
                lookupNamespace: 'provider-ns',
                referenceSemantics: 'exact',
                qualificationManifestRef: 'manifest-'.$merchantAccountRef,
            ),
        );

        $merchant = new MerchantRevision(
            revisionRef: $merchantConfigRevision,
            scope: new ExecutionScope(self::INSTALLATION, $sellerRef, $environment, 'source-a'),
            merchantAccountRef: $merchantAccountRef,
            providerId: 'provider-sim',
            driverId: 'driver-saved',
            driverContractVersion: 'driver-saved.v1',
            capabilitySnapshotRef: $snapshotRef,
            capabilityDigest: $capDigest,
            credentialRef: new SecretRef('secret://kms-v1/'.$merchantAccountRef, 'kms-v1'),
            credentialVersionRef: 'cred-v1',
            currencyCodes: $currencies,
            digest: 'sha256:'.hash('sha256', $merchantAccountRef.':'.$merchantConfigRevision),
            createdAt: new DateTimeImmutable('2026-11-20T00:00:00Z'),
            policy: new MerchantRevisionPolicy(
                validFrom: new DateTimeImmutable('2026-01-01T00:00:00Z'),
                validUntil: null,
                predecessorRef: null,
                apiProfileVersion: 'v1',
                webhookRouteRef: 'wh-'.$merchantAccountRef,
                continuityEvidenceRef: 'cont-'.$merchantAccountRef,
                settlementAccountRef: 'settle-'.$merchantAccountRef,
                mandateNamespaceRef: 'mandate-ns-'.$merchantAccountRef,
            ),
        );

        return [$merchant, $capabilities];
    }

    /**
     * @param  list<ConsentPurpose>  $purposes
     * @param  list<string>  $currencies
     */
    protected function registerCommand(
        string $commandKey = 'reg-consent-1',
        string $consentRef = 'consent-1',
        int $consentVersion = 1,
        string $billingAccountRef = 'account-a',
        string $customerRef = 'customer-a',
        string $paymentMethodRef = 'pm_tok_alpha_01',
        string $tokenLocator = 'vault://provider-sim/tok_alpha_01',
        string $merchantAccountRef = 'merchant-a',
        string $merchantConfigRevision = 'mrev-a1',
        array $purposes = [ConsentPurpose::RecurringCharge, ConsentPurpose::DunningRetry],
        array $currencies = ['RUB', 'USD'],
        ?int $perChargeCapMinor = 10000,
        ?int $perPeriodCapMinor = 25000,
        ?string $capCurrency = 'RUB',
        ?DateTimeImmutable $validFrom = null,
        ?DateTimeImmutable $validUntil = null,
        string $sourceEvidenceRef = 'mandate-proof-1',
        ?string $sourceEvidenceDigest = null,
        int $sourceRevision = 1,
        int $cancellationGeneration = 0,
        ?int $supersedesConsentVersion = null,
        ?CollectionScope $scope = null,
    ): RegisterConsentBinding {
        $utc = new DateTimeZone('UTC');

        return new RegisterConsentBinding(
            commandKey: $commandKey,
            scope: $scope ?? $this->collectionScope(),
            billingAccountRef: $billingAccountRef,
            customerRef: $customerRef,
            consentRef: $consentRef,
            consentVersion: $consentVersion,
            paymentMethodRef: $paymentMethodRef,
            tokenLocator: $tokenLocator,
            merchantAccountRef: $merchantAccountRef,
            merchantConfigRevision: $merchantConfigRevision,
            allowedPurposes: $purposes,
            allowedCurrencies: $currencies,
            perChargeCapMinor: $perChargeCapMinor,
            perPeriodCapMinor: $perPeriodCapMinor,
            capCurrency: $capCurrency,
            capturedAt: new DateTimeImmutable('2026-11-20T00:00:00Z', $utc),
            validFrom: $validFrom ?? new DateTimeImmutable('2026-11-20T00:00:00Z', $utc),
            validUntil: $validUntil,
            sourceEvidenceRef: $sourceEvidenceRef,
            sourceEvidenceDigest: $sourceEvidenceDigest ?? self::digest('e'),
            sourceRevision: $sourceRevision,
            cancellationGeneration: $cancellationGeneration,
            supersedesConsentVersion: $supersedesConsentVersion,
        );
    }
}
