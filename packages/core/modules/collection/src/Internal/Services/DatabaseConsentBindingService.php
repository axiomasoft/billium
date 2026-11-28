<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Services;

use Billium\Collection\Contracts\CollectionConsentGuard;
use Billium\Collection\Contracts\ConsentBindingService;
use Billium\Collection\Contracts\SavedMethodCapabilityValidator;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\ConsentBindingView;
use Billium\Collection\Data\ConsentChargeEligibilityRequest;
use Billium\Collection\Data\ConsentEligibilityVerdict;
use Billium\Collection\Data\ConsentGuardStateView;
use Billium\Collection\Data\CustomerActionReceipt;
use Billium\Collection\Data\OriginalRefundRouteView;
use Billium\Collection\Data\RecordCustomerAction;
use Billium\Collection\Data\RecordOriginalRefundRoute;
use Billium\Collection\Data\RegisterConsentBinding;
use Billium\Collection\Data\RevocationReceipt;
use Billium\Collection\Data\RevokeConsentCommand;
use Billium\Collection\Data\SavedMethodCapabilityRequirement;
use Billium\Collection\Enums\ConsentGuardStatus;
use Billium\Collection\Enums\CustomerActionStatus;
use Billium\Collection\Internal\Canonical\CanonicalJson;
use Billium\Collection\Internal\CollectionReceipt;
use Billium\Collection\Internal\Queries\DatabaseConsentQueries;
use Billium\Collection\Internal\Reducers\ConsentLifecycleReducer;
use Billium\Collection\Internal\Support\PanCvvGuard;
use Billium\Collection\Internal\Support\RowCast;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runner\LockScope;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\MerchantRevision;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use PDO;
use stdClass;

