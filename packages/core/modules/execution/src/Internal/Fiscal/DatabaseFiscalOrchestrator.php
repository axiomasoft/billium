<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Fiscal;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\Fiscal\FiscalDriver;
use Billium\Execution\Contracts\Fiscal\FiscalDrivers;
use Billium\Execution\Contracts\Fiscal\FiscalOrchestrator;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\Fiscal\FiscalActualEffect;
use Billium\Execution\Data\Fiscal\FiscalCallbackEnvelope;
use Billium\Execution\Data\Fiscal\FiscalCorrectionRequest;
use Billium\Execution\Data\Fiscal\FiscalDriverResult;
use Billium\Execution\Data\Fiscal\FiscalEvidenceRecord;
use Billium\Execution\Data\Fiscal\FiscalExternalOutcome;
use Billium\Execution\Data\Fiscal\FiscalIntentRecord;
use Billium\Execution\Data\Fiscal\FiscalLookupRequest;
use Billium\Execution\Data\Fiscal\FiscalPolicyConfig;
use Billium\Execution\Data\Fiscal\FiscalPolicyRecord;
use Billium\Execution\Data\Fiscal\FiscalProjectionInput;
use Billium\Execution\Data\Fiscal\FiscalProjectionRecord;
use Billium\Execution\Data\Fiscal\FiscalRegisterRequest;
use Billium\Execution\Enums\Fiscal\FiscalCallbackTrustStrategy;
use Billium\Execution\Enums\Fiscal\FiscalIntentState;
use Billium\Execution\Enums\Fiscal\FiscalOperationKind;
use Billium\Execution\Enums\Fiscal\FiscalReceiptKind;
use Billium\Execution\Enums\Fiscal\FiscalRoute;
use Billium\Execution\Internal\Dispatch\TransportBoundary;
use Billium\Execution\Internal\Merchants\ContinuityMerchantCredentials;
use Billium\Platform\Contracts\Installation\Installation;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;
use Throwable;

