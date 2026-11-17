<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Contracts\Data\Money;
use Billium\Core\Contracts\Runner\TransactionContext;
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
use DateTimeImmutable;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/**
 * Every mutation gets current TransactionContext and asserts runner current execution participant + root connection. No begin/commit/runner inside store. Raw evidence/work methods accept only declared RawCustody or nonfinancial work scopes and cannot change money. Financial methods require session admission. IDs discovered before locking are reread; complete plan mismatch throws LockPlanChanged.
 * Use existing platform idempotency via OperationSpec, with commandKind and a versioned bounded JCS hash of authenticated installation, seller, environment, logical kind, intentOwnerNamespace, validated sourceContext and original key. Validate the full semantic command digest independently; global logical business identity excludes installation, seller and command key. Persist canonicalizationVersion in business_binding and immutable operation binding. Never key by untrusted request namespace or fresh worker actor.
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
/** Every mutation gets current TransactionContext and asserts runner current execution participant + root connection. No begin/commit/runner inside store. Raw evidence/work methods accept only declared RawCustody or nonfinancial work scopes and cannot change money. Financial methods require session admission. IDs discovered before locking are reread; complete plan mismatch throws LockPlanChanged. */
interface ExecutionStore
{
    /** read only operation/root/partition/child/capacity/attempt ids; collection owner plan merged externally */
    public function plan(ExecutionScope $executionScope, string $operationRef, bool $includeChildren = false, ?TransactionContext $context = null): ExecutionLockPlan;

    /** acquire preplanned requests via ctx; validate planDigest/current scoped rows and register lock possession for current rootRef */
    public function lock(TransactionContext $transactionContext, ExecutionLockPlan $executionLockPlan): void;

    /** read only */
    public function namespace(ExecutionScope $executionScope, ?TransactionContext $context = null): NamespaceRecord;

    /** Acquire the exact namespace at rank 27 before collection or root rows and reread its state. */
    public function lockNamespace(TransactionContext $context, ExecutionScope $scope): NamespaceRecord;

    /** Retain a refused command after its root rolls back, using only authenticated raw custody. */
    public function recordAdmissionConflict(TransactionContext $context, AdmissionConflict $conflict): string;

    public function cancelAdmitted(TransactionContext $context, OperationRecord $operation, string $attemptRef, string $receiptRef): bool;

    public function finishDispatchWork(TransactionContext $context, string $attemptRef, int $expectedFence, WorkState $state, ?DateTimeImmutable $dueAt, ?string $errorCode): bool;

    /** Restore a locally missing original identity under an externally authenticated, current recovery owner. */
    public function importOriginalIdentity(TransactionContext $ctx, ArchivedExecutionIdentity $identity, bool $verifyOnly = false): ExecutionReceipt;

    /** insert-first durable semantic business identity; null means new binding, existing same digest returns operation, conflict refuses */
    public function insertBinding(TransactionContext $transactionContext, FrozenBinding $frozenBinding): ?OperationRecord;

    /** Claim the child's independent immutable business identity before inserting its operation in the same root. */
    public function claimChildBinding(TransactionContext $context, AdmissionPlan $plan, string $businessIntentRef, ?string $reasonCode = null, ?string $category = null): ?OperationRecord;

    /** Authorized discovery only; admission revalidates the retained original terms under the root lock. */
    public function childByIdentity(ExecutionScope $scope, EffectKind $kind, string $sourceContext, string $businessIntentRef, ?TransactionContext $context = null): ?OperationRecord;

    /** Prepare the first partition in TxA before discovering FeedPartition/Root lock order and inserting a new root. */
    public function prepareObservationPartition(TransactionContext $context, FrozenBinding $binding, string $lineage): ObservationPartition;

    /** operation+immutable root binding; no implicit attempt */
    public function insertOperation(TransactionContext $transactionContext, AdmissionPlan $admissionPlan): OperationRecord;

    /** scoped historical read; cannot imply absent without namespace proof */
    public function operation(ExecutionScope $executionScope, string $string, ?TransactionContext $context = null): ?OperationRecord;