#[Internal]
final readonly class DatabaseConsentBindingService implements ConsentBindingService
{
    public const int CONSENT_GUARD_LOCK_SEGMENT = 0;

    /** @var list<string> */
    private const array COLLECTION_MODULES = ['platform', 'ledger', 'execution', 'collection'];

    /**
     * @param  Closure(): CollectionConsentGuard  $hostConsentGuardResolver
     */
    public function __construct(
        private DatabaseManager $databases,
        private OperationRunner $runner,
        private CallerContext $callers,
        private BilliumOptions $options,
        private SavedMethodCapabilityValidator $capabilityValidator,
        private Closure $hostConsentGuardResolver,
        private ConsentLifecycleReducer $reducer = new ConsentLifecycleReducer,
    ) {}

    #[Override]
    public function registerBinding(
        RegisterConsentBinding $command,
        MerchantRevision $merchant,
        CapabilitySnapshot $capabilities,
    ): ConsentBindingView {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->billingAccountRef, 'billingAccountRef');
        RowCast::bounded($command->customerRef, 'customerRef');
        RowCast::bounded($command->consentRef, 'consentRef');
        RowCast::bounded($command->merchantAccountRef, 'merchantAccountRef');
        RowCast::bounded($command->merchantConfigRevision, 'merchantConfigRevision');
        PanCvvGuard::assertOpaqueReference($command->paymentMethodRef, 'paymentMethodRef');
        PanCvvGuard::assertOpaqueReference($command->tokenLocator, 'tokenLocator');
        PanCvvGuard::assertOpaqueReference($command->sourceEvidenceRef, 'sourceEvidenceRef');
        RowCast::digest($command->sourceEvidenceDigest, 'sourceEvidenceDigest');

        [
            'purposeValues' => $purposeValues,
            'currencies' => $currencies,
            'capCurrency' => $capCurrency,
        ] = $this->reducer->validateAndNormalizeRegistration($command);

        $production = $command->scope->environment === 'live';
        foreach ($currencies as $currency) {
            foreach ($command->allowedPurposes as $purpose) {
                $this->capabilityValidator->assertMerchantSupportsSavedMethod(
                    $merchant,
                    $capabilities,
                    new SavedMethodCapabilityRequirement(
                        scope: $command->scope,
                        merchantAccountRef: $command->merchantAccountRef,
                        merchantConfigRevision: $command->merchantConfigRevision,
                        currency: $currency,
                        purpose: $purpose,
                        production: $production,
                    ),
                );
            }
        }

        $proofPayload = [
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'billingAccountRef' => $command->billingAccountRef,
            'customerRef' => $command->customerRef,
            'consentRef' => $command->consentRef,
            'consentVersion' => $command->consentVersion,
            'paymentMethodRef' => $command->paymentMethodRef,
            'tokenLocator' => $command->tokenLocator,
            'merchantAccountRef' => $merchant->merchantAccountRef,
            'merchantConfigRevision' => $merchant->revisionRef,
            'providerId' => $merchant->providerId,
            'driverId' => $merchant->driverId,
            'driverContractVersion' => $merchant->driverContractVersion,
            'capabilitySnapshotRef' => $capabilities->snapshotRef,
            'capabilityDigest' => $capabilities->digest,
            'allowedPurposes' => $purposeValues,
            'allowedCurrencies' => $currencies,
            'perChargeCapMinor' => $command->perChargeCapMinor,
            'perPeriodCapMinor' => $command->perPeriodCapMinor,
            'capCurrency' => $capCurrency,
            'capturedAt' => RowCast::formatUtc($command->capturedAt),
            'validFrom' => RowCast::formatUtc($command->validFrom),
            'validUntil' => $command->validUntil === null ? null : RowCast::formatUtc($command->validUntil),
            'sourceEvidenceRef' => $command->sourceEvidenceRef,
            'sourceEvidenceDigest' => $command->sourceEvidenceDigest,
            'supersedesConsentVersion' => $command->supersedesConsentVersion,
        ];
        $proofDigest = CanonicalJson::digest($proofPayload);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'collection.consent.register.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::NewIntent,
                modules: self::COLLECTION_MODULES,
                commandKey: $command->commandKey,
                digest: $proofDigest,
                commitKind: 'collection.consent.register',
            ),
            body: function (TransactionContext $context) use ($caller, $command, $merchant, $capabilities, $purposeValues, $currencies, $capCurrency, $proofDigest): CollectionReceipt {
                $this->runner->assertCurrent($context, 'collection');
                $db = $this->databases->connection($context->connectionName());
                $now = RowCast::formatUtc(new DateTimeImmutable('now', new DateTimeZone('UTC')));

                $existingGuard = $db->table('billium_collection_consent_guard')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('consent_ref', $command->consentRef)
                    ->first();

                if ($existingGuard !== null) {
                    $guardId = RowCast::integer($existingGuard->id, 'id');
                    $locked = $context->lock(new LockRequest(
                        LockRank::DunningCase,
                        'billium_collection_consent_guard',
                        [$guardId],
                        scope: LockScope::Seller,
                        segment: self::CONSENT_GUARD_LOCK_SEGMENT,
                    ));
                    if ($locked !== [$guardId]) {
                        throw new LockPlanChanged('Consent guard row disappeared before lock');
                    }
                    $existingGuard = $db->table('billium_collection_consent_guard')
                        ->where('id', $guardId)
                        ->first() ?? throw new LockPlanChanged('Consent guard missing after lock');
                }

                $existingBinding = $db->table('billium_collection_consent_binding')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('consent_ref', $command->consentRef)
                    ->where('consent_version', $command->consentVersion)
                    ->first();

                if ($existingBinding !== null) {
                    if (RowCast::str($existingBinding->proof_digest, 'proof_digest') !== $proofDigest) {
                        throw new ProblemException(
                            ProblemCode::IdempotencyConflict,
                            'billium.collection.consent_version_conflict',
                        );
                    }

                    return new CollectionReceipt('collection.consent.registered', [
                        'bindingId' => RowCast::integer($existingBinding->id, 'id'),
                        'consentRef' => $command->consentRef,
                        'consentVersion' => $command->consentVersion,
                    ]);
                }

                $guardView = $existingGuard === null ? null : DatabaseConsentQueries::hydrateGuard($existingGuard);
                $activeBindingView = null;
                if ($guardView !== null) {
                    $activeBindingRow = $db->table('billium_collection_consent_binding')
                        ->where('id', $guardView->activeBindingId)
                        ->first();
                    $activeBindingView = $activeBindingRow === null ? null : DatabaseConsentQueries::hydrateBinding($activeBindingRow);
                }
                $supersedesBindingId = $this->reducer->planSuperseding(
                    $command,
                    $merchant,
                    $guardView,
                    $activeBindingView,
                );

                $inserted = $db->selectOne(
                    <<<'SQL'
                    INSERT INTO public.billium_collection_consent_binding (
                        seller_ref, environment, billing_account_ref, customer_ref,
                        consent_ref, consent_version, payment_method_ref, token_locator,
                        merchant_account_ref, merchant_config_revision, provider_id, driver_id,
                        driver_contract_version, capability_snapshot_ref, capability_digest,
                        allowed_purposes, allowed_currencies, per_charge_cap_minor, per_period_cap_minor,
                        cap_currency, captured_at, valid_from, valid_until,
                        source_evidence_ref, source_evidence_digest, proof_digest,
                        supersedes_binding_id, created_at
                    ) VALUES (
                        ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?::jsonb, ?::jsonb, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?
                    )
                    ON CONFLICT (seller_ref, environment, consent_ref, consent_version) DO NOTHING
                    RETURNING id
                    SQL,
                    [
                        $caller->sellerRef,
                        $caller->environment,
                        $command->billingAccountRef,
                        $command->customerRef,
                        $command->consentRef,
                        $command->consentVersion,
                        $command->paymentMethodRef,
                        $command->tokenLocator,
                        $merchant->merchantAccountRef,
                        $merchant->revisionRef,
                        $merchant->providerId,
                        $merchant->driverId,
                        $merchant->driverContractVersion,
                        $capabilities->snapshotRef,
                        $capabilities->digest,
                        CanonicalJson::encode($purposeValues),
                        CanonicalJson::encode($currencies),
                        $command->perChargeCapMinor,
                        $command->perPeriodCapMinor,
                        $capCurrency,
                        RowCast::formatUtc($command->capturedAt),
                        RowCast::formatUtc($command->validFrom),
                        $command->validUntil === null ? null : RowCast::formatUtc($command->validUntil),
                        $command->sourceEvidenceRef,
                        $command->sourceEvidenceDigest,
                        $proofDigest,
                        $supersedesBindingId,
                        $now,
                    ],
                );
                if (! $inserted instanceof stdClass) {
                    $concurrent = $db->table('billium_collection_consent_binding')
                        ->where('seller_ref', $caller->sellerRef)
                        ->where('environment', $caller->environment)
                        ->where('consent_ref', $command->consentRef)
                        ->where('consent_version', $command->consentVersion)
                        ->first();
                    if ($concurrent === null) {
                        throw new LockPlanChanged('Concurrent consent binding insert requires retry');
                    }
                    if (RowCast::str($concurrent->proof_digest, 'proof_digest') !== $proofDigest) {
                        throw new ProblemException(
                            ProblemCode::IdempotencyConflict,
                            'billium.collection.consent_version_conflict',
                        );
                    }

                    return new CollectionReceipt('collection.consent.registered', [
                        'bindingId' => RowCast::integer($concurrent->id, 'id'),
                        'consentRef' => $command->consentRef,
                        'consentVersion' => $command->consentVersion,
                    ]);
                }
                $bindingId = RowCast::integer($inserted->id, 'id');

                if ($existingGuard === null) {
                    $guardInserted = $db->selectOne(
                        <<<'SQL'
                        INSERT INTO public.billium_collection_consent_guard (
                            seller_ref, environment, billing_account_ref, consent_ref,
                            active_binding_id, active_consent_version, guard_revision,
                            source_revision, cancellation_generation, status,
                            revoked_at, revocation_receipt_ref, revocation_reason, effective_at,
                            created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, ?,
                            ?, ?, 1,
                            ?, ?, 'active',
                            NULL, NULL, NULL, NULL,
                            ?, ?
                        )
                        ON CONFLICT (seller_ref, environment, consent_ref) DO NOTHING
                        RETURNING id
                        SQL,
                        [
                            $caller->sellerRef,
                            $caller->environment,
                            $command->billingAccountRef,
                            $command->consentRef,
                            $bindingId,
                            $command->consentVersion,
                            $command->sourceRevision,
                            $command->cancellationGeneration,
                            $now,
                            $now,
                        ],
                    );
                    if (! $guardInserted instanceof stdClass) {
                        throw new LockPlanChanged('Consent guard row created concurrently; retry under lock');
                    }
                } else {
                    $currentGuardRev = RowCast::integer($existingGuard->guard_revision, 'guard_revision');
                    $updated = $db->table('billium_collection_consent_guard')
                        ->where('id', RowCast::integer($existingGuard->id, 'id'))
                        ->where('guard_revision', $currentGuardRev)
                        ->update([
                            'active_binding_id' => $bindingId,
                            'active_consent_version' => $command->consentVersion,
                            'guard_revision' => $currentGuardRev + 1,
                            'source_revision' => $command->sourceRevision,
                            'cancellation_generation' => $command->cancellationGeneration,
                            'updated_at' => $now,
                        ]);
                    if ($updated !== 1) {
                        throw new LockPlanChanged('Consent guard revision changed concurrently');
                    }
                }

                return new CollectionReceipt('collection.consent.registered', [
                    'bindingId' => $bindingId,
                    'consentRef' => $command->consentRef,
                    'consentVersion' => $command->consentVersion,
                ]);
            },
        )->orThrow();

        $bindingId = RowCast::integer($outcome->body['bindingId'] ?? null, 'bindingId');
        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_consent_binding')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('id', $bindingId)
            ->first() ?? throw new LogicException('Registered consent binding vanished');

        return DatabaseConsentQueries::hydrateBinding($row);
    }

    #[Override]
    public function revokeOrCancel(RevokeConsentCommand $command): RevocationReceipt
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->consentRef, 'consentRef');
        RowCast::bounded($command->tombstoneRef, 'tombstoneRef');
        RowCast::bounded($command->reason, 'reason', 500);
        RowCast::digest($command->evidenceDigest, 'evidenceDigest');

        if ($command->sourceRevision < 0 || $command->cancellationGeneration < 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'sourceRevision and cancellationGeneration must be nonnegative');
        }
        if ($command->targetAuthorizationRef !== null) {
            RowCast::bounded($command->targetAuthorizationRef, 'targetAuthorizationRef');
        }
        if ($command->targetBusinessIntentRef !== null) {
            RowCast::bounded($command->targetBusinessIntentRef, 'targetBusinessIntentRef');
        }
        if ($command->expectedGuardRevision !== null && $command->expectedGuardRevision < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'expectedGuardRevision must be >= 1');
        }

        $digest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'consentRef' => $command->consentRef,
            'tombstoneRef' => $command->tombstoneRef,
            'tombstoneKind' => $command->tombstoneKind->value,
            'sourceRevision' => $command->sourceRevision,
            'cancellationGeneration' => $command->cancellationGeneration,
            'reason' => $command->reason,
            'evidenceDigest' => $command->evidenceDigest,
            'targetAuthorizationRef' => $command->targetAuthorizationRef,
            'targetBusinessIntentRef' => $command->targetBusinessIntentRef,
            'expectedGuardRevision' => $command->expectedGuardRevision,
        ]);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'collection.consent.revoke.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::FrozenIntentCompletion,
                modules: self::COLLECTION_MODULES,
                commandKey: $command->commandKey,
                digest: $digest,
                commitKind: 'collection.consent.revoke',
            ),
            body: function (TransactionContext $context) use ($caller, $command): CollectionReceipt {
                $this->runner->assertCurrent($context, 'collection');
                $db = $this->databases->connection($context->connectionName());

                $guard = $db->table('billium_collection_consent_guard')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('consent_ref', $command->consentRef)
                    ->first();

                if ($guard === null) {
                    throw new ProblemException(
                        ProblemCode::ReferenceUnknown,
                        'billium.collection.consent_not_found',
                    );
                }

                $guardId = RowCast::integer($guard->id, 'id');
                $locked = $context->lock(new LockRequest(
                    LockRank::DunningCase,
                    'billium_collection_consent_guard',
                    [$guardId],
                    scope: LockScope::Seller,
                    segment: self::CONSENT_GUARD_LOCK_SEGMENT,
                ));
                if ($locked !== [$guardId]) {
                    throw new LockPlanChanged('Consent guard row disappeared before lock');
                }
                $guard = $db->table('billium_collection_consent_guard')
                    ->where('id', $guardId)
                    ->first() ?? throw new LockPlanChanged('Consent guard missing after lock');

                $existingTombstone = $db->table('billium_collection_guard_tombstone')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('tombstone_ref', $command->tombstoneRef)
                    ->first();

                if ($existingTombstone !== null) {
                    if (
                        RowCast::str($existingTombstone->consent_ref, 'consent_ref') !== $command->consentRef
                        || RowCast::str($existingTombstone->tombstone_kind, 'tombstone_kind') !== $command->tombstoneKind->value
                        || RowCast::str($existingTombstone->evidence_digest, 'evidence_digest') !== $command->evidenceDigest
                    ) {
                        throw new ProblemException(
                            ProblemCode::IdempotencyConflict,
                            'billium.collection.tombstone_conflict',
                        );
                    }

                    return new CollectionReceipt('collection.consent.revoked', [
                        'tombstoneRef' => $command->tombstoneRef,
                        'guardStatus' => RowCast::str($guard->status, 'status'),
                    ]);
                }

                $guardView = DatabaseConsentQueries::hydrateGuard($guard);
                $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $now = RowCast::formatUtc($nowUtc);
                $decision = $this->reducer->reduceRevocation($command, $guardView, $nowUtc);

                $updated = $db->table('billium_collection_consent_guard')
                    ->where('id', $guardId)
                    ->where('guard_revision', $guardView->guardRevision)
                    ->update([
                        'guard_revision' => $decision->newGuardRevision,
                        'source_revision' => $command->sourceRevision,
                        'cancellation_generation' => $command->cancellationGeneration,
                        'status' => $decision->targetStatus->value,
                        'revoked_at' => RowCast::formatUtc($decision->revokedAt),
                        'revocation_receipt_ref' => $decision->revocationReceiptRef,
                        'revocation_reason' => $decision->revocationReason,
                        'effective_at' => RowCast::formatUtc($decision->effectiveAt),
                        'updated_at' => $now,
                    ]);
                if ($updated !== 1) {
                    throw new LockPlanChanged('Consent guard updated concurrently');
                }

                $activeVersion = $guardView->activeConsentVersion;
                $db->insert(
                    <<<'SQL'
                    INSERT INTO public.billium_collection_guard_tombstone (
                        seller_ref, environment, consent_guard_id, consent_ref, consent_version,
                        tombstone_ref, tombstone_kind, target_authorization_ref, target_business_intent_ref,
                        guard_revision, source_revision, cancellation_generation,
                        reason, evidence_digest, effective_at, recorded_at
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?
                    )
                    SQL,
                    [
                        $caller->sellerRef,
                        $caller->environment,
                        $guardId,
                        $command->consentRef,
                        $activeVersion,
                        $command->tombstoneRef,
                        $command->tombstoneKind->value,
                        $command->targetAuthorizationRef,
                        $command->targetBusinessIntentRef,
                        $decision->newGuardRevision,
                        $command->sourceRevision,
                        $command->cancellationGeneration,
                        $command->reason,
                        $command->evidenceDigest,
                        $now,
                        $now,
                    ],
                );

                return new CollectionReceipt('collection.consent.revoked', [
                    'tombstoneRef' => $command->tombstoneRef,
                    'guardStatus' => $decision->targetStatus->value,
                ]);
            },
        )->orThrow();

        $tombstoneRef = RowCast::str($outcome->body['tombstoneRef'] ?? null, 'tombstoneRef');
        $status = ConsentGuardStatus::from(RowCast::str($outcome->body['guardStatus'] ?? null, 'guardStatus'));
        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_guard_tombstone')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('tombstone_ref', $tombstoneRef)
            ->first() ?? throw new LogicException('Recorded tombstone vanished');

        return DatabaseConsentQueries::hydrateTombstone($row, $status);
    }

    #[Override]
    public function syncHostAuthority(
        CollectionScope $scope,
        string $billingAccountRef,
        string $consentRef,
        string $commandKey,
        string $tombstoneRef,
    ): ?RevocationReceipt {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($billingAccountRef, 'billingAccountRef');
        RowCast::bounded($consentRef, 'consentRef');
        RowCast::bounded($commandKey, 'commandKey');
        RowCast::bounded($tombstoneRef, 'tombstoneRef');

        $this->assertOutsideTransaction();

        $snapshot = ($this->hostConsentGuardResolver)()->currentAuthority($scope, $billingAccountRef, $consentRef);

        $command = $this->reducer->decideHostAuthoritySync(
            $snapshot,
            $scope,
            $billingAccountRef,
            $consentRef,
            $commandKey,
            $tombstoneRef,
        );
        if ($command === null) {
            return null;
        }

        return $this->revokeOrCancel($command);
    }

    #[Override]
    public function evaluateChargeEligibility(
        ConsentChargeEligibilityRequest $request,
        ?TransactionContext $context = null,
    ): ConsentEligibilityVerdict {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $request->scope);
        RowCast::bounded($request->billingAccountRef, 'billingAccountRef');
        RowCast::bounded($request->consentRef, 'consentRef');
        RowCast::bounded($request->merchantAccountRef, 'merchantAccountRef');
        RowCast::bounded($request->merchantConfigRevision, 'merchantConfigRevision');
        RowCast::currency($request->currency);

        if ($context !== null) {
            $this->runner->assertCurrent($context, 'collection');
        }
        $connectionName = $context?->connectionName() ?? $this->options->connection;
        $db = $this->databases->connection($connectionName);

        $bindingRow = $db->table('billium_collection_consent_binding')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('consent_ref', $request->consentRef)
            ->where('consent_version', $request->consentVersion)
            ->first();

        $binding = $bindingRow === null ? null : DatabaseConsentQueries::hydrateBinding($bindingRow);
        $guardRow = $binding === null
            ? null
            : $db->table('billium_collection_consent_guard')
                ->where('seller_ref', $caller->sellerRef)
                ->where('environment', $caller->environment)
                ->where('consent_ref', $request->consentRef)
                ->first();
        $guard = $guardRow === null ? null : DatabaseConsentQueries::hydrateGuard($guardRow);

        $authorizationTombstoned = false;
        if ($binding !== null && $guard !== null && $request->authorizationRef !== null) {
            $authorizationTombstoned = $db->table('billium_collection_guard_tombstone')
                ->where('seller_ref', $caller->sellerRef)
                ->where('environment', $caller->environment)
                ->where('target_authorization_ref', $request->authorizationRef)
                ->exists();
        }
        $businessIntentTombstoned = false;
        if ($binding !== null && $guard !== null && $request->businessPaymentIntentRef !== null) {
            $businessIntentTombstoned = $db->table('billium_collection_guard_tombstone')
                ->where('seller_ref', $caller->sellerRef)
                ->where('environment', $caller->environment)
                ->where('target_business_intent_ref', $request->businessPaymentIntentRef)
                ->exists();
        }

        return $this->reducer->evaluateEligibility(
            $request,
            $binding,
            $guard,
            $authorizationTombstoned,
            $businessIntentTombstoned,
        );
    }

    #[Override]
    public function lockGuard(
        TransactionContext $context,
        CollectionScope $scope,
        string $consentRef,
    ): ConsentGuardStateView {
        $this->runner->assertCurrent($context, 'collection');
        RowCast::assertScope($context->caller(), $scope);
        RowCast::bounded($consentRef, 'consentRef');

        $db = $this->databases->connection($context->connectionName());
        $existing = $db->table('billium_collection_consent_guard')
            ->where('seller_ref', $scope->sellerRef)
            ->where('environment', $scope->environment)
            ->where('consent_ref', $consentRef)
            ->first();

        if ($existing === null) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.collection.consent_not_found');
        }

        $guardId = RowCast::integer($existing->id, 'id');
        $locked = $context->lock(new LockRequest(
            LockRank::DunningCase,
            'billium_collection_consent_guard',
            [$guardId],
            scope: LockScope::Seller,
            segment: self::CONSENT_GUARD_LOCK_SEGMENT,
        ));
        if ($locked !== [$guardId]) {
            throw new LockPlanChanged('Consent guard row disappeared during lock');
        }

        $refreshed = $db->table('billium_collection_consent_guard')
            ->where('id', $guardId)
            ->first() ?? throw new LockPlanChanged('Consent guard vanished after lock');

        return DatabaseConsentQueries::hydrateGuard($refreshed);
    }

    #[Override]
    public function recordOriginalRefundRoute(RecordOriginalRefundRoute $command): OriginalRefundRouteView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->operationRef, 'operationRef');
        RowCast::bounded($command->billingAccountRef, 'billingAccountRef');
        RowCast::bounded($command->consentRef, 'consentRef');

        $digest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'operationRef' => $command->operationRef,
            'billingAccountRef' => $command->billingAccountRef,
            'consentRef' => $command->consentRef,
            'consentVersion' => $command->consentVersion,
        ]);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'collection.refund_route.record.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::FrozenIntentCompletion,
                modules: self::COLLECTION_MODULES,
                commandKey: $command->commandKey,
                digest: $digest,
                commitKind: 'collection.refund_route.record',
            ),
            body: function (TransactionContext $context) use ($caller, $command): CollectionReceipt {
                $this->runner->assertCurrent($context, 'collection');
                $db = $this->databases->connection($context->connectionName());

                $bindingRow = $db->table('billium_collection_consent_binding')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('consent_ref', $command->consentRef)
                    ->where('consent_version', $command->consentVersion)
                    ->first();

                if ($bindingRow === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.collection.consent_not_found');
                }
                $binding = DatabaseConsentQueries::hydrateBinding($bindingRow);
                $this->reducer->assertRefundRouteBinding($binding, $command);

                $routeDigest = CanonicalJson::digest([
                    'sellerRef' => $caller->sellerRef,
                    'environment' => $caller->environment,
                    'operationRef' => $command->operationRef,
                    'originalBindingId' => $binding->id,
                    'originalConsentRef' => $binding->consentRef,
                    'originalConsentVersion' => $binding->consentVersion,
                    'originalMerchantAccountRef' => $binding->merchantAccountRef,
                    'originalMerchantConfigRevision' => $binding->merchantConfigRevision,
                    'originalProviderId' => $binding->providerId,
                    'originalDriverId' => $binding->driverId,
                    'originalDriverContractVersion' => $binding->driverContractVersion,
                    'originalPaymentMethodRef' => $binding->paymentMethodRef,
                ]);

                $now = RowCast::formatUtc(new DateTimeImmutable('now', new DateTimeZone('UTC')));
                $db->table('billium_collection_refund_route')->insertOrIgnore([
                    'seller_ref' => $caller->sellerRef,
                    'environment' => $caller->environment,
                    'operation_ref' => $command->operationRef,
                    'billing_account_ref' => $binding->billingAccountRef,
                    'original_binding_id' => $binding->id,
                    'original_consent_ref' => $binding->consentRef,
                    'original_consent_version' => $binding->consentVersion,
                    'original_merchant_account_ref' => $binding->merchantAccountRef,
                    'original_merchant_config_revision' => $binding->merchantConfigRevision,
                    'original_provider_id' => $binding->providerId,
                    'original_driver_id' => $binding->driverId,
                    'original_driver_contract_version' => $binding->driverContractVersion,
                    'original_payment_method_ref' => $binding->paymentMethodRef,
                    'route_digest' => $routeDigest,
                    'recorded_at' => $now,
                ]);

                $existing = $db->table('billium_collection_refund_route')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('operation_ref', $command->operationRef)
                    ->first() ?? throw new LogicException('Recorded refund route vanished');

                if (RowCast::str($existing->route_digest, 'route_digest') !== $routeDigest) {
                    throw new ProblemException(
                        ProblemCode::IdempotencyConflict,
                        'billium.collection.refund_route_immutable_conflict',
                    );
                }

                return new CollectionReceipt('collection.refund_route.recorded', [
                    'routeId' => RowCast::integer($existing->id, 'id'),
                    'operationRef' => $command->operationRef,
                ]);
            },
        )->orThrow();

        $routeId = RowCast::integer($outcome->body['routeId'] ?? null, 'routeId');
        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_refund_route')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('id', $routeId)
            ->first() ?? throw new LogicException('Recorded refund route missing');

        return DatabaseConsentQueries::hydrateRefundRoute($row);
    }

    #[Override]
    public function assertRefundRouteMatchesOriginal(
        CollectionScope $scope,
        string $operationRef,
        string $merchantAccountRef,
        string $merchantConfigRevision,
    ): OriginalRefundRouteView {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($operationRef, 'operationRef');
        RowCast::bounded($merchantAccountRef, 'merchantAccountRef');
        RowCast::bounded($merchantConfigRevision, 'merchantConfigRevision');

        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_refund_route')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('operation_ref', $operationRef)
            ->first();

        if ($row === null) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.collection.refund_route_not_found');
        }

        $route = DatabaseConsentQueries::hydrateRefundRoute($row);
        $this->reducer->assertRefundRouteMatchesOriginal($route, $merchantAccountRef, $merchantConfigRevision);

        return $route;
    }

    #[Override]
    public function recordCustomerAction(RecordCustomerAction $command): CustomerActionReceipt
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->actionRef, 'actionRef');
        RowCast::bounded($command->billingAccountRef, 'billingAccountRef');
        RowCast::bounded($command->consentRef, 'consentRef');
        RowCast::bounded($command->businessPaymentIntentRef, 'businessPaymentIntentRef');
        PanCvvGuard::assertOpaqueReference($command->challengeRef, 'challengeRef');
        PanCvvGuard::assertCleanPayload($command->actionPayload);

        if ($command->operationRef !== null) {
            RowCast::bounded($command->operationRef, 'operationRef');
        }

        $receiptDigest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'actionRef' => $command->actionRef,
            'billingAccountRef' => $command->billingAccountRef,
            'consentRef' => $command->consentRef,
            'consentVersion' => $command->consentVersion,
            'operationRef' => $command->operationRef,
            'businessPaymentIntentRef' => $command->businessPaymentIntentRef,
            'actionType' => $command->actionType->value,
            'status' => CustomerActionStatus::RequiresAction->value,
            'challengeRef' => $command->challengeRef,
            'actionPayload' => $command->actionPayload,
            'expiresAt' => RowCast::formatUtc($command->expiresAt),
        ]);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'collection.customer_action.record.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::FrozenIntentCompletion,
                modules: self::COLLECTION_MODULES,
                commandKey: $command->commandKey,
                digest: $receiptDigest,
                commitKind: 'collection.customer_action.record',
            ),
            body: function (TransactionContext $context) use ($caller, $command, $receiptDigest): CollectionReceipt {
                $this->runner->assertCurrent($context, 'collection');
                $db = $this->databases->connection($context->connectionName());

                $bindingRow = $db->table('billium_collection_consent_binding')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('consent_ref', $command->consentRef)
                    ->where('consent_version', $command->consentVersion)
                    ->first();

                if ($bindingRow === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.collection.consent_not_found');
                }
                $binding = DatabaseConsentQueries::hydrateBinding($bindingRow);
                $this->reducer->assertCustomerActionBinding($binding, $command);

                $now = RowCast::formatUtc(new DateTimeImmutable('now', new DateTimeZone('UTC')));
                $db->table('billium_collection_customer_action')->insertOrIgnore([
                    'seller_ref' => $caller->sellerRef,
                    'environment' => $caller->environment,
                    'action_ref' => $command->actionRef,
                    'billing_account_ref' => $command->billingAccountRef,
                    'consent_binding_id' => RowCast::integer($bindingRow->id, 'id'),
                    'consent_ref' => $command->consentRef,
                    'consent_version' => $command->consentVersion,
                    'operation_ref' => $command->operationRef,
                    'business_payment_intent_ref' => $command->businessPaymentIntentRef,
                    'action_type' => $command->actionType->value,
                    'status' => CustomerActionStatus::RequiresAction->value,
                    'challenge_ref' => $command->challengeRef,
                    'action_payload' => CanonicalJson::encodeObject($command->actionPayload),
                    'expires_at' => RowCast::formatUtc($command->expiresAt),
                    'receipt_digest' => $receiptDigest,
                    'recorded_at' => $now,
                ]);

                $existing = $db->table('billium_collection_customer_action')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('action_ref', $command->actionRef)
                    ->first();
                if ($existing === null) {
                    $identity = $db->table('billium_collection_customer_action')
                        ->where('seller_ref', $caller->sellerRef)
                        ->where('environment', $caller->environment)
                        ->where('business_payment_intent_ref', $command->businessPaymentIntentRef)
                        ->where('challenge_ref', $command->challengeRef)
                        ->first();
                    if ($identity !== null) {
                        throw new ProblemException(
                            ProblemCode::IdempotencyConflict,
                            'billium.collection.customer_action_conflict',
                        );
                    }
                    throw new LogicException('Customer action receipt vanished');
                }

                if (RowCast::str($existing->receipt_digest, 'receipt_digest') !== $receiptDigest) {
                    throw new ProblemException(
                        ProblemCode::IdempotencyConflict,
                        'billium.collection.customer_action_conflict',
                    );
                }

                return new CollectionReceipt('collection.customer_action.recorded', [
                    'actionId' => RowCast::integer($existing->id, 'id'),
                    'actionRef' => $command->actionRef,
                ]);
            },
        )->orThrow();

        $actionId = RowCast::integer($outcome->body['actionId'] ?? null, 'actionId');
        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_customer_action')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('id', $actionId)
            ->first() ?? throw new LogicException('Customer action receipt missing');

        return DatabaseConsentQueries::hydrateCustomerAction($row);
    }

    private function assertOutsideTransaction(): void
    {
        foreach ($this->databases->getConnections() as $connection) {
            $writePdo = $connection->getRawPdo();
            $readPdo = $connection->getRawReadPdo();
            if (
                $connection->transactionLevel() !== 0
                || ($writePdo instanceof PDO && $writePdo->inTransaction())
                || ($readPdo instanceof PDO && $readPdo->inTransaction())
            ) {
                throw new LogicException('CollectionConsentGuard host authority lookup must run outside every database transaction');
            }
        }
    }
}
