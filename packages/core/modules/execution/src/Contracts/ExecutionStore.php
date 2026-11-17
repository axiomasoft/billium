<?php

declare(strict_types=1);

namespace Billium\Execution\Contracts;

use Billium\Contracts\Data\Money;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Execution\Data\AdmissionPlan;
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
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\NamespaceRecord;
use Billium\Execution\Data\ObservationEmission;
use Billium\Execution\Data\ObservationPartition;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ProviderFact;
use Billium\Execution\Data\ProviderWorkClaim;
use Billium\Execution\Data\ReconciliationRun;
use Billium\Execution\Data\RecoveryAction;
use Billium\Execution\Data\Reservation;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Enums\ReservationState;
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
    public function plan(ExecutionScope $executionScope, string $operationRef, bool $includeChildren = false): ExecutionLockPlan;

    /** acquire preplanned requests via ctx; validate planDigest/current scoped rows and register lock possession for current rootRef */
    public function lock(TransactionContext $transactionContext, ExecutionLockPlan $executionLockPlan): void;

    /** read only */
    public function namespace(ExecutionScope $executionScope): NamespaceRecord;

    /** insert-first durable semantic business identity; null means new binding, existing same digest returns operation, conflict refuses */
    public function insertBinding(TransactionContext $transactionContext, FrozenBinding $frozenBinding): ?OperationRecord;

    /** operation+immutable root binding; no implicit attempt */
    public function insertOperation(TransactionContext $transactionContext, AdmissionPlan $admissionPlan): OperationRecord;

    /** scoped historical read; cannot imply absent without namespace proof */
    public function operation(ExecutionScope $executionScope, string $string): ?OperationRecord;

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

    /** same custody Tx */
    public function enqueueProviderWork(TransactionContext $transactionContext, string $evidenceRef, string $workRef, string $handlerVersion): void;

    /** short claim Tx/fence; no reducer network */
    public function claimProviderWork(TransactionContext $transactionContext, string $workRef, string $leaseOwner, DateTimeImmutable $until): ?ProviderWorkClaim;

    /** fence CAS; stale finish does not overwrite */
    public function finishProviderWork(TransactionContext $transactionContext, ProviderWorkClaim $providerWorkClaim, string $state, ?string $resultRef, ?string $errorCode, ?DateTimeImmutable $dueAt): bool;

    /** false economic duplicate; incompatible identity content stored and quarantined; never silently overwrite */
    public function appendFact(TransactionContext $transactionContext, ProviderFact $providerFact): bool;

    /** update operation material state + append revision; no emission implicit outside caller */
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
    public function attempt(ExecutionScope $executionScope, string $attemptRef): ?AttemptRecord;
}
