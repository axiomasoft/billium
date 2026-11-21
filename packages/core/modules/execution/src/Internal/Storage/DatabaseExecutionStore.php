<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Storage;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Execution\Contracts\Collection\RootIdentityStore;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\OriginalIdentityArchive;
use Billium\Execution\Data\AdmissionConflict;
use Billium\Execution\Data\AdmissionPlan;
use Billium\Execution\Data\ArchivedExecutionIdentity;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\AuthorizationRecord;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\CapacitySnapshot;
use Billium\Execution\Data\DispatchDecision;
use Billium\Execution\Data\ExecutionLockPlan;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\MaterialChange;
use Billium\Execution\Data\MaterialSnapshot;
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\NamespaceRecord;
use Billium\Execution\Data\ObservationEmission;
use Billium\Execution\Data\ObservationPartition;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ProviderFact;
use Billium\Execution\Data\ProviderWorkClaim;
use Billium\Execution\Data\ProviderWorkInput;
use Billium\Execution\Data\ReconciliationRun;
use Billium\Execution\Data\RecoveryAction;
use Billium\Execution\Data\Reservation;
use Billium\Execution\Data\VerifiedAbsence;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Enums\ReservationState;
use Billium\Execution\Enums\WorkState;
use Billium\Execution\Internal\Authority\AbsenceProofRegistry;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Reducer\VerifiedReduction;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final class DatabaseExecutionStore implements ExecutionStore, RootIdentityStore
{
    private readonly ExecutionTables $tables;

    private readonly OperationRevisionLog $revisions;

    private readonly NamespaceStorage $namespaces;

    private readonly MerchantRevisionStorage $merchants;

    private readonly CapacityStorage $capacities;

    private readonly ObservationStorage $observations;

    private readonly ProviderCustodyStorage $custody;

    private readonly AttemptStorage $attempts;

    private readonly RecoveryRecordStorage $recoveryRecords;

    private readonly ProviderFactStorage $facts;

    private readonly AdmissionStorage $admissions;

    /** @param (Closure():?OriginalIdentityArchive)|null $identityArchive */
    public function __construct(DatabaseManager $databases, OperationRunner $runner, CallerContext $callers, BilliumOptions $options, private readonly FinancialSessionAdmission $sessionAdmission, ?AbsenceProofRegistry $absenceProofs = null, private readonly ?Closure $identityArchive = null, ?VerifiedReduction $verifiedReduction = null)
    {
        $this->tables = new ExecutionTables($databases, $runner, $callers, $options);
        $this->revisions = new OperationRevisionLog($this->tables);
        $this->namespaces = new NamespaceStorage($this->tables);
        $this->merchants = new MerchantRevisionStorage($this->tables);
        $this->capacities = new CapacityStorage($this->tables);
        $this->observations = new ObservationStorage($this->tables);
        $this->custody = new ProviderCustodyStorage($this->tables);
        $this->attempts = new AttemptStorage($this->tables, $this->merchants, $this->capacities, $sessionAdmission, $this->revisions);
        $this->recoveryRecords = new RecoveryRecordStorage($this->tables);
        $this->admissions = new AdmissionStorage($this->tables, $this->merchants, $this->namespaces);
        $this->facts = new ProviderFactStorage($this->tables, $this->merchants, $this->capacities, $this->custody, $absenceProofs, $verifiedReduction, $this->revisions);
    }

    public function plan(ExecutionScope $executionScope, string $operationRef, bool $includeChildren = false, ?TransactionContext $context = null): ExecutionLockPlan
    {
        $this->tables->assertScope($executionScope, $context);
        $operation = $this->operation($executionScope, $operationRef, $context);
        if ($operation === null) {
            return new ExecutionLockPlan($executionScope, $operationRef, [], ['execution', 'platform'], ExecutionCodec::digest([$executionScope, $operationRef, []]));
        }
        $root = $operation->rootOperationRef;
        $locks = [];
        $parts = $this->tables->query('observation_partition', $executionScope, $context)->where('root_operation_ref', $root)->orderBy('id')->pluck('id')->all();
        if ($parts !== []) {
            $locks[] = new LockRequest(LockRank::FeedPartition, 'billium_execution_observation_partition', array_values(array_map(ExecutionRows::integer(...), $parts)));
        }
        $row = $this->tables->query('operation', $executionScope, $context)->where('operation_ref', $root)->first();
        if ($row !== null) {
            $locks[] = new LockRequest(LockRank::RootCharge, 'billium_execution_operation', [ExecutionRows::integer($row->id)]);
        }

        $children = $this->tables->query('operation', $executionScope, $context)->where('root_operation_ref', $root)->where('operation_ref', '<>', $root);
        $ids = array_values(array_map(ExecutionRows::integer(...), $children->orderBy('id')->pluck('id')->all()));
        if ($ids !== []) {
            $locks[] = new LockRequest(LockRank::ChildEffect, 'billium_execution_operation', $ids);
        }

        $capacity = $this->tables->query('capacity', $executionScope, $context)->where('root_operation_ref', $root)->first();
        if ($capacity !== null) {
            $locks[] = new LockRequest(LockRank::Capacity, 'billium_execution_capacity', [ExecutionRows::integer($capacity->id)]);
        }
        foreach ([1 => 'reservation', 2 => 'authorization'] as $segment => $table) {
            $rows = $this->tables->query($table, $executionScope, $context)->where('root_operation_ref', $root)->orderBy('id')->pluck('id')->all();
            if ($rows !== []) {
                $locks[] = new LockRequest(LockRank::Capacity, 'billium_execution_'.$table, array_values(array_map(ExecutionRows::integer(...), $rows)), segment: $segment);
            }
        }
        $attemptIds = $this->tables->query('attempt', $executionScope, $context)->whereIn('operation_ref', $this->tables->query('operation', $executionScope, $context)->where('root_operation_ref', $root)->pluck('operation_ref')->all())->orderBy('id')->pluck('id')->all();
        if ($attemptIds !== []) {
            $locks[] = new LockRequest(LockRank::Attempt, 'billium_execution_attempt', array_values(array_map(ExecutionRows::integer(...), $attemptIds)));
        }

        return new ExecutionLockPlan($executionScope, $root, $locks, ['execution', 'platform'], ExecutionCodec::digest([$executionScope, $root, $locks]));
    }

    public function lock(TransactionContext $transactionContext, ExecutionLockPlan $executionLockPlan): void
    {
        $this->tables->database($transactionContext);
        $this->tables->assertScope($executionLockPlan->scope, $transactionContext);
        if ($executionLockPlan->planDigest !== ExecutionCodec::digest([$executionLockPlan->scope, $executionLockPlan->rootOperationRef, $executionLockPlan->locks])) {
            throw new LogicException('Execution lock plan digest mismatch');
        }
        foreach ($executionLockPlan->locks as $request) {
            if (! str_starts_with($request->table, 'billium_execution_')) {
                throw new LogicException('Foreign execution lock target');
            }
            $locked = $transactionContext->lock($request);
            if (count($locked) !== count($request->keys)) {
                throw new LockPlanChanged('Execution row plan changed under locks');
            }
        }
        $current = $this->plan($executionLockPlan->scope, $executionLockPlan->rootOperationRef, true, $transactionContext);
        if ($current->planDigest !== $executionLockPlan->planDigest) {
            throw new LockPlanChanged('Execution row plan changed under locks');
        }
        $held = [];
        if ($this->operation($executionLockPlan->scope, $executionLockPlan->rootOperationRef, $transactionContext) !== null) {
            $this->tables->admittedOperation($transactionContext, $executionLockPlan->rootOperationRef);
            $held[] = $executionLockPlan->rootOperationRef;
        }
        foreach ($executionLockPlan->locks as $request) {
            foreach ($request->keys as $id) {
                $held[] = $request->table.':'.$id;
            }
        }
        $this->tables->hold($transactionContext, $held);
    }

    public function lockRootIdentity(TransactionContext $context, ExecutionScope $scope, string $operationRef, string $businessIntentRef): void
    {
        $this->financial($context);
        $this->assertScope($scope, $context);
        $prefix = ['billium.execution.root-identity.v1', $this->scope($scope)];
        // Collection reserves either reference across source contexts and intent owner namespaces.
        $keys = [ExecutionCodec::encode([...$prefix, 'operation', $operationRef]), ExecutionCodec::encode([...$prefix, 'intent', $businessIntentRef])];
        $this->database($context)->select(
            'SELECT pg_advisory_xact_lock(lock_key) FROM (VALUES (hashtextextended(?, 0)), (hashtextextended(?, 0))) AS identities(lock_key) ORDER BY lock_key',
            $keys,
        );
    }

    public function rootIdentityExists(TransactionContext $context, ExecutionScope $scope, string $operationRef, string $businessIntentRef): bool
    {
        $this->assertScope($scope, $context);

        return $this->query('business_binding', $scope, $context)->where(static fn (Builder $query): Builder => $query
            ->where('operation_ref', $operationRef)->orWhere('business_intent_ref', $businessIntentRef))->exists()
            || $this->callerQuery('operation', $context)->where('operation_ref', $operationRef)->exists();
    }

    public function importOriginalIdentity(TransactionContext $ctx, ArchivedExecutionIdentity $identity, bool $verifyOnly = false): ExecutionReceipt
    {
        $this->tables->financial($ctx);
        $binding = $identity->binding;
        $scope = $binding->scope;
        $this->tables->assertScope($scope, $ctx, $binding->executionProfile);
        $spec = $ctx->spec();
        $token = $this->sessionAdmission->currentToken($scope->installationRef);
        $archive = $this->identityArchive === null ? null : ($this->identityArchive)();
        if ($spec->mode !== FinancialMode::Recovery || ! in_array('execution.recover_held', $ctx->caller()->permissions, true)
            || $token === null || $token->expired(new DateTimeImmutable) || ! $token->permits(FinancialMode::Recovery)
            || $identity->originalLineage === AuthorityClaims::sourceLineage($token, $ctx->sessionEpoch()) || $identity->startedAt > new DateTimeImmutable
            || $archive?->cached($scope, $binding->merchantAccountRef, $identity->providerReference, $token, $ctx->sessionEpoch()) !== $identity) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Current recovery owner has no authenticated original dispatch and source journal');
        }
        if ((! $verifyOnly && ($spec->commandKind !== $identity->commandSchema() || $spec->commandKey !== $identity->qualifiedKeyDigest
            || $spec->digest !== $identity->semanticDigest || $spec->inputDigest !== $identity->inputDigest || $spec->originalExecutionBindingRef !== $binding->rootOperationRef))
            || $this->lockNamespace($ctx, $scope)->state === NamespaceState::Open
            || $binding->canonicalizationVersion !== PaymentIdentity::VERSION
            || $identity->qualifiedKeyDigest !== PaymentIdentity::commandKey($scope, $identity->kind === EffectKind::Authorize ? 'charge' : $identity->kind->value, $binding->sourceContext, $identity->commandKey)
            || $identity->requestDigest !== AuthorityClaims::immutableDriverRequestDigest($binding, $identity->operationRef, $identity->attemptRef, $identity->providerReference, $identity->kind, $identity->requested)) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Archive differs from the original command or qualified provider request');
        }
        $merchant = $this->merchantRevision($scope, $binding->merchantRevisionRef, $ctx);
        $cap = $this->capability($scope, $merchant->capabilitySnapshotRef, $ctx);
        if ([$merchant->providerId, $merchant->digest, $merchant->credentialVersionRef, $merchant->merchantAccountRef, $merchant->driverId, $merchant->driverContractVersion,
            $merchant->capabilityDigest, $cap->details->lookupNamespace] !== [$identity->providerId, $identity->merchantRevisionDigest, $identity->credentialVersionRef,
                $binding->merchantAccountRef, $binding->driverId, $binding->driverContractVersion, $binding->capabilityDigest, $identity->providerNamespace]
            || ! in_array($identity->requested->currency->code, $merchant->currencyCodes, true)) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Archived merchant lineage is unavailable on the selected attachment');
        }
        if (! $verifyOnly) {
            $original = $this->prepareObservationPartition($ctx, $binding, $identity->originalLineage);
            $current = $this->prepareObservationPartition($ctx, $binding, AuthorityClaims::sourceLineage($token, $ctx->sessionEpoch()));
            if ($this->operation($scope, $binding->rootOperationRef, $ctx) === null) {
                $ids = array_values(array_unique([$original->id, $current->id]));
                sort($ids, SORT_NUMERIC);
                if ($ctx->lock(new LockRequest(LockRank::FeedPartition, 'billium_execution_observation_partition', $ids)) !== $ids) {
                    throw new LockPlanChanged('Archived observation partitions changed during recovery');
                }
            }
        }
        $this->lock($ctx, $this->plan($scope, $binding->rootOperationRef, true, $ctx));
        $imports = $this->tables->query('original_identity_import', $scope, $ctx)->where('intent_owner_namespace', $scope->intentOwnerNamespace);
        $digest = ExecutionCodec::digest($identity);
        $prior = (clone $imports)->where('operation_ref', $identity->operationRef)->first();
        $receipt = new ExecutionReceipt(PaymentIdentity::reference($scope, $identity->operationRef, 'admission'), $identity->commandKey,
            $identity->operationRef, $identity->semanticDigest, 'admitted', false, [$identity->attemptRef]);
        if ($prior !== null) {
            if ($prior->identity_digest !== $digest) {
                throw new ProblemException(ProblemCode::IdempotencyConflict, 'Original dispatch archive conflicts with a retained import');
            }

            return $receipt;
        }
        if ($verifyOnly || $this->operation($scope, $identity->operationRef, $ctx) !== null) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'Original receipt or operation has another retained owner');
        }
        $this->insertArchivedExecution($ctx, $identity);
        $this->tables->database($ctx)->table('billium_execution_original_identity_import')->insert(ExecutionTables::scope($scope) + [
            'intent_owner_namespace' => $scope->intentOwnerNamespace, 'operation_ref' => $identity->operationRef, 'attempt_ref' => $identity->attemptRef,
            'command_key_digest' => $identity->qualifiedKeyDigest, 'input_digest' => $identity->inputDigest, 'manifest_digest' => $identity->manifestDigest,
            'source_journal_digest' => $identity->sourceJournalDigest, 'identity_digest' => $digest, 'original_lineage' => $identity->originalLineage,
            'original_fence' => $identity->originalFence, 'adopted_epoch' => $ctx->sessionEpoch(),
        ]);
        if ($identity->operationRef !== $binding->rootOperationRef) {
            // The attempt and archive now establish the original send-closed child for the SQL reservation guard.
            // A newer root total does not settle it. Only the original authenticated child fact can do that.
            $reservation = new Reservation(PaymentIdentity::reference($scope, $identity->operationRef, 'reservation'), $binding->rootOperationRef,
                $identity->operationRef, $identity->kind, $identity->requested, ReservationState::Held, new Money(0, $identity->requested->currency), null);
            $this->capacities->insertHeldReservation($ctx, $reservation, $this->capacity($ctx, $binding->rootOperationRef));
        }

        return $receipt;
    }

    private function insertArchivedExecution(TransactionContext $ctx, ArchivedExecutionIdentity $a): void
    {
        $b = $a->binding;
        $scope = $b->scope;
        $db = $this->tables->database($ctx);
        $root = $a->operationRef === $b->rootOperationRef;
        $shared = ExecutionTables::scope($scope) + ['intent_owner_namespace' => $scope->intentOwnerNamespace, 'source_context' => $b->sourceContext,
            'binding' => ExecutionCodec::encode($b), 'operation_ref' => $a->operationRef, 'created_at' => ExecutionRows::stamp($a->createdAt)];
        $identity = $shared + ['business_intent_ref' => $a->businessIntentRef, 'operation_kind' => $root ? 'charge' : $a->kind->value,
            'semantic_digest' => $a->semanticDigest, 'canonicalization_version' => $b->canonicalizationVersion];
        $terms = ['root_operation_ref' => $b->rootOperationRef, 'requested_minor' => $a->requested->minor,
            'currency' => $a->requested->currency->code, 'currency_exponent' => $a->requested->currency->scale];
        $table = $root ? 'business_binding' : 'child_identity';
        if (! $root) {
            $this->tables->requireLock($ctx, $b->rootOperationRef);
            $parent = $this->operation($scope, $b->rootOperationRef, $ctx);
            if ($parent === null || ExecutionCodec::differs($parent->binding, $b)
                || $parent->requested->currency->code !== $a->requested->currency->code) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Reconcile the authenticated original root before its child');
            }
            $identity += $terms + ['reason_code' => $a->reasonCode, 'category' => $a->category, 'receipt_ref' => PaymentIdentity::reference($scope, $a->operationRef, 'admission')];
        }
        if ($db->table('billium_execution_'.$table)->insertOrIgnore($identity) !== 1) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'The global original business identity or reference already has an owner');
        }
        $db->table('billium_execution_operation')->insert($shared + $terms + [
            'kind' => $a->kind->value, 'business_intent_ref' => $b->businessIntentRef, 'binding_digest' => ExecutionCodec::bindingDigest($b),
            'merchant_revision_ref' => $b->merchantRevisionRef, 'merchant_account_ref' => $b->merchantAccountRef, 'driver_id' => $b->driverId,
            'driver_contract_version' => $b->driverContractVersion, 'capability_digest' => $b->capabilityDigest, 'state' => 'unknown', 'revision' => 0,
            'economic_digest' => $a->semanticDigest, 'collection_authorization_ref' => $b->collectionAuthorizationRef,
        ]);
        if ($root) {
            $db->table('billium_execution_capacity')->insert(ExecutionTables::scope($scope) + ['root_operation_ref' => $b->rootOperationRef, 'currency' => $a->requested->currency->code]);
        }
        $db->table('billium_execution_attempt')->insert(ExecutionTables::scope($scope) + ['attempt_ref' => $a->attemptRef, 'operation_ref' => $a->operationRef,
            'provider_reference' => $a->providerReference, 'provider_id' => $a->providerId, 'merchant_account_ref' => $b->merchantAccountRef,
            'state' => 'unknown', 'lease_fence' => $a->originalFence + 1, 'session_epoch' => $a->originalEpoch, 'owner_session_ref' => $a->originalOwner,
            'request_digest' => $a->requestDigest, 'started_at' => ExecutionRows::stamp($a->startedAt), 'request_deadline_at' => ExecutionRows::stamp($a->deadline),
            'dispatch_ticket_ref' => $a->firstTicketRef, 'collection_dispatch_permit_ref' => $a->collectionPermitRef]);
    }

    public function operation(ExecutionScope $executionScope, string $string, ?TransactionContext $context = null): ?OperationRecord
    {
        return $this->tables->operation($executionScope, $string, $context);
    }

    public function attempt(ExecutionScope $executionScope, string $attemptRef, ?TransactionContext $context = null): ?AttemptRecord
    {
        return $this->tables->attempt($executionScope, $attemptRef, $context);
    }

    public function merchantRevision(ExecutionScope $executionScope, string $revisionRef, ?TransactionContext $context = null): MerchantRevision
    {
        return $this->merchants->merchantRevision($executionScope, $revisionRef, $context);
    }

    public function appendMerchantRevision(TransactionContext $transactionContext, MerchantRevision $merchantRevision, CapabilitySnapshot $capabilitySnapshot): void
    {
        $this->merchants->appendMerchantRevision($transactionContext, $merchantRevision, $capabilitySnapshot);
    }

    public function capability(ExecutionScope $scope, string $ref, ?TransactionContext $context = null): CapabilitySnapshot
    {
        return $this->merchants->capability($scope, $ref, $context);
    }

    public function ensureObservationPartition(TransactionContext $transactionContext, ExecutionScope $executionScope, string $sourceAuthority, string $lineage, string $rootRef): ObservationPartition
    {
        return $this->observations->ensureObservationPartition($transactionContext, $executionScope, $sourceAuthority, $lineage, $rootRef);
    }

    public function prepareObservationPartition(TransactionContext $context, FrozenBinding $binding, string $lineage): ObservationPartition
    {
        return $this->observations->prepareObservationPartition($context, $binding, $lineage);
    }

    public function nextObservationSequence(TransactionContext $transactionContext, int $partitionId): int
    {
        return $this->observations->nextObservationSequence($transactionContext, $partitionId);
    }

    public function appendEmission(TransactionContext $transactionContext, ObservationEmission $observationEmission): void
    {
        $this->observations->appendEmission($transactionContext, $observationEmission);
    }

    public function bindEmissionCommandRow(TransactionContext $transactionContext, string $commandRef, int $commandRowId): void
    {
        $this->observations->bindEmissionCommandRow($transactionContext, $commandRef, $commandRowId);
    }

    public function appendReconciliationRun(TransactionContext $transactionContext, ReconciliationRun $reconciliationRun): void
    {
        $this->recoveryRecords->appendReconciliationRun($transactionContext, $reconciliationRun);
    }

    public function advanceReconciliationRun(TransactionContext $transactionContext, ReconciliationRun $reconciliationRun, string $expectedState): void
    {
        $this->recoveryRecords->advanceReconciliationRun($transactionContext, $reconciliationRun, $expectedState);
    }

    public function appendRepairReceipt(TransactionContext $transactionContext, RecoveryAction $recoveryAction, string $beforeDigest, string $afterDigest, array $correctionRefs): ExecutionReceipt
    {
        return $this->recoveryRecords->appendRepairReceipt($transactionContext, $recoveryAction, $beforeDigest, $afterDigest, $correctionRefs);
    }

    public function recordRecoveryAction(TransactionContext $transactionContext, RecoveryAction $recoveryAction): ExecutionReceipt
    {
        return $this->recoveryRecords->recordRecoveryAction($transactionContext, $recoveryAction);
    }

    public function capacity(TransactionContext $transactionContext, string $rootRef): CapacitySnapshot
    {
        return $this->capacities->capacity($transactionContext, $rootRef);
    }

    public function reserveCapacity(TransactionContext $transactionContext, Reservation $reservation, CapacitySnapshot $expected): CapacitySnapshot
    {
        return $this->capacities->reserveCapacity($transactionContext, $reservation, $expected);
    }

    public function authorization(TransactionContext $transactionContext, string $rootOperationRef): ?AuthorizationRecord
    {
        return $this->capacities->authorization($transactionContext, $rootOperationRef);
    }

    public function applyAuthorization(TransactionContext $transactionContext, AuthorizationRecord $authorizationRecord, int $expectedRevision): void
    {
        $this->capacities->applyAuthorization($transactionContext, $authorizationRecord, $expectedRevision);
    }

    public function insertAttempt(TransactionContext $transactionContext, OperationRecord $operationRecord, string $attemptRef, string $providerReference): AttemptRecord
    {
        return $this->attempts->insertAttempt($transactionContext, $operationRecord, $attemptRef, $providerReference);
    }

    public function insertDispatchWork(TransactionContext $transactionContext, AttemptRecord $attemptRecord, string $workRef, string $handlerVersion): void
    {
        $this->attempts->insertDispatchWork($transactionContext, $attemptRecord, $workRef, $handlerVersion);
    }

    public function claimAttempt(TransactionContext $transactionContext, string $attemptRef, string $leaseOwner, DateTimeImmutable $leaseUntil): AttemptRecord
    {
        return $this->attempts->claimAttempt($transactionContext, $attemptRef, $leaseOwner, $leaseUntil);
    }

    public function cancelAdmitted(TransactionContext $context, OperationRecord $operation, string $attemptRef, string $receiptRef): bool
    {
        return $this->attempts->cancelAdmitted($context, $operation, $attemptRef, $receiptRef);
    }

    public function finishDispatchWork(TransactionContext $context, string $attemptRef, int $expectedFence, WorkState $state, ?DateTimeImmutable $dueAt, ?string $errorCode): bool
    {
        return $this->attempts->finishDispatchWork($context, $attemptRef, $expectedFence, $state, $dueAt, $errorCode);
    }

    public function startDispatch(TransactionContext $transactionContext, DispatchDecision $dispatchDecision): bool
    {
        return $this->attempts->startDispatch($transactionContext, $dispatchDecision);
    }

    public function finishAttempt(TransactionContext $transactionContext, string $attemptRef, int $expectedFence, AttemptState $attemptState, string $evidenceRef): bool
    {
        return $this->attempts->finishAttempt($transactionContext, $attemptRef, $expectedFence, $attemptState, $evidenceRef);
    }

    public function reservation(TransactionContext $context, string $reservationRef): ?Reservation
    {
        return $this->capacities->reservation($context, $reservationRef);
    }

    public function reservationForOperation(TransactionContext $context, string $operationRef): ?Reservation
    {
        return $this->capacities->reservationForOperation($context, $operationRef);
    }

    public function appendEvidence(TransactionContext $transactionContext, ProviderEvidence $providerEvidence): string
    {
        return $this->custody->appendEvidence($transactionContext, $providerEvidence);
    }

    public function providerWork(string $workRef, ?TransactionContext $context = null): ?ProviderWorkInput
    {
        return $this->custody->providerWork($workRef, $context);
    }

    public function enqueueProviderWork(TransactionContext $transactionContext, string $evidenceRef, string $workRef, string $handlerVersion, ?string $operationHint = null): string
    {
        return $this->custody->enqueueProviderWork($transactionContext, $evidenceRef, $workRef, $handlerVersion, $operationHint);
    }

    public function claimProviderWork(TransactionContext $transactionContext, string $workRef, string $leaseOwner, DateTimeImmutable $until): ?ProviderWorkClaim
    {
        return $this->custody->claimProviderWork($transactionContext, $workRef, $leaseOwner, $until);
    }

    public function finishProviderWork(TransactionContext $transactionContext, ProviderWorkClaim $providerWorkClaim, string $state, ?string $resultRef, ?string $errorCode, ?DateTimeImmutable $dueAt): bool
    {
        return $this->custody->finishProviderWork($transactionContext, $providerWorkClaim, $state, $resultRef, $errorCode, $dueAt);
    }

    public function lastObserved(TransactionContext $context, string $operationRef): ?MaterialSnapshot
    {
        return $this->facts->lastObserved($context, $operationRef);
    }

    public function rootOrderingFrontier(TransactionContext $context, string $rootRef, MaterialSnapshot $incoming): ?MaterialSnapshot
    {
        return $this->facts->rootOrderingFrontier($context, $rootRef, $incoming);
    }

    public function latestRootObservation(TransactionContext $context, string $rootRef): ?MaterialSnapshot
    {
        return $this->facts->latestRootObservation($context, $rootRef);
    }

    public function providerOperationId(ExecutionScope $scope, string $attemptRef): ?string
    {
        return $this->facts->providerOperationId($scope, $attemptRef);
    }

    public function operationByProviderId(ExecutionScope $scope, string $providerId, string $merchantAccountRef, string $providerNamespace, string $providerOperationId): ?OperationRecord
    {
        return $this->facts->operationByProviderId($scope, $providerId, $merchantAccountRef, $providerNamespace, $providerOperationId);
    }

    public function bindProviderOperationId(TransactionContext $context, AttemptRecord $attempt, ProviderFact $fact): bool
    {
        return $this->facts->bindProviderOperationId($context, $attempt, $fact);
    }

    public function factByIdentity(TransactionContext $context, string $providerIdentityScope, string $factIdentity): ?ProviderFact
    {
        return $this->facts->factByIdentity($context, $providerIdentityScope, $factIdentity);
    }

    public function factForEvidence(TransactionContext $context, string $evidenceRef, string $operationRef): ?ProviderFact
    {
        return $this->facts->factForEvidence($context, $evidenceRef, $operationRef);
    }

    public function appendFact(TransactionContext $transactionContext, ProviderFact $providerFact): bool
    {
        return $this->facts->appendFact($transactionContext, $providerFact);
    }

    public function applyAttemptFact(TransactionContext $context, AttemptRecord $attempt, ProviderFact $fact, AttemptState $state, ?DateTimeImmutable $lookupDueAt): void
    {
        $this->facts->applyAttemptFact($context, $attempt, $fact, $state, $lookupDueAt);
    }

    public function applyCertifiedAbsence(TransactionContext $context, AttemptRecord $attempt, ProviderWorkClaim $claim, VerifiedAbsence $proof, ?CapacitySnapshot $releasedCapacity): void
    {
        $this->facts->applyCertifiedAbsence($context, $attempt, $claim, $proof, $releasedCapacity);
    }

    public function assertCertifiedDecision(TransactionContext $context, OperationRecord $operation, VerifiedAbsence $proof): void
    {
        $this->facts->assertCertifiedDecision($context, $operation, $proof);
    }

    public function assertMaterialRevision(TransactionContext $context, MaterialChange $change): void
    {
        $this->facts->assertMaterialRevision($context, $change);
    }

    public function appendRevision(TransactionContext $transactionContext, MaterialChange $materialChange, string $receiptRef): void
    {
        $this->facts->appendRevision($transactionContext, $materialChange, $receiptRef);
    }

    public function appendDiscrepancy(TransactionContext $transactionContext, string $ref, MaterialChange $materialChange): void
    {
        $this->facts->appendDiscrepancy($transactionContext, $ref, $materialChange);
    }

    public function recordQuarantineHold(TransactionContext $context, ProviderWorkClaim $claim, MaterialSnapshot $conflict, string $discrepancyRef, string $reason): ?OperationRecord
    {
        return $this->facts->recordQuarantineHold($context, $claim, $conflict, $discrepancyRef, $reason);
    }

    public function settleReservation(TransactionContext $transactionContext, string $reservationRef, ReservationState $reservationState, Money $consumed, ?string $releaseEvidenceRef, CapacitySnapshot $next): void
    {
        $this->facts->settleReservation($transactionContext, $reservationRef, $reservationState, $consumed, $releaseEvidenceRef, $next);
    }

    public function attemptForOperation(ExecutionScope $scope, string $operationRef, ?TransactionContext $context = null): ?AttemptRecord
    {
        return $this->tables->attemptForOperation($scope, $operationRef, $context);
    }

    public function namespace(ExecutionScope $executionScope, ?TransactionContext $context = null): NamespaceRecord
    {
        return $this->namespaces->namespace($executionScope, $context);
    }

    public function lockNamespace(TransactionContext $context, ExecutionScope $scope): NamespaceRecord
    {
        return $this->namespaces->lockNamespace($context, $scope);
    }

    public function transitionNamespace(TransactionContext $transactionContext, ExecutionScope $executionScope, NamespaceState $namespaceState, int $expectedGeneration, string $authorityEvidenceRef): NamespaceRecord
    {
        return $this->namespaces->transitionNamespace($transactionContext, $executionScope, $namespaceState, $expectedGeneration, $authorityEvidenceRef);
    }

    public function insertBinding(TransactionContext $transactionContext, FrozenBinding $frozenBinding): ?OperationRecord
    {
        return $this->admissions->insertBinding($transactionContext, $frozenBinding);
    }

    public function claimChildBinding(TransactionContext $context, AdmissionPlan $plan, string $businessIntentRef, ?string $reasonCode = null, ?string $category = null): ?OperationRecord
    {
        return $this->admissions->claimChildBinding($context, $plan, $businessIntentRef, $reasonCode, $category);
    }

    public function childByIdentity(ExecutionScope $scope, EffectKind $kind, string $sourceContext, string $businessIntentRef, ?TransactionContext $context = null): ?OperationRecord
    {
        return $this->admissions->childByIdentity($scope, $kind, $sourceContext, $businessIntentRef, $context);
    }

    public function insertOperation(TransactionContext $transactionContext, AdmissionPlan $admissionPlan): OperationRecord
    {
        return $this->admissions->insertOperation($transactionContext, $admissionPlan);
    }

    public function recordAdmissionConflict(TransactionContext $context, AdmissionConflict $conflict): string
    {
        return $this->admissions->recordAdmissionConflict($context, $conflict);
    }
}