    /** ONE operation attempt */
    public function insertAttempt(TransactionContext $transactionContext, OperationRecord $operationRecord, string $attemptRef, string $providerReference): AttemptRecord;

    /** TxA durable wakeup */
    public function insertDispatchWork(TransactionContext $transactionContext, AttemptRecord $attemptRecord, string $workRef, string $handlerVersion): void;

    /** monotonic fence; state retained */
    public function claimAttempt(TransactionContext $transactionContext, string $attemptRef, string $leaseOwner, DateTimeImmutable $leaseUntil): AttemptRecord;

    /** CAS admitted+expected fence/session to dispatching; true only once */
    public function startDispatch(TransactionContext $transactionContext, DispatchDecision $dispatchDecision): bool;

    /** requires recorded root/capacity lock */
    public function capacity(TransactionContext $transactionContext, string $rootRef): CapacitySnapshot;

    /** atomic checked admission + insert + version compare; implements mechanics, EVIDENCE owns formula and decisions */
    public function reserveCapacity(TransactionContext $transactionContext, Reservation $reservation, CapacitySnapshot $expected): CapacitySnapshot;

    /** CAS version, typed no-effect evidence authorization, no bounds against actual provider totals */
    public function settleReservation(TransactionContext $transactionContext, string $reservationRef, ReservationState $reservationState, Money $consumed, ?string $releaseEvidenceRef, CapacitySnapshot $next): void;

    /** append delivery even stale lease; conflict versions survive */
    public function appendEvidence(TransactionContext $transactionContext, ProviderEvidence $providerEvidence): string;

    /** Return the canonical persisted work reference for this evidence and handler in the custody transaction. */
    public function enqueueProviderWork(TransactionContext $transactionContext, string $evidenceRef, string $workRef, string $handlerVersion, ?string $operationHint = null): string;

    public function providerWork(string $workRef, ?TransactionContext $context = null): ?ProviderWorkInput;

    public function attemptForOperation(ExecutionScope $scope, string $operationRef, ?TransactionContext $context = null): ?AttemptRecord;

    public function reservationForOperation(TransactionContext $context, string $operationRef): ?Reservation;

    public function reservation(TransactionContext $context, string $reservationRef): ?Reservation;

    public function lastObserved(TransactionContext $context, string $operationRef): ?MaterialSnapshot;

    public function latestRootObservation(TransactionContext $context, string $rootRef): ?MaterialSnapshot;

    public function providerOperationId(ExecutionScope $scope, string $attemptRef): ?string;

    public function operationByProviderId(ExecutionScope $scope, string $providerId, string $merchantAccountRef, string $providerNamespace, string $providerOperationId): ?OperationRecord;

    /** Check the signed assignment against the original attempt. A fresh assignment is committed atomically by appendFact. */
    public function bindProviderOperationId(TransactionContext $context, AttemptRecord $attempt, ProviderFact $fact): bool;

    public function factByIdentity(TransactionContext $context, string $providerIdentityScope, string $factIdentity): ?ProviderFact;

    public function factForEvidence(TransactionContext $context, string $evidenceRef, string $operationRef): ?ProviderFact;

    public function applyAttemptFact(TransactionContext $context, AttemptRecord $attempt, ProviderFact $fact, AttemptState $state, ?DateTimeImmutable $lookupDueAt): void;

    public function applyCertifiedAbsence(TransactionContext $context, AttemptRecord $attempt, ProviderWorkClaim $claim, VerifiedAbsence $proof, ?CapacitySnapshot $releasedCapacity): void;

    /** short claim Tx/fence; no reducer network */
    public function claimProviderWork(TransactionContext $transactionContext, string $workRef, string $leaseOwner, DateTimeImmutable $until): ?ProviderWorkClaim;

    /** fence CAS; stale finish does not overwrite */
    public function finishProviderWork(TransactionContext $transactionContext, ProviderWorkClaim $providerWorkClaim, string $state, ?string $resultRef, ?string $errorCode, ?DateTimeImmutable $dueAt): bool;