#[Internal]
final readonly class DatabaseFiscalOrchestrator implements FiscalOrchestrator
{
    /** @param Closure(): FiscalDrivers $drivers */
    public function __construct(
        private DatabaseManager $databases,
        private OperationRunner $runner,
        private CallerContext $callers,
        private Installation $installation,
        private ExecutionStore $store,
        private ContinuityMerchantCredentials $credentials,
        private TransportBoundary $boundary,
        private Closure $drivers,
        private ActualEffectExtractor $extractor,
        private BilliumOptions $options,
    ) {}

    public function configurePolicy(FiscalPolicyConfig $config): FiscalPolicyRecord
    {
        $this->boundary->assertOutsideTransaction();
        $this->assertScope($config->scope);

        if ($config->fiscalRoute->isDispatchable()) {
            $driverId = $config->driverId ?? throw new ProblemException(ProblemCode::ValidationInvalid, 'driverId is required');
            $driverContractVersion = $config->driverContractVersion ?? throw new ProblemException(ProblemCode::ValidationInvalid, 'driverContractVersion is required');
            $driver = $this->resolveDriver($driverId, $driverContractVersion);
            if (! $driver->capabilities()->supportsRoute($config->fiscalRoute)) {
                throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Fiscal driver does not support configured fiscal route');
            }
            if ($config->callbackTrustStrategy !== null && ! $driver->capabilities()->supportsTrustStrategy($config->callbackTrustStrategy)) {
                throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Fiscal driver does not support configured callback trust strategy');
            }
        }

        $scope = $config->scope;
        $spec = new OperationSpec(
            'execution.fiscal_configure_policy',
            $scope->sellerRef,
            $scope->environment,
            FinancialMode::Administrative,
            ModuleOperationClass::HistoricalRead,
            ['execution', 'platform'],
            financialScope: new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace),
        );

        $record = null;
        $this->runner->run($spec, function (TransactionContext $ctx) use ($config, $scope, &$record): FiscalReceipt {
            $db = $this->connection($ctx);
            $latestVersion = $db->table('billium_fiscal_policy')
                ->where('installation_ref', $scope->installationRef)
                ->where('seller_ref', $scope->sellerRef)
                ->where('environment', $scope->environment)
                ->where('execution_profile', $config->executionProfile)
                ->where('operation_kind', $config->operationKind->value)
                ->max('policy_version');
            $nextVersion = $latestVersion === null ? 1 : FiscalJson::integer($latestVersion) + 1;

            $digest = FiscalJson::digest([
                'schema' => 'billium.fiscal.policy.v1',
                'installationRef' => $scope->installationRef,
                'sellerRef' => $scope->sellerRef,
                'environment' => $scope->environment,
                'executionProfile' => $config->executionProfile,
                'operationKind' => $config->operationKind->value,
                'policyVersion' => $nextVersion,
                'receiptRequired' => $config->receiptRequired,
                'fiscalRoute' => $config->fiscalRoute->value,
                'driverId' => $config->driverId,
                'driverContractVersion' => $config->driverContractVersion,
                'callbackTrustStrategy' => $config->callbackTrustStrategy?->value,
                'metadata' => $config->metadata,
            ]);

            $id = FiscalJson::integer($db->table('billium_fiscal_policy')->insertGetId([
                'installation_ref' => $scope->installationRef,
                'seller_ref' => $scope->sellerRef,
                'environment' => $scope->environment,
                'execution_profile' => $config->executionProfile,
                'operation_kind' => $config->operationKind->value,
                'policy_version' => $nextVersion,
                'receipt_required' => $config->receiptRequired,
                'fiscal_route' => $config->fiscalRoute->value,
                'driver_id' => $config->driverId,
                'driver_contract_version' => $config->driverContractVersion,
                'callback_trust_strategy' => $config->callbackTrustStrategy?->value,
                'policy_digest' => $digest,
                'metadata' => FiscalJson::encodeObject($config->metadata),
                'created_at' => FiscalJson::formatDbTimestamp(new DateTimeImmutable),
            ]));

            $row = $db->table('billium_fiscal_policy')->where('id', $id)->first();
            if ($row === null) {
                throw new LogicException('Inserted fiscal policy row disappeared');
            }
            $record = FiscalStorage::hydratePolicy($scope, $row);

            return new FiscalReceipt('execution.fiscal_policy_configured', [
                'policyVersion' => $nextVersion,
                'policyDigest' => $digest,
                'fiscalRoute' => $config->fiscalRoute->value,
            ]);
        })->orThrow();

        return $record ?? throw new LogicException('Fiscal policy write returned no record');
    }

    public function activePolicy(ExecutionScope $scope, string $executionProfile, FiscalOperationKind $operationKind): ?FiscalPolicyRecord
    {
        $this->assertScope($scope);
        $row = $this->connection()->table('billium_fiscal_policy')
            ->where('installation_ref', $scope->installationRef)
            ->where('seller_ref', $scope->sellerRef)
            ->where('environment', $scope->environment)
            ->where('execution_profile', $executionProfile)
            ->where('operation_kind', $operationKind->value)
            ->orderByDesc('policy_version')
            ->orderByDesc('id')
            ->first();

        return $row === null ? null : FiscalStorage::hydratePolicy($scope, $row);
    }

    public function prepareIntent(FiscalActualEffect $effect): ?FiscalIntentRecord
    {
        $this->boundary->assertOutsideTransaction();
        $this->assertScope($effect->scope);

        if ($effect->executionProfile === 'embedded-commerz') {
            throw new ProblemException(
                ProblemCode::CapabilityUnsupported,
                'embedded-commerz is projection-only and cannot prepare or dispatch fiscal intents',
            );
        }
        if (! $effect->confirmedActual) {
            return null;
        }

        $policy = $this->activePolicy($effect->scope, $effect->executionProfile, $effect->operationKind);
        if ($policy === null || ! $policy->receiptRequired || $policy->fiscalRoute === FiscalRoute::None) {
            return null;
        }
        if (! $policy->fiscalRoute->isDispatchable()) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Active fiscal policy route is not dispatchable');
        }

        $driverId = $policy->driverId ?? throw new LogicException('Dispatchable fiscal policy missing driverId');
        $driverContractVersion = $policy->driverContractVersion ?? throw new LogicException('Dispatchable fiscal policy missing driverContractVersion');
        $driver = $this->resolveDriver($driverId, $driverContractVersion);
        if (! $driver->capabilities()->supportsRoute($policy->fiscalRoute)) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Resolved fiscal driver does not support policy route');
        }
        $trustStrategy = $policy->callbackTrustStrategy ?? FiscalCallbackTrustStrategy::DriverVerified;
        if (! $driver->capabilities()->supportsTrustStrategy($trustStrategy)) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Resolved fiscal driver does not support policy callback trust strategy');
        }

        $scope = $effect->scope;
        $externalId = FiscalStorage::externalId($scope, $effect->economicEffectRef, $effect->receiptKind, 0);
        $intentRef = FiscalStorage::intentRef($scope, $effect->economicEffectRef, $effect->receiptKind, 0);
        $payloadSnapshot = [
            'economicEffectRef' => $effect->economicEffectRef,
            'rootOperationRef' => $effect->rootOperationRef,
            'operationRef' => $effect->operationRef,
            'operationKind' => $effect->operationKind->value,
            'receiptKind' => $effect->receiptKind->value,
            'correctionRevision' => 0,
            'amount' => $effect->actualAmount->toWire(),
            'receiptPayload' => $effect->receiptPayload,
        ];
        $payloadDigest = FiscalJson::digest($payloadSnapshot);

        $spec = new OperationSpec(
            'execution.fiscal_prepare_intent',
            $scope->sellerRef,
            $scope->environment,
            $this->financialMode($scope),
            ModuleOperationClass::FrozenIntentCompletion,
            ['execution', 'platform'],
            financialScope: new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, $effect->executionProfile),
        );

        $record = null;
        $this->runner->run($spec, function (TransactionContext $ctx) use (
            $scope,
            $effect,
            $policy,
            $driverId,
            $driverContractVersion,
            $trustStrategy,
            $externalId,
            $intentRef,
            $payloadSnapshot,
            $payloadDigest,
            &$record,
        ): FiscalReceipt {
            $db = $this->connection($ctx);
            $now = FiscalJson::formatDbTimestamp(new DateTimeImmutable);
            $inserted = $db->table('billium_fiscal_intent')->insertOrIgnore([
                'installation_ref' => $scope->installationRef,
                'seller_ref' => $scope->sellerRef,
                'environment' => $scope->environment,
                'intent_ref' => $intentRef,
                'execution_profile' => $effect->executionProfile,
                'economic_effect_ref' => $effect->economicEffectRef,
                'root_operation_ref' => $effect->rootOperationRef,
                'operation_ref' => $effect->operationRef,
                'receipt_kind' => $effect->receiptKind->value,
                'correction_revision' => 0,
                'parent_fiscal_intent_ref' => null,
                'external_id' => $externalId,
                'fiscal_route' => $policy->fiscalRoute->value,
                'driver_id' => $driverId,
                'driver_contract_version' => $driverContractVersion,
                'callback_trust_strategy' => $trustStrategy->value,
                'merchant_revision_ref' => $effect->merchantRevisionRef,
                'merchant_account_ref' => $effect->merchantAccountRef,
                'provider_evidence_ref' => $effect->providerEvidenceRef,
                'policy_version' => $policy->policyVersion,
                'policy_digest' => $policy->policyDigest,
                'amount_minor' => $effect->actualAmount->minor,
                'currency' => $effect->actualAmount->currency->code,
                'currency_exponent' => $effect->actualAmount->currency->scale,
                'exceptional' => $effect->exceptional,
                'exposure_provenance' => FiscalJson::encodeObject($effect->exposureProvenance),
                'payload_snapshot' => FiscalJson::encodeObject($payloadSnapshot),
                'payload_digest' => $payloadDigest,
                'state' => FiscalIntentState::Prepared->value,
                'lookup_attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = $db->table('billium_fiscal_intent')
                ->where('installation_ref', $scope->installationRef)
                ->where('seller_ref', $scope->sellerRef)
                ->where('environment', $scope->environment)
                ->where('economic_effect_ref', $effect->economicEffectRef)
                ->where('receipt_kind', $effect->receiptKind->value)
                ->where('correction_revision', 0)
                ->first();
            if ($row === null) {
                throw new LogicException('Prepared fiscal intent row disappeared');
            }
            $hydrated = FiscalStorage::hydrateIntent($scope, $row);
            if (
                $inserted === 0
                && (
                    $hydrated->amount->minor !== $effect->actualAmount->minor
                    || $hydrated->amount->currency->code !== $effect->actualAmount->currency->code
                    || $hydrated->operationRef !== $effect->operationRef
                    || $hydrated->rootOperationRef !== $effect->rootOperationRef
                    || $hydrated->merchantRevisionRef !== $effect->merchantRevisionRef
                )
            ) {
                throw new ProblemException(
                    ProblemCode::IdempotencyConflict,
                    'Conflicting actual effect semantics for existing fiscal intent key',
                );
            }
            $record = $hydrated;

            return new FiscalReceipt('execution.fiscal_intent_prepared', [
                'intentRef' => $hydrated->intentRef,
                'externalId' => $hydrated->externalId,
                'replayed' => $inserted === 0,
            ]);
        })->orThrow();

        return $record;
    }

    /** @return list<FiscalIntentRecord> */
    public function syncActualEffectsForRoot(ExecutionScope $scope, string $rootOperationRef, string $executionProfile): array
    {
        $this->boundary->assertOutsideTransaction();
        $this->assertScope($scope);

        if ($executionProfile === 'embedded-commerz') {
            throw new ProblemException(
                ProblemCode::CapabilityUnsupported,
                'embedded-commerz is projection-only and cannot create fiscal intents',
            );
        }

        $effects = $this->extractor->extractForRoot($scope, $rootOperationRef, $executionProfile);
        $intents = [];
        foreach ($effects as $effect) {
            $prepared = $this->prepareIntent($effect);
            if ($prepared !== null) {
                $intents[] = $prepared;
            }
        }

        return $intents;
    }

    public function dispatchIntent(ExecutionScope $scope, string $intentRef, ?DateTimeImmutable $now = null): FiscalIntentRecord
    {
        $this->boundary->assertOutsideTransaction();
        $this->assertScope($scope);
        $now ??= new DateTimeImmutable;

        $intent = $this->findIntent($scope, $intentRef);
        if ($intent === null) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown fiscal intent reference');
        }
        if ($intent->executionProfile === 'embedded-commerz') {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'embedded-commerz cannot dispatch fiscal intents');
        }
        if ($intent->state->isTerminal() || $intent->state === FiscalIntentState::Intervention) {
            return $intent;
        }
        if ($intent->state->allowsLookup()) {
            return $this->lookupIntent($scope, $intentRef, $now);
        }

        $merchant = $this->store->merchantRevision($scope, $intent->merchantRevisionRef);
        $driver = $this->resolveDriver($intent->driverId, $intent->driverContractVersion);
        if (! $driver->capabilities()->supportsRoute($intent->fiscalRoute)) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Fiscal driver no longer supports frozen fiscal route');
        }

        $credentials = $this->credentials->resolve($merchant, $this->registerCredentialPurpose($intent));

        // Tx1: atomic CAS transition prepared -> submitted BEFORE any external driver call.
        $transitioned = false;
        $submitted = null;
        $tx1Spec = new OperationSpec(
            'execution.fiscal_start_dispatch',
            $scope->sellerRef,
            $scope->environment,
            $this->financialMode($scope),
            ModuleOperationClass::FrozenIntentCompletion,
            ['execution', 'platform'],
            financialScope: new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, $intent->executionProfile),
        );
        $this->runner->run($tx1Spec, function (TransactionContext $ctx) use ($scope, $intentRef, $now, &$transitioned, &$submitted): FiscalReceipt {
            $db = $this->connection($ctx);
            $affected = $db->table('billium_fiscal_intent')
                ->where('installation_ref', $scope->installationRef)
                ->where('seller_ref', $scope->sellerRef)
                ->where('environment', $scope->environment)
                ->where('intent_ref', $intentRef)
                ->where('state', FiscalIntentState::Prepared->value)
                ->update([
                    'state' => FiscalIntentState::Submitted->value,
                    'next_lookup_at' => FiscalJson::formatDbTimestamp($now->modify('+60 seconds')),
                    'updated_at' => FiscalJson::formatDbTimestamp($now),
                ]);

            $fresh = $db->table('billium_fiscal_intent')
                ->where('installation_ref', $scope->installationRef)
                ->where('seller_ref', $scope->sellerRef)
                ->where('environment', $scope->environment)
                ->where('intent_ref', $intentRef)
                ->first();
            if ($fresh === null) {
                throw new ProblemException(ProblemCode::ReferenceUnknown, 'Fiscal intent disappeared before dispatch');
            }
            $transitioned = $affected === 1;
            $submitted = FiscalStorage::hydrateIntent($scope, $fresh);

            return new FiscalReceipt(
                $transitioned ? 'execution.fiscal_dispatch_started' : 'execution.fiscal_dispatch_skipped',
                ['intentRef' => $intentRef, 'state' => $submitted->state->value],
            );
        })->orThrow();

        $currentIntent = $submitted ?? throw new LogicException('Dispatch start returned no intent');
        if (! $transitioned) {
            return $currentIntent->state->allowsLookup()
                ? $this->lookupIntent($scope, $intentRef, $now)
                : $currentIntent;
        }

        // Driver call strictly outside DB transaction.
        $this->boundary->assertOutsideTransaction();
        $evidenceKind = 'register_response';
        $result = null;
        $transportError = null;
        try {
            $result = $driver->registerReceipt(new FiscalRegisterRequest(
                intent: $currentIntent,
                merchantRevision: $merchant,
                credentials: $credentials,
                externalId: $currentIntent->externalId,
                route: $currentIntent->fiscalRoute,
                amount: $currentIntent->amount,
                payloadSnapshot: $currentIntent->payloadSnapshot,
            ));
        } catch (Throwable $error) {
            $evidenceKind = 'transport_failure';
            $transportError = $error::class;
        }

        return $this->recordDriverOutcome(
            scope: $scope,
            intent: $currentIntent,
            evidenceKind: $evidenceKind,
            credentialVersionRef: $credentials->credentialVersionRef,
            result: $result,
            transportError: $transportError,
            incrementLookupAttempts: false,
            now: $now,
        );
    }

    public function lookupIntent(ExecutionScope $scope, string $intentRef, ?DateTimeImmutable $now = null): FiscalIntentRecord
    {
        $this->boundary->assertOutsideTransaction();
        $this->assertScope($scope);
        $now ??= new DateTimeImmutable;

        $intent = $this->findIntent($scope, $intentRef);
        if ($intent === null) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown fiscal intent reference');
        }
        if ($intent->state->isTerminal()) {
            return $intent;
        }
        if ($intent->state === FiscalIntentState::Prepared) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Cannot lookup a fiscal intent before initial submission');
        }

        $merchant = $this->store->merchantRevision($scope, $intent->merchantRevisionRef);
        $driver = $this->resolveDriver($intent->driverId, $intent->driverContractVersion);
        if (! $driver->capabilities()->supportsLookup) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Fiscal driver does not support receipt lookup');
        }

        // Historical lookup credentials resolved strictly via ContinuityMerchantCredentials('lookup') outside Tx.
        $credentials = $this->credentials->resolve($merchant, 'lookup');

        $this->boundary->assertOutsideTransaction();
        $evidenceKind = 'lookup_response';
        $result = null;
        $transportError = null;
        try {
            $result = $driver->findReceipt(new FiscalLookupRequest(
                intent: $intent,
                merchantRevision: $merchant,
                credentials: $credentials,
                externalId: $intent->externalId,
                providerReceiptId: $intent->providerReceiptId,
                route: $intent->fiscalRoute,
            ));
        } catch (Throwable $error) {
            $evidenceKind = 'transport_failure';
            $transportError = $error::class;
        }

        return $this->recordDriverOutcome(
            scope: $scope,
            intent: $intent,
            evidenceKind: $evidenceKind,
            credentialVersionRef: $credentials->credentialVersionRef,
            result: $result,
            transportError: $transportError,
            incrementLookupAttempts: true,
            now: $now,
        );
    }

    /** @return list<FiscalIntentRecord> */
    public function claimDueLookups(
        ExecutionScope $scope,
        DateTimeImmutable $asOf,
        int $limit = 50,
        int $afterId = 0,
        string $workerRef = 'fiscal-worker',
        int $leaseSeconds = 60,
    ): array {
        $this->boundary->assertOutsideTransaction();
        $this->assertScope($scope);

        if ($limit < 1 || $limit > 500) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'claimDueLookups limit must be between 1 and 500');
        }
        if ($afterId < 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'claimDueLookups afterId must be non-negative');
        }
        if ($workerRef === '' || strlen($workerRef) > 200 || $leaseSeconds < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid workerRef or leaseSeconds for due lookup claim');
        }

        $sourceAuthority = $this->callers->current()->sourceAuthorities[0] ?? null;
        $spec = new OperationSpec(
            'execution.fiscal_claim_due_lookups',
            $scope->sellerRef,
            $scope->environment,
            $this->financialMode($scope),
            ModuleOperationClass::UnknownResolution,
            ['execution', 'platform'],
            financialScope: new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, $sourceAuthority),
        );

        $claimed = [];
        $this->runner->run($spec, function (TransactionContext $ctx) use ($scope, $asOf, $limit, $afterId, $workerRef, $leaseSeconds, &$claimed): FiscalReceipt {
            $db = $this->connection($ctx);
            $asOfText = FiscalJson::formatDbTimestamp($asOf);
            $leaseUntil = FiscalJson::formatDbTimestamp($asOf->modify('+'.$leaseSeconds.' seconds'));

            $candidateIds = [];
            foreach (['unknown', 'submitted'] as $candidateState) {
                $remaining = $limit - count($candidateIds);
                if ($remaining <= 0) {
                    break;
                }
                // Exact COV-337 bounded seller-scoped keyset query backed by billium_fiscal_intent_due_lookup (seller_ref, state, next_lookup_at, id).
                $rows = $db->select(
                    'SELECT id FROM public.billium_fiscal_intent '
                    .'WHERE seller_ref = ? AND state = ? AND next_lookup_at <= ? AND id > ? '
                    .'AND installation_ref = ? AND environment = ? '
                    .'AND (lease_expires_at IS NULL OR lease_expires_at <= ?) '
                    .'ORDER BY next_lookup_at ASC, id ASC '
                    .'LIMIT ?',
                    [
                        $scope->sellerRef,
                        $candidateState,
                        $asOfText,
                        $afterId,
                        $scope->installationRef,
                        $scope->environment,
                        $asOfText,
                        $remaining,
                    ],
                );
                foreach ($rows as $row) {
                    if (is_object($row) && isset($row->id)) {
                        $candidateIds[] = FiscalJson::integer($row->id);
                    }
                }
            }

            if ($candidateIds !== []) {
                $placeholders = implode(', ', array_fill(0, count($candidateIds), '?'));
                $leasedRows = $db->select(
                    'UPDATE public.billium_fiscal_intent '
                    .'SET lease_owner = ?, lease_expires_at = ?, updated_at = ? '
                    ."WHERE id IN ({$placeholders}) AND state IN ('unknown', 'submitted') "
                    .'AND (lease_expires_at IS NULL OR lease_expires_at <= ?) '
                    .'RETURNING id',
                    [$workerRef, $leaseUntil, $asOfText, ...$candidateIds, $asOfText],
                );

                $wonIds = [];
                foreach ($leasedRows as $leased) {
                    if (is_object($leased) && isset($leased->id)) {
                        $wonIds[] = FiscalJson::integer($leased->id);
                    }
                }

                if ($wonIds !== []) {
                    $fetched = $db->table('billium_fiscal_intent')
                        ->whereIn('id', $wonIds)
                        ->orderBy('next_lookup_at')
                        ->orderBy('id')
                        ->get();
                    foreach ($fetched as $row) {
                        $claimed[] = FiscalStorage::hydrateIntent($scope, $row);
                    }
                }
            }

            return new FiscalReceipt('execution.fiscal_due_lookups_claimed', [
                'claimedCount' => count($claimed),
                'sellerRef' => $scope->sellerRef,
            ]);
        })->orThrow();

        return $claimed;
    }

    public function createCorrection(FiscalCorrectionRequest $request): FiscalIntentRecord
    {
        $this->boundary->assertOutsideTransaction();
        $this->assertScope($request->scope);

        $parent = $this->findIntent($request->scope, $request->parentFiscalIntentRef);
        if ($parent === null) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'Parent fiscal intent not found');
        }
        if ($request->correctionAmount->currency->code !== $parent->amount->currency->code) {
            throw new ProblemException(ProblemCode::MoneyCurrencyMismatch, 'Fiscal correction currency must match parent intent currency');
        }

        $driver = $this->resolveDriver($parent->driverId, $parent->driverContractVersion);
        if (! $driver->capabilities()->supportsCorrection) {
            throw new ProblemException(ProblemCode::CapabilityUnsupported, 'Fiscal driver does not support receipt corrections');
        }

        $correctionPolicy = $this->activePolicy($request->scope, $parent->executionProfile, FiscalOperationKind::Correction);
        if ($correctionPolicy !== null && $correctionPolicy->fiscalRoute->isDispatchable() && $correctionPolicy->fiscalRoute !== $parent->fiscalRoute) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'Correction policy cannot switch away from the original frozen fiscal route',
            );
        }

        $scope = $request->scope;
        $correctionKind = $parent->receiptKind->correctionKind();

        $spec = new OperationSpec(
            'execution.fiscal_create_correction',
            $scope->sellerRef,
            $scope->environment,
            $this->financialMode($scope),
            ModuleOperationClass::FrozenIntentCompletion,
            ['execution', 'platform'],
            financialScope: new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, $parent->executionProfile),
        );

        $record = null;
        $this->runner->run($spec, function (TransactionContext $ctx) use ($scope, $parent, $request, $correctionKind, &$record): FiscalReceipt {
            $db = $this->connection($ctx);
            $existingRows = $db->table('billium_fiscal_intent')
                ->where('installation_ref', $scope->installationRef)
                ->where('seller_ref', $scope->sellerRef)
                ->where('environment', $scope->environment)
                ->where('economic_effect_ref', $parent->economicEffectRef)
                ->orderBy('correction_revision')
                ->orderBy('id')
                ->get();

            $maxRevision = 0;
            foreach ($existingRows as $row) {
                $hydrated = FiscalStorage::hydrateIntent($scope, $row);
                if ($hydrated->receiptKind === $correctionKind && $hydrated->correctionRevision > $maxRevision) {
                    $maxRevision = $hydrated->correctionRevision;
                }
                if (
                    $hydrated->receiptKind === $correctionKind
                    && $hydrated->correctionRevision > 0
                    && ($hydrated->payloadSnapshot['correctionBasisRef'] ?? null) === $request->correctionBasisRef
                ) {
                    if (
                        $hydrated->parentFiscalIntentRef !== $request->parentFiscalIntentRef
                        || $hydrated->amount->minor !== $request->correctionAmount->minor
                        || ($hydrated->payloadSnapshot['reason'] ?? null) !== $request->reason
                    ) {
                        throw new ProblemException(
                            ProblemCode::IdempotencyConflict,
                            'Conflicting correction request for existing correctionBasisRef',
                        );
                    }
                    $record = $hydrated;

                    return new FiscalReceipt('execution.fiscal_correction_created', [
                        'intentRef' => $hydrated->intentRef,
                        'externalId' => $hydrated->externalId,
                        'correctionRevision' => $hydrated->correctionRevision,
                        'replayed' => true,
                    ]);
                }
            }

            $nextRevision = $maxRevision + 1;
            $externalId = FiscalStorage::externalId($scope, $parent->economicEffectRef, $correctionKind, $nextRevision);
            $intentRef = FiscalStorage::intentRef($scope, $parent->economicEffectRef, $correctionKind, $nextRevision);

            $payloadSnapshot = [
                'economicEffectRef' => $parent->economicEffectRef,
                'rootOperationRef' => $parent->rootOperationRef,
                'operationRef' => $parent->operationRef,
                'parentFiscalIntentRef' => $parent->intentRef,
                'receiptKind' => $correctionKind->value,
                'correctionRevision' => $nextRevision,
                'correctionBasisRef' => $request->correctionBasisRef,
                'reason' => $request->reason,
                'amount' => $request->correctionAmount->toWire(),
                'receiptPayload' => $request->receiptPayload,
            ];
            $payloadDigest = FiscalJson::digest($payloadSnapshot);
            $now = FiscalJson::formatDbTimestamp(new DateTimeImmutable);

            $id = FiscalJson::integer($db->table('billium_fiscal_intent')->insertGetId([
                'installation_ref' => $scope->installationRef,
                'seller_ref' => $scope->sellerRef,
                'environment' => $scope->environment,
                'intent_ref' => $intentRef,
                'execution_profile' => $parent->executionProfile,
                'economic_effect_ref' => $parent->economicEffectRef,
                'root_operation_ref' => $parent->rootOperationRef,
                'operation_ref' => $parent->operationRef,
                'receipt_kind' => $correctionKind->value,
                'correction_revision' => $nextRevision,
                'parent_fiscal_intent_ref' => $parent->intentRef,
                'external_id' => $externalId,
                'fiscal_route' => $parent->fiscalRoute->value,
                'driver_id' => $parent->driverId,
                'driver_contract_version' => $parent->driverContractVersion,
                'callback_trust_strategy' => $parent->callbackTrustStrategy->value,
                'merchant_revision_ref' => $parent->merchantRevisionRef,
                'merchant_account_ref' => $parent->merchantAccountRef,
                'provider_evidence_ref' => $parent->providerEvidenceRef,
                'policy_version' => $parent->policyVersion,
                'policy_digest' => $parent->policyDigest,
                'amount_minor' => $request->correctionAmount->minor,
                'currency' => $request->correctionAmount->currency->code,
                'currency_exponent' => $request->correctionAmount->currency->scale,
                'exceptional' => $request->exceptional,
                'exposure_provenance' => FiscalJson::encodeObject($request->exposureProvenance),
                'payload_snapshot' => FiscalJson::encodeObject($payloadSnapshot),
                'payload_digest' => $payloadDigest,
                'state' => FiscalIntentState::Prepared->value,
                'lookup_attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

            $fresh = $db->table('billium_fiscal_intent')->where('id', $id)->first();
            if ($fresh === null) {
                throw new LogicException('Inserted fiscal correction intent disappeared');
            }
            $record = FiscalStorage::hydrateIntent($scope, $fresh);

            return new FiscalReceipt('execution.fiscal_correction_created', [
                'intentRef' => $record->intentRef,
                'externalId' => $record->externalId,
                'correctionRevision' => $nextRevision,
                'replayed' => false,
            ]);
        })->orThrow();

        return $record ?? throw new LogicException('Fiscal correction write returned no record');
    }

    public function ingestCallback(FiscalCallbackEnvelope $envelope): FiscalIntentRecord
    {
        $this->boundary->assertOutsideTransaction();
        $this->assertScope($envelope->scope);

        $scope = $envelope->scope;
        $intent = $this->findIntentByExternalId($scope, $envelope->externalId);
        if ($intent === null) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown fiscal intent externalId in callback');
        }

        if (
            $envelope->driverId !== $intent->driverId
            || $envelope->driverContractVersion !== $intent->driverContractVersion
            || $envelope->trustStrategy !== $intent->callbackTrustStrategy
        ) {
            $this->recordRejectedCallback($scope, $intent, $envelope, 'driver_or_trust_strategy_mismatch');
            throw new ProblemException(ProblemCode::AuthDenied, 'Fiscal callback driver, version, or trust strategy mismatch');
        }

        $driver = $this->resolveDriver($intent->driverId, $intent->driverContractVersion);
        if (! $driver->capabilities()->supportsTrustStrategy($envelope->trustStrategy)) {
            $this->recordRejectedCallback($scope, $intent, $envelope, 'unsupported_callback_trust_strategy');
            throw new ProblemException(ProblemCode::AuthDenied, 'Fiscal driver does not support callback trust strategy');
        }

        $verification = $driver->verifyCallback($envelope, $intent);
        if (! $verification->trusted || $verification->result === null) {
            $this->recordRejectedCallback($scope, $intent, $envelope, $verification->rejectionReason ?? 'callback_signature_invalid');
            throw new ProblemException(ProblemCode::AuthDenied, $verification->rejectionReason ?? 'Fiscal callback verification failed');
        }

        $driverResult = $verification->result;
        $spec = new OperationSpec(
            'execution.fiscal_ingest_callback',
            $scope->sellerRef,
            $scope->environment,
            $this->financialMode($scope),
            ModuleOperationClass::ObservedFactRecording,
            ['execution', 'platform'],
            financialScope: new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, $intent->executionProfile),
        );

        $updated = null;
        $this->runner->run($spec, function (TransactionContext $ctx) use ($scope, $intent, $envelope, $driverResult, &$updated): FiscalReceipt {
            $db = $this->connection($ctx);
            $row = $db->table('billium_fiscal_intent')->where('id', $intent->id)->first();
            if ($row === null) {
                throw new LogicException('Fiscal intent disappeared during callback ingestion');
            }
            $current = FiscalStorage::hydrateIntent($scope, $row);

            $alreadySeenEvent = $db->table('billium_fiscal_evidence')
                ->where('installation_ref', $scope->installationRef)
                ->where('seller_ref', $scope->sellerRef)
                ->where('environment', $scope->environment)
                ->where('fiscal_intent_ref', $current->intentRef)
                ->where('evidence_kind', 'callback')
                ->where('external_event_id', $envelope->externalEventId)
                ->exists();

            $sanitized = [
                'externalId' => $envelope->externalId,
                'externalEventId' => $envelope->externalEventId,
                'trustStrategy' => $envelope->trustStrategy->value,
                'reportedState' => $driverResult->state->value,
                'providerReceiptId' => $driverResult->providerReceiptId,
                'fiscalDocumentNumber' => $driverResult->fiscalDocumentNumber,
                'errorCode' => $driverResult->errorCode,
                'details' => $driverResult->sanitizedDetails,
            ];

            if ($alreadySeenEvent || $current->state->isTerminal()) {
                FiscalStorage::appendEvidence(
                    db: $db,
                    scope: $scope,
                    intent: $current,
                    evidenceKind: 'callback',
                    credentialVersionRef: null,
                    trustVerdict: 'verified',
                    externalEventId: $envelope->externalEventId,
                    outcomeState: 'duplicate_ignored',
                    sanitizedPayload: $sanitized,
                    recordedAt: $envelope->receivedAt,
                );
                $updated = $current;

                return new FiscalReceipt('execution.fiscal_callback_ignored', [
                    'intentRef' => $current->intentRef,
                    'state' => $current->state->value,
                    'duplicateIgnored' => true,
                ]);
            }

            $nextState = $this->resolveForwardState($current->state, $driverResult->state->toIntentState());
            $nextLookupAt = $nextState->allowsLookup()
                ? FiscalJson::formatDbTimestamp($envelope->receivedAt->modify('+60 seconds'))
                : null;

            $affected = $db->table('billium_fiscal_intent')
                ->where('id', $current->id)
                ->where('state', $current->state->value)
                ->update([
                    'state' => $nextState->value,
                    'provider_receipt_id' => $current->providerReceiptId ?? $driverResult->providerReceiptId,
                    'fiscal_document_number' => $current->fiscalDocumentNumber ?? $driverResult->fiscalDocumentNumber,
                    'last_error_code' => $driverResult->errorCode,
                    'next_lookup_at' => $nextLookupAt,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'updated_at' => FiscalJson::formatDbTimestamp($envelope->receivedAt),
                ]);

            FiscalStorage::appendEvidence(
                db: $db,
                scope: $scope,
                intent: $current,
                evidenceKind: 'callback',
                credentialVersionRef: null,
                trustVerdict: 'verified',
                externalEventId: $envelope->externalEventId,
                outcomeState: $affected === 1 ? $nextState->value : 'duplicate_ignored',
                sanitizedPayload: $sanitized,
                recordedAt: $envelope->receivedAt,
            );

            $fresh = $db->table('billium_fiscal_intent')->where('id', $current->id)->first();
            if ($fresh === null) {
                throw new LogicException('Updated fiscal intent disappeared after callback');
            }
            $updated = FiscalStorage::hydrateIntent($scope, $fresh);

            return new FiscalReceipt('execution.fiscal_callback_applied', [
                'intentRef' => $updated->intentRef,
                'state' => $updated->state->value,
                'duplicateIgnored' => $affected === 0,
            ]);
        })->orThrow();

        return $updated ?? throw new LogicException('Callback ingestion returned no intent');
    }

    public function recordProjection(FiscalProjectionInput $input): FiscalProjectionRecord
    {
        $this->boundary->assertOutsideTransaction();
        $this->assertScope($input->scope);

        if ($input->fiscalStatus === 'registered') {
            if ($input->externalFiscalRef === null || trim($input->externalFiscalRef) === '' || strlen($input->externalFiscalRef) > 200) {
                throw new ProblemException(
                    ProblemCode::ValidationInvalid,
                    'Registered fiscal projection requires a non-empty bounded externalFiscalRef',
                );
            }
        } elseif ($input->externalFiscalRef !== null && (trim($input->externalFiscalRef) === '' || strlen($input->externalFiscalRef) > 200)) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'externalFiscalRef must be null or a non-empty bounded string',
            );
        }

        $scope = $input->scope;
        $sourcePayload = [
            'economicEffectRef' => $input->economicEffectRef,
            'rootOperationRef' => $input->rootOperationRef,
            'operationRef' => $input->operationRef,
            'receiptKind' => $input->receiptKind->value,
            'correctionRevision' => $input->correctionRevision,
            'fiscalStatus' => $input->fiscalStatus,
            'externalFiscalRef' => $input->externalFiscalRef,
            'amount' => $input->actualAmount->toWire(),
            'sourcePayload' => $input->sourcePayload,
        ];
        $sourceDigest = FiscalJson::digest($sourcePayload);
        $projectionRef = FiscalStorage::projectionRef(
            $scope,
            $input->economicEffectRef,
            $input->receiptKind,
            $input->correctionRevision,
            $input->fiscalStatus,
        );

        $spec = new OperationSpec(
            'execution.fiscal_record_projection',
            $scope->sellerRef,
            $scope->environment,
            FinancialMode::RawCustody,
            ModuleOperationClass::ObservedFactRecording,
            ['execution', 'platform'],
            financialScope: new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace),
        );

        $record = null;
        $this->runner->run($spec, function (TransactionContext $ctx) use ($scope, $input, $projectionRef, $sourcePayload, $sourceDigest, &$record): FiscalReceipt {
            $db = $this->connection($ctx);
            $inserted = $db->table('billium_fiscal_projection')->insertOrIgnore([
                'installation_ref' => $scope->installationRef,
                'seller_ref' => $scope->sellerRef,
                'environment' => $scope->environment,
                'projection_ref' => $projectionRef,
                'execution_profile' => 'embedded-commerz',
                'source_authority' => $input->sourceAuthority,
                'economic_effect_ref' => $input->economicEffectRef,
                'root_operation_ref' => $input->rootOperationRef,
                'operation_ref' => $input->operationRef,
                'receipt_kind' => $input->receiptKind->value,
                'correction_revision' => $input->correctionRevision,
                'external_fiscal_ref' => $input->externalFiscalRef,
                'fiscal_status' => $input->fiscalStatus,
                'amount_minor' => $input->actualAmount->minor,
                'currency' => $input->actualAmount->currency->code,
                'currency_exponent' => $input->actualAmount->currency->scale,
                'exceptional' => $input->exceptional,
                'exposure_provenance' => FiscalJson::encodeObject($input->exposureProvenance),
                'source_payload' => FiscalJson::encodeObject($sourcePayload),
                'source_digest' => $sourceDigest,
                'recorded_at' => FiscalJson::formatDbTimestamp(new DateTimeImmutable),
            ]);

            $row = $db->table('billium_fiscal_projection')
                ->where('installation_ref', $scope->installationRef)
                ->where('seller_ref', $scope->sellerRef)
                ->where('environment', $scope->environment)
                ->where('economic_effect_ref', $input->economicEffectRef)
                ->where('receipt_kind', $input->receiptKind->value)
                ->where('correction_revision', $input->correctionRevision)
                ->where('fiscal_status', $input->fiscalStatus)
                ->first();
            if ($row === null) {
                throw new LogicException('Recorded fiscal projection disappeared');
            }
            $hydrated = FiscalStorage::hydrateProjection($scope, $row);
            if (
                $inserted === 0
                && (
                    $hydrated->amount->minor !== $input->actualAmount->minor
                    || $hydrated->amount->currency->code !== $input->actualAmount->currency->code
                    || $hydrated->externalFiscalRef !== $input->externalFiscalRef
                    || $hydrated->sourceDigest !== $sourceDigest
                )
            ) {
                throw new ProblemException(
                    ProblemCode::IdempotencyConflict,
                    'Conflicting amount, currency, externalFiscalRef, or source payload for existing fiscal projection key',
                );
            }
            $record = $hydrated;

            return new FiscalReceipt('execution.fiscal_projection_recorded', [
                'projectionRef' => $hydrated->projectionRef,
                'replayed' => $inserted === 0,
            ]);
        })->orThrow();

        return $record ?? throw new LogicException('Fiscal projection write returned no record');
    }

    /**
     * @param  list<FiscalExternalOutcome>  $externalOutcomes
     * @return list<FiscalProjectionRecord>
     */
    public function syncActualProjectionsForRoot(
        ExecutionScope $scope,
        string $rootOperationRef,
        string $sourceAuthority = 'embedded-commerz',
        array $externalOutcomes = [],
    ): array {
        $this->boundary->assertOutsideTransaction();
        $this->assertScope($scope);

        if ($sourceAuthority === '' || strlen($sourceAuthority) > 200) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'sourceAuthority must be a non-empty bounded string');
        }
        if ($externalOutcomes === []) {
            return [];
        }

        $effects = $this->extractor->extractForRoot($scope, $rootOperationRef, 'embedded-commerz');
        $projectionsByRef = [];
        foreach ($effects as $effect) {
            $policy = $this->activePolicy($scope, 'embedded-commerz', $effect->operationKind);
            if ($policy === null || ! $policy->receiptRequired || $policy->fiscalRoute !== FiscalRoute::ProjectionOnly) {
                continue;
            }
            foreach ($externalOutcomes as $outcome) {
                if (
                    $outcome->operationRef !== $effect->operationRef
                    || $outcome->economicEffectRef !== $effect->economicEffectRef
                    || $outcome->receiptKind !== $effect->receiptKind
                    || $outcome->correctionRevision !== 0
                ) {
                    continue;
                }
                $recorded = $this->recordProjection(new FiscalProjectionInput(
                    scope: $scope,
                    sourceAuthority: $sourceAuthority,
                    economicEffectRef: $effect->economicEffectRef,
                    rootOperationRef: $effect->rootOperationRef,
                    operationRef: $effect->operationRef,
                    receiptKind: $outcome->receiptKind,
                    correctionRevision: $outcome->correctionRevision,
                    actualAmount: $effect->actualAmount,
                    fiscalStatus: $outcome->fiscalStatus,
                    externalFiscalRef: $outcome->externalFiscalRef,
                    exceptional: $effect->exceptional,
                    exposureProvenance: $effect->exposureProvenance,
                    sourcePayload: [
                        'evidenceDigest' => $outcome->evidenceDigest,
                        'externalOutcome' => $outcome->sourcePayload,
                        'actualEffect' => $effect->receiptPayload,
                    ],
                ));
                $projectionsByRef[$recorded->projectionRef] = $recorded;
            }
        }

        return array_values($projectionsByRef);
    }

    public function findIntent(ExecutionScope $scope, string $intentRef): ?FiscalIntentRecord
    {
        $this->assertScope($scope);
        $row = $this->connection()->table('billium_fiscal_intent')
            ->where('installation_ref', $scope->installationRef)
            ->where('seller_ref', $scope->sellerRef)
            ->where('environment', $scope->environment)
            ->where('intent_ref', $intentRef)
            ->first();

        return $row === null ? null : FiscalStorage::hydrateIntent($scope, $row);
    }

    public function findIntentByExternalId(ExecutionScope $scope, string $externalId): ?FiscalIntentRecord
    {
        $this->assertScope($scope);
        $row = $this->connection()->table('billium_fiscal_intent')
            ->where('installation_ref', $scope->installationRef)
            ->where('seller_ref', $scope->sellerRef)
            ->where('environment', $scope->environment)
            ->where('external_id', $externalId)
            ->first();

        return $row === null ? null : FiscalStorage::hydrateIntent($scope, $row);
    }

    /** @return list<FiscalIntentRecord> */
    public function intentsForRoot(ExecutionScope $scope, string $rootOperationRef): array
    {
        $this->assertScope($scope);
        $rows = $this->connection()->table('billium_fiscal_intent')
            ->where('installation_ref', $scope->installationRef)
            ->where('seller_ref', $scope->sellerRef)
            ->where('environment', $scope->environment)
            ->where('root_operation_ref', $rootOperationRef)
            ->orderBy('id')
            ->get();

        $records = [];
        foreach ($rows as $row) {
            $records[] = FiscalStorage::hydrateIntent($scope, $row);
        }

        return $records;
    }

    /** @return list<FiscalEvidenceRecord> */
    public function evidenceForIntent(ExecutionScope $scope, string $intentRef): array
    {
        $this->assertScope($scope);
        $rows = $this->connection()->table('billium_fiscal_evidence')
            ->where('installation_ref', $scope->installationRef)
            ->where('seller_ref', $scope->sellerRef)
            ->where('environment', $scope->environment)
            ->where('fiscal_intent_ref', $intentRef)
            ->orderBy('id')
            ->get();

        $records = [];
        foreach ($rows as $row) {
            $records[] = FiscalStorage::hydrateEvidence($row);
        }

        return $records;
    }

    /** @return list<FiscalProjectionRecord> */
    public function projectionsForRoot(ExecutionScope $scope, string $rootOperationRef): array
    {
        $this->assertScope($scope);
        $rows = $this->connection()->table('billium_fiscal_projection')
            ->where('installation_ref', $scope->installationRef)
            ->where('seller_ref', $scope->sellerRef)
            ->where('environment', $scope->environment)
            ->where('root_operation_ref', $rootOperationRef)
            ->orderBy('id')
            ->get();

        $records = [];
        foreach ($rows as $row) {
            $records[] = FiscalStorage::hydrateProjection($scope, $row);
        }

        return $records;
    }

    private function recordDriverOutcome(
        ExecutionScope $scope,
        FiscalIntentRecord $intent,
        string $evidenceKind,
        string $credentialVersionRef,
        ?FiscalDriverResult $result,
        ?string $transportError,
        bool $incrementLookupAttempts,
        DateTimeImmutable $now,
    ): FiscalIntentRecord {
        $spec = new OperationSpec(
            $incrementLookupAttempts ? 'execution.fiscal_record_lookup' : 'execution.fiscal_record_register',
            $scope->sellerRef,
            $scope->environment,
            $this->financialMode($scope),
            $incrementLookupAttempts ? ModuleOperationClass::UnknownResolution : ModuleOperationClass::FrozenIntentCompletion,
            ['execution', 'platform'],
            financialScope: new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, $intent->executionProfile),
        );

        $updated = null;
        $this->runner->run($spec, function (TransactionContext $ctx) use (
            $scope,
            $intent,
            $evidenceKind,
            $credentialVersionRef,
            $result,
            $transportError,
            $incrementLookupAttempts,
            $now,
            &$updated,
        ): FiscalReceipt {
            $db = $this->connection($ctx);
            $row = $db->table('billium_fiscal_intent')->where('id', $intent->id)->first();
            if ($row === null) {
                throw new LogicException('Fiscal intent disappeared while recording driver outcome');
            }
            $current = FiscalStorage::hydrateIntent($scope, $row);
            $proposedState = $result?->state->toIntentState() ?? FiscalIntentState::Unknown;
            $errorCode = $result !== null ? $result->errorCode : ($transportError !== null ? 'transport_unavailable' : null);

            $sanitized = [
                'evidenceKind' => $evidenceKind,
                'externalId' => $current->externalId,
                'route' => $current->fiscalRoute->value,
                'reportedState' => $proposedState->value,
                'providerReceiptId' => $result?->providerReceiptId,
                'fiscalDocumentNumber' => $result?->fiscalDocumentNumber,
                'errorCode' => $errorCode,
                'transportErrorClass' => $transportError,
                'details' => $result->sanitizedDetails ?? [],
            ];

            if ($current->state->isTerminal()) {
                FiscalStorage::appendEvidence(
                    db: $db,
                    scope: $scope,
                    intent: $current,
                    evidenceKind: $evidenceKind,
                    credentialVersionRef: $credentialVersionRef,
                    trustVerdict: 'not_applicable',
                    externalEventId: null,
                    outcomeState: 'duplicate_ignored',
                    sanitizedPayload: $sanitized,
                    recordedAt: $now,
                );
                $updated = $current;

                return new FiscalReceipt('execution.fiscal_outcome_ignored', ['state' => $current->state->value]);
            }

            $nextState = $this->resolveForwardState($current->state, $proposedState);
            $nextAttempts = $incrementLookupAttempts ? $current->lookupAttempts + 1 : $current->lookupAttempts;
            $nextLookupAt = null;
            if ($nextState->allowsLookup()) {
                $shift = min($nextAttempts, 6);
                $backoffSeconds = min(3600, 60 * (1 << $shift));
                $nextLookupAt = FiscalJson::formatDbTimestamp($now->modify('+'.$backoffSeconds.' seconds'));
            }

            $affected = $db->table('billium_fiscal_intent')
                ->where('id', $current->id)
                ->where('state', $current->state->value)
                ->update([
                    'state' => $nextState->value,
                    'provider_receipt_id' => $current->providerReceiptId ?? $result?->providerReceiptId,
                    'fiscal_document_number' => $current->fiscalDocumentNumber ?? $result?->fiscalDocumentNumber,
                    'last_error_code' => $errorCode,
                    'lookup_attempts' => $nextAttempts,
                    'next_lookup_at' => $nextLookupAt,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'updated_at' => FiscalJson::formatDbTimestamp($now),
                ]);

            FiscalStorage::appendEvidence(
                db: $db,
                scope: $scope,
                intent: $current,
                evidenceKind: $evidenceKind,
                credentialVersionRef: $credentialVersionRef,
                trustVerdict: 'not_applicable',
                externalEventId: null,
                outcomeState: $affected === 1 ? $nextState->value : 'duplicate_ignored',
                sanitizedPayload: $sanitized,
                recordedAt: $now,
            );

            $fresh = $db->table('billium_fiscal_intent')->where('id', $current->id)->first();
            if ($fresh === null) {
                throw new LogicException('Updated fiscal intent disappeared');
            }
            $updated = FiscalStorage::hydrateIntent($scope, $fresh);

            return new FiscalReceipt('execution.fiscal_outcome_recorded', [
                'intentRef' => $updated->intentRef,
                'state' => $updated->state->value,
            ]);
        })->orThrow();

        return $updated ?? throw new LogicException('Driver outcome recording returned no intent');
    }

    private function recordRejectedCallback(
        ExecutionScope $scope,
        FiscalIntentRecord $intent,
        FiscalCallbackEnvelope $envelope,
        string $reason,
    ): void {
        $spec = new OperationSpec(
            'execution.fiscal_callback_rejected',
            $scope->sellerRef,
            $scope->environment,
            FinancialMode::RawCustody,
            ModuleOperationClass::CallbackCustody,
            ['execution', 'platform'],
            financialScope: new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace),
        );
        $this->runner->run($spec, function (TransactionContext $ctx) use ($scope, $intent, $envelope, $reason): FiscalReceipt {
            $db = $this->connection($ctx);
            $sanitized = [
                'externalId' => $envelope->externalId,
                'externalEventId' => $envelope->externalEventId,
                'claimedDriverId' => $envelope->driverId,
                'claimedContractVersion' => $envelope->driverContractVersion,
                'claimedTrustStrategy' => $envelope->trustStrategy->value,
                'rejectionReason' => $reason,
            ];
            FiscalStorage::appendEvidence(
                db: $db,
                scope: $scope,
                intent: $intent,
                evidenceKind: 'callback_rejected',
                credentialVersionRef: null,
                trustVerdict: 'rejected',
                externalEventId: $envelope->externalEventId,
                outcomeState: 'untrusted_rejected',
                sanitizedPayload: $sanitized,
                recordedAt: $envelope->receivedAt,
            );

            return new FiscalReceipt('execution.fiscal_callback_rejected', ['reason' => $reason]);
        })->orThrow();
    }

    private function resolveForwardState(FiscalIntentState $current, FiscalIntentState $proposed): FiscalIntentState
    {
        if ($current === FiscalIntentState::Unknown && $proposed === FiscalIntentState::Submitted) {
            return FiscalIntentState::Unknown;
        }
        if ($current === FiscalIntentState::Intervention && in_array($proposed, [FiscalIntentState::Submitted, FiscalIntentState::Unknown], true)) {
            return FiscalIntentState::Intervention;
        }

        return $proposed;
    }

    private function registerCredentialPurpose(FiscalIntentRecord $intent): string
    {
        return match ($intent->receiptKind) {
            FiscalReceiptKind::Refund, FiscalReceiptKind::CorrectionRefund => 'refund',
            FiscalReceiptKind::Sale, FiscalReceiptKind::CorrectionSale => 'new',
        };
    }

    private function resolveDriver(string $driverId, string $contractVersion): FiscalDriver
    {
        $registry = ($this->drivers)();
        $driver = $registry->resolve($driverId, $contractVersion);
        if ($driver->driverId() !== $driverId || $driver->contractVersion() !== $contractVersion) {
            throw new ProblemException(
                ProblemCode::CapabilityUnsupported,
                'Resolved fiscal driver does not match requested exact driverId and contractVersion',
            );
        }

        return $driver;
    }

    private function assertScope(ExecutionScope $scope): void
    {
        $caller = $this->callers->current();
        if (
            $caller->installationRef !== $scope->installationRef
            || $caller->sellerRef !== $scope->sellerRef
            || $caller->environment !== $scope->environment
            || ! in_array($scope->intentOwnerNamespace, $caller->intentOwnerNamespaces, true)
        ) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Fiscal operation scope is outside the authenticated caller grant');
        }
    }

    private function financialMode(ExecutionScope $scope): FinancialMode
    {
        $session = $this->installation->currentSession($scope->installationRef);

        return $session?->state === 'recovery' ? FinancialMode::Recovery : FinancialMode::Active;
    }

    private function connection(?TransactionContext $ctx = null): Connection
    {
        return $this->databases->connection($ctx?->connectionName() ?? $this->options->connection);
    }
}
