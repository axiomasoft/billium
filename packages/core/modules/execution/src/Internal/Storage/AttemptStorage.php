<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Storage;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\DispatchDecision;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\WorkState;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use DateTimeImmutable;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

/** Provider attempts and their dispatch work: creation, leasing, dispatch start, completion and pre-dispatch cancellation. */
#[Internal]
final readonly class AttemptStorage
{
    public function __construct(private ExecutionTables $tables, private MerchantRevisionStorage $merchants, private CapacityStorage $capacities, private FinancialSessionAdmission $sessionAdmission, private OperationRevisionLog $revisions) {}

    public function insertAttempt(TransactionContext $transactionContext, OperationRecord $operationRecord, string $attemptRef, string $providerReference): AttemptRecord
    {
        $this->tables->financial($transactionContext);
        $s = $operationRecord->binding->scope;
        $this->tables->assertScope($s, $transactionContext, $operationRecord->binding->executionProfile);
        $row = $this->tables->operation($s, $operationRecord->operationRef, $transactionContext);
        if (ExecutionCodec::differs($row, $operationRecord)) {
            ExecutionTables::refuse('Operation changed before attempt');
        }
        $merchant = $this->merchants->merchantRevision($s, $operationRecord->binding->merchantRevisionRef, $transactionContext);
        $digest = AuthorityClaims::immutableDriverRequestDigest($operationRecord->binding, $operationRecord->operationRef, $attemptRef, $providerReference, $operationRecord->kind, $operationRecord->requested);
        $id = $this->tables->database($transactionContext)->table('billium_execution_attempt')->insertGetId(ExecutionTables::scope($s) + ['attempt_ref' => $attemptRef, 'operation_ref' => $operationRecord->operationRef, 'provider_reference' => $providerReference, 'provider_id' => $merchant->providerId, 'merchant_account_ref' => $merchant->merchantAccountRef, 'request_digest' => $digest]);

        return new AttemptRecord($id, $attemptRef, $operationRecord->operationRef, $providerReference, AttemptState::Admitted, 0, null, null, null, null, null, $digest, null, null, null);
    }

    public function insertDispatchWork(TransactionContext $transactionContext, AttemptRecord $attemptRecord, string $workRef, string $handlerVersion): void
    {
        $this->tables->financial($transactionContext);
        $attempt = $this->tables->callerQuery('attempt', $transactionContext)->where('attempt_ref', $attemptRecord->attemptRef)->where('operation_ref', $attemptRecord->operationRef)->first() ?? throw new LogicException('Attempt absent');
        $this->tables->admittedOperation($transactionContext, ExecutionRows::text($attempt->operation_ref));
        $this->tables->database($transactionContext)->table('billium_execution_dispatch_work')->insert($this->tables->callerScope($transactionContext) + ['work_ref' => $workRef, 'operation_ref' => $attemptRecord->operationRef, 'attempt_ref' => $attemptRecord->attemptRef, 'handler_version' => $handlerVersion]);
    }

    public function claimAttempt(TransactionContext $transactionContext, string $attemptRef, string $leaseOwner, DateTimeImmutable $leaseUntil): AttemptRecord
    {
        $this->tables->financial($transactionContext);
        $q = $this->tables->callerQuery('attempt', $transactionContext)->where('attempt_ref', $attemptRef);
        $r = (clone $q)->first() ?? throw new LogicException('Attempt absent');
        $this->tables->requireOperationLock($transactionContext, ExecutionRows::text($r->operation_ref));
        $until = ExecutionRows::stamp($leaseUntil);
        $fence = ExecutionRows::integer($r->lease_fence) + 1;
        $work = $this->tables->callerQuery('dispatch_work', $transactionContext)->where('attempt_ref', $attemptRef)->where('operation_ref', $r->operation_ref)
            ->where('lease_fence', $fence - 1)->whereRaw('?::timestamptz > clock_timestamp()', [$until])
            ->whereRaw("((state = 'due' AND due_at <= clock_timestamp()) OR (state = 'leased' AND lease_expires_at <= clock_timestamp()))");
        if ($leaseOwner === '' || ! in_array($r->state, ['admitted', 'dispatching', 'pending', 'unknown'], true)
            || $work->incrementEach(['attempt_count' => 1], ['state' => 'leased', 'lease_owner' => $leaseOwner, 'lease_fence' => $fence, 'lease_expires_at' => $until]) !== 1) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Dispatch work is not available for this attempt');
        }
        $q->update(['lease_owner' => $leaseOwner, 'lease_fence' => $fence, 'lease_expires_at' => $until]);

        return ExecutionRecords::attempt($q->first() ?? throw new LogicException('Attempt absent'));
    }

    public function cancelAdmitted(TransactionContext $context, OperationRecord $operation, string $attemptRef, string $receiptRef): bool
    {
        $this->tables->financial($context);
        $current = $this->tables->admittedOperation($context, $operation->operationRef);
        $this->tables->requireLock($context, $current->rootOperationRef);
        if ($receiptRef === '' || ExecutionCodec::differs($current, $operation) || $current->rootOperationRef !== $current->operationRef
            || ! in_array($current->kind, [EffectKind::Charge, EffectKind::Authorize], true) || $current->state !== 'admitted') {
            return false;
        }
        $attempt = $this->tables->callerQuery('attempt', $context)->where('attempt_ref', $attemptRef)->where('operation_ref', $current->operationRef);
        if ($attempt->where('state', 'admitted')->whereNull('started_at')->whereNull('dispatch_ticket_ref')->whereNull('collection_dispatch_permit_ref')
            ->update(['state' => 'cancelled', 'finish_evidence_ref' => $receiptRef, 'lease_owner' => null, 'lease_expires_at' => null]) !== 1) {
            return false;
        }
        $work = $this->tables->callerQuery('dispatch_work', $context)->where('operation_ref', $current->operationRef)->where('attempt_ref', $attemptRef);
        if ($work->whereIn('state', ['due', 'leased'])->update(['state' => 'processed', 'lease_owner' => null, 'lease_expires_at' => null, 'due_at' => null]) !== 1) {
            throw new LogicException('Local cancellation requires the original dispatch work');
        }
        $revision = $current->revision + 1;
        $material = ['schema' => 'billium.execution.cancel-before-dispatch.v1', 'operationRef' => $current->operationRef, 'attemptRef' => $attemptRef,
            'originalBindingDigest' => AuthorityClaims::bindingDigest($current->binding), 'receiptRef' => $receiptRef];
        $digest = PaymentIdentity::digest($material);
        $this->tables->callerQuery('operation', $context)->where('id', $current->id)->update(['state' => 'cancelled', 'revision' => $revision, 'economic_digest' => $digest]);
        $capacity = $this->capacities->capacity($context, $current->rootOperationRef);
        $this->revisions->append($context, $current, $revision, $digest, $material, $capacity, '{}', [$receiptRef], $receiptRef, new DateTimeImmutable);

        return true;
    }

    public function finishDispatchWork(TransactionContext $context, string $attemptRef, int $expectedFence, WorkState $state, ?DateTimeImmutable $dueAt, ?string $errorCode): bool
    {
        $this->tables->financial($context);
        if ($state === WorkState::Leased || (($state === WorkState::Due) !== ($dueAt !== null))) {
            ExecutionTables::refuse('Invalid dispatch work completion');
        }
        $q = $this->tables->callerQuery('attempt', $context)->where('attempt_ref', $attemptRef);
        $attempt = (clone $q)->first() ?? throw new LogicException('Attempt absent');
        $this->tables->requireOperationLock($context, ExecutionRows::text($attempt->operation_ref));
        if (ExecutionRows::integer($attempt->lease_fence) !== $expectedFence || $attempt->lease_owner === null) {
            return false;
        }
        $work = $this->tables->callerQuery('dispatch_work', $context)->where('operation_ref', $attempt->operation_ref)->where('attempt_ref', $attemptRef)
            ->where('state', 'leased')->where('lease_owner', $attempt->lease_owner)->where('lease_fence', $expectedFence)->whereRaw('lease_expires_at > clock_timestamp()');
        if (! (clone $work)->exists()) {
            return false;
        }
        if ($state === WorkState::Processed && ! $this->hasQueuedEvidence($context, $attempt)) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Dispatch completion requires original durable provider evidence work');
        }
        if ($work->update(['state' => $state->value, 'due_at' => ExecutionRows::nullableStamp($dueAt), 'lease_owner' => null,
            'lease_expires_at' => null, 'last_error_code' => $errorCode, 'last_error_at' => $errorCode === null ? null : new DateTimeImmutable]) !== 1) {
            return false;
        }
        $q->update(['lease_owner' => null, 'lease_expires_at' => null, 'dispatch_ticket_ref' => null]);

        return true;
    }

    public function startDispatch(TransactionContext $transactionContext, DispatchDecision $dispatchDecision): bool
    {
        $this->tables->financial($transactionContext);
        $this->tables->requireOperationLock($transactionContext, $dispatchDecision->operationRef);
        $operation = $this->tables->admittedOperation($transactionContext, $dispatchDecision->operationRef);
        $attempt = $this->tables->attempt($operation->binding->scope, $dispatchDecision->attemptRef, $transactionContext);
        if ($attempt === null || $attempt->operationRef !== $operation->operationRef
            || AuthorityClaims::immutableDriverRequestDigest($operation->binding, $operation->operationRef, $attempt->attemptRef, $attempt->providerReference, $operation->kind, $operation->requested) !== $dispatchDecision->immutableRequestDigest) {
            return false;
        }
        $collectionAuthorization = AuthorityClaims::collectionPermitAuthorizationRef($operation->binding, $operation->kind);
        if ($dispatchDecision->sessionEpoch !== $transactionContext->sessionEpoch() || $dispatchDecision->requestDeadlineAt === null
            || $operation->state !== 'admitted' || (($collectionAuthorization === null) !== ($dispatchDecision->collectionDispatchPermitRef === null))) {
            return false;
        }

        $token = $this->sessionAdmission->currentToken($transactionContext->caller()->installationRef);
        if ($token === null || $token->installationRef !== $transactionContext->caller()->installationRef || $token->expired(new DateTimeImmutable) || ! $token->permits($transactionContext->spec()->mode)) {
            throw new LogicException('Cached admitted owner identity is absent');
        }

        $deadline = ExecutionRows::stamp($dispatchDecision->requestDeadlineAt);

        return $this->tables->callerQuery('attempt', $transactionContext)->where('attempt_ref', $dispatchDecision->attemptRef)->where('operation_ref', $dispatchDecision->operationRef)
            ->where('state', 'admitted')->where('request_digest', $dispatchDecision->immutableRequestDigest)->where('lease_fence', $dispatchDecision->leaseFence)
            ->whereNotNull('lease_owner')->where('lease_expires_at', '>=', $deadline)->whereRaw('?::timestamptz > clock_timestamp()', [$deadline])
            ->update(['state' => 'dispatching', 'session_epoch' => $transactionContext->sessionEpoch(), 'owner_session_ref' => $token->ownerSessionRef,
                'dispatch_ticket_ref' => $dispatchDecision->ticketRef, 'collection_dispatch_permit_ref' => $dispatchDecision->collectionDispatchPermitRef,
                'started_at' => $this->tables->database($transactionContext)->raw('clock_timestamp()'), 'request_deadline_at' => $deadline]) === 1;
    }

    public function finishAttempt(TransactionContext $transactionContext, string $attemptRef, int $expectedFence, AttemptState $attemptState, string $evidenceRef): bool
    {
        $this->tables->financial($transactionContext);
        $evidence = $this->tables->callerQuery('provider_evidence', $transactionContext)->where('evidence_ref', $evidenceRef)->first() ?? throw new LogicException('Attempt finish evidence absent');
        $q = $this->tables->callerQuery('attempt', $transactionContext)->where('attempt_ref', $attemptRef);
        $row = (clone $q)->first() ?? throw new LogicException('Attempt absent');
        $this->tables->requireOperationLock($transactionContext, ExecutionRows::text($row->operation_ref));
        if (ExecutionRows::integer($row->lease_fence) !== $expectedFence) {
            return false;
        }
        if ($row->merchant_account_ref !== $evidence->merchant_account_ref || $row->provider_id !== $evidence->provider_id
            || $evidence->intent_owner_namespace !== $this->tables->selectedScope($transactionContext)->intentOwnerNamespace
            || ! in_array($attemptState, [AttemptState::Pending, AttemptState::Unknown], true)
            || $this->transportData($evidence, $row, $expectedFence) === null) {
            ExecutionTables::refuse('Attempt completion requires the current scoped transport observation');
        }

        return $q->where('lease_fence', $expectedFence)->whereRaw('lease_expires_at > clock_timestamp()')->whereIn('state', ['dispatching', 'pending', 'unknown'])->update(['state' => $attemptState->value, 'finish_evidence_ref' => $evidenceRef]) === 1;
    }

    private function hasQueuedEvidence(TransactionContext $context, stdClass $attempt): bool
    {
        $scope = $this->tables->selectedScope($context);
        $records = $this->tables->callerQuery('provider_evidence', $context)->where('intent_owner_namespace', $scope->intentOwnerNamespace)
            ->where('provider_id', $attempt->provider_id)->where('merchant_account_ref', $attempt->merchant_account_ref);
        $record = (clone $records)->where('evidence_ref', $attempt->finish_evidence_ref)->first();
        if ($record === null) {
            return false;
        }
        $data = $this->transportData($record, $attempt, ExecutionRows::integer($attempt->lease_fence));
        if ($data === null) {
            return false;
        }
        if ($record->signature_verdict === 'verified_no_effect' && ($data['verifiedAbsence'] ?? null) === true) {
            $evidence = $record;
        } else {
            $ref = $data['retainedProviderEvidenceRef'] ?? null;
            if ($record->signature_verdict !== 'local_transport' || ! is_string($ref)) {
                return false;
            }
            $evidence = $records->where('evidence_ref', $ref)->first();
        }

        return $evidence !== null && $evidence->signature_verdict !== 'quarantined_conflict'
            && $this->tables->callerQuery('provider_work', $context)->where('evidence_ref', $evidence->evidence_ref)
                ->where('handler_contract_version', $evidence->handler_contract_version)->where('state', '<>', 'quarantined')->exists();
    }

    /** @return array<string,mixed>|null */
    private function transportData(stdClass $record, stdClass $attempt, int $fence): ?array
    {
        if ($record->handler_contract_version !== 'execution.transport.v1' || ! in_array($record->signature_verdict, ['local_transport', 'verified_no_effect'], true)) {
            return null;
        }
        $data = ExecutionCodec::map(ExecutionRows::text($record->sanitized_payload));
        if (($data['schema'] ?? null) !== 'billium.execution.transport-observation.v1' || ($data['operationRef'] ?? null) !== $attempt->operation_ref
            || ($data['attemptRef'] ?? null) !== $attempt->attempt_ref || ($data['providerReference'] ?? null) !== $attempt->provider_reference
            || ($data['leaseFence'] ?? null) !== (string) $fence) {
            return null;
        }

        return $data;
    }
}