    /** false economic duplicate; incompatible identity content stored and quarantined; never silently overwrite */
    public function appendFact(TransactionContext $transactionContext, ProviderFact $providerFact): bool;

    /** Authenticate the exact persisted material revision before publishing. */
    public function assertMaterialRevision(TransactionContext $context, MaterialChange $change): void;

    public function assertCertifiedDecision(TransactionContext $context, OperationRecord $operation, VerifiedAbsence $proof): void;

    /** Advance the operation and append its accepted observation in the caller transaction. */
    public function appendRevision(TransactionContext $transactionContext, MaterialChange $materialChange, string $receiptRef): void;

    /** immutable exposure fact; explicit hold */
    public function appendDiscrepancy(TransactionContext $transactionContext, string $ref, MaterialChange $materialChange): void;

    /** insert/find before RootCharge100; no root FK */
    public function ensureObservationPartition(TransactionContext $transactionContext, ExecutionScope $executionScope, string $sourceAuthority, string $lineage, string $rootRef): ObservationPartition;

    /** requires FeedPartition lock already taken; transactional increment */
    public function nextObservationSequence(TransactionContext $transactionContext, int $partitionId): int;

    /** immutable mapping before outbox record */
    public function appendEmission(TransactionContext $transactionContext, ObservationEmission $observationEmission): void;

    /** same root-only completion; row id not commandRef */
    public function bindEmissionCommandRow(TransactionContext $transactionContext, string $commandRef, int $commandRowId): void;

    /** SCHEMA/Merchants uses frozen immutable historical data */
    public function merchantRevision(ExecutionScope $executionScope, string $revisionRef): MerchantRevision;

    /** admin-authorized source, append-only; no credentials fetched under Tx */
    public function appendMerchantRevision(TransactionContext $transactionContext, MerchantRevision $merchantRevision, CapabilitySnapshot $capabilitySnapshot): void;

    /** append-only reconciliation/repair state+receipt; never arbitrary table update */
    public function recordRecoveryAction(TransactionContext $transactionContext, RecoveryAction $recoveryAction): ExecutionReceipt;

    /** root lock; read lifecycle */
    public function authorization(TransactionContext $transactionContext, string $rootOperationRef): ?AuthorizationRecord;

    /** CAS lifecycle and append corresponding operation revision via caller; actual proof required for release */
    public function applyAuthorization(TransactionContext $transactionContext, AuthorizationRecord $authorizationRecord, int $expectedRevision): void;

    /** start audit run; no implied provider completeness */
    public function appendReconciliationRun(TransactionContext $transactionContext, ReconciliationRun $reconciliationRun): void;

    /** cursor/completeness CAS; outcome verified by owner */
    public function advanceReconciliationRun(TransactionContext $transactionContext, ReconciliationRun $reconciliationRun, string $expectedState): void;

    /**
     * Immutable receipt in the same correction transaction.
     *
     * @param  list<string>  $correctionRefs
     */
    public function appendRepairReceipt(TransactionContext $transactionContext, RecoveryAction $recoveryAction, string $beforeDigest, string $afterDigest, array $correctionRefs): ExecutionReceipt;

    /** CAS with verified complete coverage or closure; never implicit open from max key */
    public function transitionNamespace(TransactionContext $transactionContext, ExecutionScope $executionScope, NamespaceState $namespaceState, int $expectedGeneration, string $authorityEvidenceRef): NamespaceRecord;

    /** CAS lease metadata only; stale response evidence preserved separately; no reversal of newer material state */
    public function finishAttempt(TransactionContext $transactionContext, string $attemptRef, int $expectedFence, AttemptState $attemptState, string $evidenceRef): bool;

    /** Authenticated scoped read of committed dispatch state; never claims a lease or grants authority. */
    public function attempt(ExecutionScope $executionScope, string $attemptRef, ?TransactionContext $context = null): ?AttemptRecord;
}
