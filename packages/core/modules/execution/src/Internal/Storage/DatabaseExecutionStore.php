<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Storage;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Execution\Contracts\ExecutionStore;
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
use Billium\Execution\Data\MerchantRevisionPolicy;
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
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Enums\ReservationState;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;
use WeakMap;

#[Internal]
final class DatabaseExecutionStore implements ExecutionStore
{
    /** @var WeakMap<TransactionContext,array<string,bool>> */
    private WeakMap $held;

    public function __construct(private readonly DatabaseManager $databases, private readonly OperationRunner $runner, private readonly CallerContext $callers, private readonly BilliumOptions $options, private readonly FinancialSessionAdmission $sessionAdmission)
    {
        $this->held = new WeakMap;
    }

    public function plan(ExecutionScope $executionScope, string $operationRef, bool $includeChildren = false): ExecutionLockPlan
    {
        $this->assertScope($executionScope);
        $operation = $this->operation($executionScope, $operationRef);
        if ($operation === null) {
            return new ExecutionLockPlan($executionScope, $operationRef, [], ['execution', 'platform'], ExecutionCodec::digest([$executionScope, $operationRef, []]));
        }
        $root = $operation->rootOperationRef;
        $locks = [];
        $parts = $this->query('observation_partition', $executionScope)->where('root_operation_ref', $root)->orderBy('id')->pluck('id')->all();
        if ($parts !== []) {
            $locks[] = new LockRequest(LockRank::FeedPartition, 'billium_execution_observation_partition', array_values(array_map(self::integer(...), $parts)));
        }
        $row = $this->query('operation', $executionScope)->where('operation_ref', $root)->first();
        if ($row !== null) {
            $locks[] = new LockRequest(LockRank::RootCharge, 'billium_execution_operation', [self::integer($row->id)]);
        }

        $children = $this->query('operation', $executionScope)->where('root_operation_ref', $root)->where('operation_ref', '<>', $root);
        $ids = array_values(array_map(self::integer(...), $children->orderBy('id')->pluck('id')->all()));
        if ($ids !== []) {
            $locks[] = new LockRequest(LockRank::ChildEffect, 'billium_execution_operation', $ids);
        }

        $capacity = $this->query('capacity', $executionScope)->where('root_operation_ref', $root)->first();
        if ($capacity !== null) {
            $locks[] = new LockRequest(LockRank::Capacity, 'billium_execution_capacity', [self::integer($capacity->id)]);
        }
        $attemptIds = $this->query('attempt', $executionScope)->whereIn('operation_ref', $this->query('operation', $executionScope)->where('root_operation_ref', $root)->pluck('operation_ref')->all())->orderBy('id')->pluck('id')->all();
        if ($attemptIds !== []) {
            $locks[] = new LockRequest(LockRank::Attempt, 'billium_execution_attempt', array_values(array_map(self::integer(...), $attemptIds)));
        }

        return new ExecutionLockPlan($executionScope, $root, $locks, ['execution', 'platform'], ExecutionCodec::digest([$executionScope, $root, $locks]));
    }

    public function lock(TransactionContext $transactionContext, ExecutionLockPlan $executionLockPlan): void
    {
        $this->database($transactionContext);
        $this->assertScope($executionLockPlan->scope, $transactionContext);
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
        $current = $this->plan($executionLockPlan->scope, $executionLockPlan->rootOperationRef, true);
        if ($current->planDigest !== $executionLockPlan->planDigest) {
            throw new LockPlanChanged('Execution row plan changed under locks');
        }
        $held = $this->held[$transactionContext] ?? [];
        if ($this->operation($executionLockPlan->scope, $executionLockPlan->rootOperationRef) !== null) {
            $this->admittedOperation($transactionContext, $executionLockPlan->rootOperationRef);
            $held[$executionLockPlan->rootOperationRef] = true;
        }
        foreach ($executionLockPlan->locks as $request) {
            foreach ($request->keys as $id) {
                $held[$request->table.':'.$id] = true;
            }
        }
        $this->held[$transactionContext] = $held;
    }

    public function namespace(ExecutionScope $executionScope): NamespaceRecord
    {
        $row = $this->query('namespace', $executionScope)->where('intent_owner_namespace', $executionScope->intentOwnerNamespace)->first();
        if ($row === null) {
            return new NamespaceRecord($executionScope, NamespaceState::RecoveryRequired, 'execution.intent.v1', null, null, 0);
        }

        return new NamespaceRecord($executionScope, NamespaceState::from(self::text($row->state)), self::text($row->canonicalization_version), self::date($row->history_from), self::nullableText($row->completeness_receipt_ref), self::integer($row->generation));
    }

    public function insertBinding(TransactionContext $transactionContext, FrozenBinding $frozenBinding): ?OperationRecord
    {
        $this->financial($transactionContext);
        $this->assertScope($frozenBinding->scope, $transactionContext, $frozenBinding->executionProfile);
        $namespace = $this->namespace($frozenBinding->scope);
        if ($namespace->state !== NamespaceState::Open || $namespace->canonicalizationVersion !== $frozenBinding->canonicalizationVersion) {
            $this->refuse('Business namespace is not proven open');
        }
        $identity = [
            'intent_owner_namespace' => $frozenBinding->scope->intentOwnerNamespace,
            'environment' => $frozenBinding->scope->environment,
            'source_context' => $frozenBinding->sourceContext,
            'business_intent_ref' => $frozenBinding->businessIntentRef,
            'operation_kind' => 'charge',
        ];
        $row = $this->scope($frozenBinding->scope) + $identity + ['semantic_digest' => $frozenBinding->semanticDigest, 'canonicalization_version' => $frozenBinding->canonicalizationVersion, 'operation_ref' => $frozenBinding->rootOperationRef, 'binding' => ExecutionCodec::encode($frozenBinding), 'created_at' => new DateTimeImmutable];
        $db = $this->database($transactionContext);
        $inserted = $db->table('billium_execution_business_binding')->insertOrIgnore($row);
        if ($inserted === 1) {
            return null;
        }
        $existing = $db->table('billium_execution_business_binding')->where('business_identity_digest', ExecutionCodec::businessIdentityDigest($frozenBinding, 'charge'))->where($identity)->first();
        if ($existing === null || $existing->tombstone_reason !== null || self::text($existing->installation_ref) !== $frozenBinding->scope->installationRef
            || self::text($existing->seller_ref) !== $frozenBinding->scope->sellerRef
            || ExecutionCodec::encode(ExecutionCodec::map(self::text($existing->binding))) !== ExecutionCodec::encode($frozenBinding)) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'Business identity conflicts with immutable binding');
        }

        return $this->operation($frozenBinding->scope, self::text($existing->operation_ref)) ?? throw new LogicException('Existing business binding has no operation');
    }

    public function insertOperation(TransactionContext $transactionContext, AdmissionPlan $admissionPlan): OperationRecord
    {
        $this->financial($transactionContext);
        $b = $admissionPlan->binding;
        $this->assertScope($admissionPlan->scope, $transactionContext, $b->executionProfile);
        if (ExecutionCodec::encode($admissionPlan->scope) !== ExecutionCodec::encode($b->scope) || $admissionPlan->amount->minor <= 0 || Currency::of($admissionPlan->amount->currency->code)->scale !== $admissionPlan->amount->currency->scale) {
            $this->refuse('Invalid binding, amount or currency precision');
        }
        $merchant = $this->merchantRevision($b->scope, $b->merchantRevisionRef);
        if ([$merchant->merchantAccountRef, $merchant->driverId, $merchant->driverContractVersion, $merchant->capabilityDigest] !== [$b->merchantAccountRef, $b->driverId, $b->driverContractVersion, $b->capabilityDigest]) {
            $this->refuse('Merchant identity substitution');
        }
        $cap = $this->capability($b->scope, $merchant->capabilitySnapshotRef);
        $cap->requireEffect($admissionPlan->kind, $admissionPlan->amount->currency->code, $b->scope->environment === 'production');
        if (! in_array($admissionPlan->amount->currency->code, $merchant->currencyCodes, true)) {
            $this->refuse('Merchant currency unsupported');
        }
        if (in_array($admissionPlan->kind, [EffectKind::Charge, EffectKind::Authorize], true)) {
            $existing = $this->operation($b->scope, $admissionPlan->operationRef);
            if ($existing !== null) {
                if ($existing->kind !== $admissionPlan->kind || ExecutionCodec::encode($existing->requested) !== ExecutionCodec::encode($admissionPlan->amount)
                    || ExecutionCodec::encode($existing->binding) !== ExecutionCodec::encode($b) || $admissionPlan->semanticDigest !== $b->semanticDigest
                    || $admissionPlan->canonicalizationVersion !== $b->canonicalizationVersion) {
                    throw new ProblemException(ProblemCode::IdempotencyConflict, 'Business identity conflicts with immutable binding');
                }

                return $existing;
            }
            $now = new DateTimeImmutable;
            $enabled = $this->query('merchant_lifecycle_event', $b->scope, $transactionContext)->where('revision_ref', $merchant->revisionRef)->where('purpose', 'new')->where('effective_at', '<=', $now)->orderByDesc('effective_at')->orderByDesc('id')->value('action');
            if ($merchant->policy->validFrom > $now || ($merchant->policy->validUntil !== null && $merchant->policy->validUntil <= $now) || $enabled !== 'enable_new') {
                $this->refuse('New merchant binding is not explicitly enabled/effective');
            }
            $identity = $this->query('business_binding', $b->scope, $transactionContext)->where('operation_ref', $admissionPlan->operationRef)->where('source_context', $b->sourceContext)->where('business_intent_ref', $b->businessIntentRef)->where('intent_owner_namespace', $b->scope->intentOwnerNamespace)->where('semantic_digest', $admissionPlan->semanticDigest)->whereNull('tombstone_reason')->first();
            if ($identity === null || $admissionPlan->canonicalizationVersion !== $b->canonicalizationVersion || ExecutionCodec::encode(ExecutionCodec::map(self::text($identity->binding))) !== ExecutionCodec::encode($b)) {
                $this->refuse('Root requires durable business binding');
            }
        } else {
            $root = $this->operation($b->scope, $b->rootOperationRef) ?? throw new LogicException('Child root missing');
            $this->requireLock($transactionContext, $root->rootOperationRef);
            if (ExecutionCodec::encode($root->binding) !== ExecutionCodec::encode($b) || ExecutionCodec::encode($root->requested->currency) !== ExecutionCodec::encode($admissionPlan->amount->currency)) {
                $this->refuse('Child binding differs from historical root');
            }
        }
        $now = new DateTimeImmutable;
        $row = $this->scope($b->scope) + ['operation_ref' => $admissionPlan->operationRef, 'root_operation_ref' => $b->rootOperationRef, 'kind' => $admissionPlan->kind->value, 'binding' => ExecutionCodec::encode($b), 'binding_digest' => ExecutionCodec::bindingDigest($b), 'source_context' => $b->sourceContext, 'business_intent_ref' => $b->businessIntentRef, 'intent_owner_namespace' => $b->scope->intentOwnerNamespace, 'merchant_revision_ref' => $b->merchantRevisionRef, 'merchant_account_ref' => $b->merchantAccountRef, 'driver_id' => $b->driverId, 'driver_contract_version' => $b->driverContractVersion, 'capability_digest' => $b->capabilityDigest, 'requested_minor' => $admissionPlan->amount->minor, 'currency' => $admissionPlan->amount->currency->code, 'currency_exponent' => $admissionPlan->amount->currency->scale, 'state' => 'admitted', 'revision' => 0, 'economic_digest' => $admissionPlan->semanticDigest, 'collection_authorization_ref' => $b->collectionAuthorizationRef, 'created_at' => $now];
        $id = $this->database($transactionContext)->table('billium_execution_operation')->insertGetId($row);
        if ($admissionPlan->operationRef === $b->rootOperationRef) {
            $this->database($transactionContext)->table('billium_execution_capacity')->insert($this->scope($b->scope) + ['root_operation_ref' => $b->rootOperationRef, 'currency' => $admissionPlan->amount->currency->code]);
        }

        return $this->operationRow($this->query('operation', $b->scope, $transactionContext)->where('id', $id)->first() ?? throw new LogicException('Inserted operation is not visible on current root connection'));
    }

    public function operation(ExecutionScope $executionScope, string $string): ?OperationRecord
    {
        $r = $this->query('operation', $executionScope)->where('operation_ref', $string)->first();

        return $r === null ? null : $this->operationRow($r);
    }

    public function attempt(ExecutionScope $executionScope, string $attemptRef): ?AttemptRecord
    {
        $r = $this->query('attempt', $executionScope)->where('attempt_ref', $attemptRef)->first();

        return $r === null ? null : $this->attemptRow($r);
    }

    public function insertAttempt(TransactionContext $transactionContext, OperationRecord $operationRecord, string $attemptRef, string $providerReference): AttemptRecord
    {
        $this->financial($transactionContext);
        $s = $operationRecord->binding->scope;
        $this->assertScope($s, $transactionContext, $operationRecord->binding->executionProfile);
        $row = $this->operation($s, $operationRecord->operationRef);
        if (ExecutionCodec::encode($row) !== ExecutionCodec::encode($operationRecord)) {
            $this->refuse('Operation changed before attempt');
        }
        $merchant = $this->merchantRevision($s, $operationRecord->binding->merchantRevisionRef);
        $id = $this->database($transactionContext)->table('billium_execution_attempt')->insertGetId($this->scope($s) + ['attempt_ref' => $attemptRef, 'operation_ref' => $operationRecord->operationRef, 'provider_reference' => $providerReference, 'provider_id' => $merchant->providerId, 'merchant_account_ref' => $merchant->merchantAccountRef, 'request_digest' => $operationRecord->economicDigest]);

        return new AttemptRecord($id, $attemptRef, $operationRecord->operationRef, $providerReference, AttemptState::Admitted, 0, null, null, null, null, null);
    }

    public function insertDispatchWork(TransactionContext $transactionContext, AttemptRecord $attemptRecord, string $workRef, string $handlerVersion): void
    {
        $this->financial($transactionContext);
        $attempt = $this->callerQuery('attempt', $transactionContext)->where('attempt_ref', $attemptRecord->attemptRef)->where('operation_ref', $attemptRecord->operationRef)->first() ?? throw new LogicException('Attempt absent');
        $this->admittedOperation($transactionContext, self::text($attempt->operation_ref));
        $this->database($transactionContext)->table('billium_execution_dispatch_work')->insert($this->callerScope($transactionContext) + ['work_ref' => $workRef, 'operation_ref' => $attemptRecord->operationRef, 'attempt_ref' => $attemptRecord->attemptRef, 'handler_version' => $handlerVersion]);
    }

    public function claimAttempt(TransactionContext $transactionContext, string $attemptRef, string $leaseOwner, DateTimeImmutable $leaseUntil): AttemptRecord
    {
        $this->financial($transactionContext);
        $q = $this->callerQuery('attempt', $transactionContext)->where('attempt_ref', $attemptRef);
        $r = (clone $q)->lockForUpdate()->first() ?? throw new LogicException('Attempt absent');
        $this->requireOperationLock($transactionContext, self::text($r->operation_ref));
        if ($leaseOwner === '' || $leaseUntil <= new DateTimeImmutable || ($r->lease_expires_at !== null && new DateTimeImmutable(self::text($r->lease_expires_at)) > new DateTimeImmutable)) {
            $this->refuse('Attempt lease unavailable');
        }
        $q->update(['lease_owner' => $leaseOwner, 'lease_fence' => self::integer($r->lease_fence) + 1, 'lease_expires_at' => $leaseUntil]);

        return $this->attemptRow($q->first() ?? throw new LogicException('Attempt absent'));
    }

    public function startDispatch(TransactionContext $transactionContext, DispatchDecision $dispatchDecision): bool
    {
        $this->financial($transactionContext);
        $this->requireOperationLock($transactionContext, $dispatchDecision->operationRef);
        if ($dispatchDecision->sessionEpoch !== $transactionContext->sessionEpoch()) {
            return false;
        }

        $token = $this->sessionAdmission->currentToken($transactionContext->caller()->installationRef);
        if ($token === null || $token->installationRef !== $transactionContext->caller()->installationRef || $token->expired(new DateTimeImmutable) || ! $token->permits($transactionContext->spec()->mode)) {
            throw new LogicException('Cached admitted owner identity is absent');
        }

        return $this->callerQuery('attempt', $transactionContext)->where('attempt_ref', $dispatchDecision->attemptRef)->where('operation_ref', $dispatchDecision->operationRef)->where('state', 'admitted')->where('request_digest', $dispatchDecision->immutableRequestDigest)->where('lease_fence', $dispatchDecision->leaseFence)->where('lease_expires_at', '>', new DateTimeImmutable)->update(['state' => 'dispatching', 'session_epoch' => $transactionContext->sessionEpoch(), 'owner_session_ref' => $token->ownerSessionRef, 'dispatch_ticket_ref' => $dispatchDecision->ticketRef, 'started_at' => new DateTimeImmutable]) === 1;
    }

    public function capacity(TransactionContext $transactionContext, string $rootRef): CapacitySnapshot
    {
        $this->financial($transactionContext);
        $this->requireLock($transactionContext, $rootRef);

        return $this->capacityRow($this->callerQuery('capacity', $transactionContext)->where('root_operation_ref', $rootRef)->first() ?? throw new LogicException('Capacity absent'));
    }

    public function reserveCapacity(TransactionContext $transactionContext, Reservation $reservation, CapacitySnapshot $expected): CapacitySnapshot
    {
        $current = $this->capacity($transactionContext, $reservation->rootOperationRef);
        if (ExecutionCodec::encode($current) !== ExecutionCodec::encode($expected) || $reservation->state !== ReservationState::Held || $reservation->consumed->minor !== 0 || $reservation->requested->minor <= 0 || ExecutionCodec::encode($reservation->requested->currency) !== ExecutionCodec::encode($current->authorizedLimit->currency) || $current->financialHold) {
            $this->refuse('Capacity reservation conflict');
        }
        $this->database($transactionContext)->table('billium_execution_reservation')->insert($this->callerScope($transactionContext) + ['reservation_ref' => $reservation->reservationRef, 'root_operation_ref' => $reservation->rootOperationRef, 'operation_ref' => $reservation->operationRef, 'kind' => $reservation->kind->value, 'requested_minor' => $reservation->requested->minor, 'currency' => $reservation->requested->currency->code, 'state' => 'held']);
        $column = match ($reservation->kind) {
            EffectKind::Capture => 'held_capture_minor',EffectKind::VoidAuthorization => 'held_void_minor',EffectKind::Refund => 'held_refund_minor',default => throw new LogicException('Invalid reservation kind')
        };
        $this->callerQuery('capacity', $transactionContext)->where('root_operation_ref', $reservation->rootOperationRef)->where('version', $expected->version)->incrementEach([$column => $reservation->requested->minor, 'version' => 1]);

        return $this->capacity($transactionContext, $reservation->rootOperationRef);
    }

    public function settleReservation(TransactionContext $transactionContext, string $reservationRef, ReservationState $reservationState, Money $consumed, ?string $releaseEvidenceRef, CapacitySnapshot $next): void
    {
        $current = $this->capacity($transactionContext, $next->rootOperationRef);
        $q = $this->callerQuery('reservation', $transactionContext)->where('reservation_ref', $reservationRef);
        $r = (clone $q)->first() ?? throw new LogicException('Reservation absent');
        if ($next->version !== $current->version + 1 || self::text($r->root_operation_ref) !== $next->rootOperationRef || self::text($r->state) !== 'held' || $reservationState === ReservationState::Held || $consumed->minor < 0 || $consumed->minor > self::integer($r->requested_minor) || $consumed->currency->code !== self::text($r->currency)) {
            $this->refuse('Reservation settlement conflict');
        }
        if ($reservationState === ReservationState::Released) {
            if ($releaseEvidenceRef === null || $this->callerQuery('provider_evidence', $transactionContext)->where('evidence_ref', $releaseEvidenceRef)->where('signature_verdict', 'verified_no_effect')->first() === null) {
                $this->refuse('Release needs verified no-effect evidence');
            }
        }
        $q->where('state', 'held')->update(['state' => $reservationState->value, 'consumed_minor' => $consumed->minor, 'release_evidence_ref' => $releaseEvidenceRef, 'version' => self::integer($r->version) + 1]);
        $this->writeCapacity($transactionContext, $next, $current->version);
    }

    public function appendEvidence(TransactionContext $transactionContext, ProviderEvidence $providerEvidence): string
    {
        $this->custody($transactionContext);
        $this->assertScope($providerEvidence->scope, $transactionContext);
        $payload = ExecutionCodec::encode($providerEvidence->sanitizedPayload);
        if (strlen($payload) > 65536 || preg_match('/"(?:pan|cvc|cvv|password|secret|accessToken|refreshToken|providerToken)"\s*:/i', $payload) === 1) {
            $this->refuse('Evidence payload is not sanitized/bounded');
        }
        $this->database($transactionContext)->table('billium_execution_provider_evidence')->insert($this->scope($providerEvidence->scope) + ['intent_owner_namespace' => $providerEvidence->scope->intentOwnerNamespace, 'evidence_ref' => $providerEvidence->evidenceRef, 'provider_id' => $providerEvidence->providerId, 'merchant_account_ref' => $providerEvidence->merchantAccountRef, 'provider_event_id' => $providerEvidence->providerEventId, 'payload_digest' => $providerEvidence->payloadDigest, 'signature_verdict' => $providerEvidence->signatureVerdict, 'handler_contract_version' => $providerEvidence->handlerContractVersion, 'received_at' => $providerEvidence->receivedAt, 'sanitized_payload' => $payload, 'secure_payload_ref' => $providerEvidence->securePayloadRef]);

        return $providerEvidence->evidenceRef;
    }

    public function enqueueProviderWork(TransactionContext $transactionContext, string $evidenceRef, string $workRef, string $handlerVersion): void
    {
        $this->custody($transactionContext);
        $this->database($transactionContext)->table('billium_execution_provider_work')->insertOrIgnore($this->callerScope($transactionContext) + ['evidence_ref' => $evidenceRef, 'work_ref' => $workRef, 'handler_contract_version' => $handlerVersion]);
    }

    public function claimProviderWork(TransactionContext $transactionContext, string $workRef, string $leaseOwner, DateTimeImmutable $until): ?ProviderWorkClaim
    {
        $this->custody($transactionContext);
        $q = $this->callerQuery('provider_work', $transactionContext)->where('work_ref', $workRef);
        $r = (clone $q)->lockForUpdate()->first();
        if ($r === null || $until <= new DateTimeImmutable || ! in_array($r->state, ['due', 'leased'], true) || new DateTimeImmutable(self::text($r->due_at)) > new DateTimeImmutable || ($r->state === 'leased' && self::date($r->lease_expires_at) > new DateTimeImmutable)) {
            return null;
        }
        $fence = self::integer($r->lease_fence) + 1;
        $count = self::integer($r->attempt_count) + 1;
        $q->update(['state' => 'leased', 'lease_owner' => $leaseOwner, 'lease_fence' => $fence, 'lease_expires_at' => $until, 'attempt_count' => $count]);

        return new ProviderWorkClaim($workRef, self::text($r->evidence_ref), self::text($r->handler_contract_version), $count, $fence, $leaseOwner, $until);
    }

    public function finishProviderWork(TransactionContext $transactionContext, ProviderWorkClaim $providerWorkClaim, string $state, ?string $resultRef, ?string $errorCode, ?DateTimeImmutable $dueAt): bool
    {
        $this->custody($transactionContext);
        if (! in_array($state, ['due', 'processed', 'parked', 'quarantined'], true)) {
            $this->refuse('Invalid work completion');
        }

        return $this->callerQuery('provider_work', $transactionContext)->where('work_ref', $providerWorkClaim->workRef)->where('handler_contract_version', $providerWorkClaim->handlerContractVersion)->where('state', 'leased')->where('lease_owner', $providerWorkClaim->leaseOwner)->where('lease_fence', $providerWorkClaim->leaseFence)->where('lease_expires_at', '>', new DateTimeImmutable)->update(['state' => $state, 'result_ref' => $resultRef, 'last_error_code' => $errorCode, 'last_error_at' => $errorCode === null ? null : new DateTimeImmutable, 'due_at' => $dueAt ?? new DateTimeImmutable]) === 1;
    }

    public function appendFact(TransactionContext $transactionContext, ProviderFact $providerFact): bool
    {
        $this->financial($transactionContext);
        $this->requireOperationLock($transactionContext, $providerFact->operationRef);
        $op = $this->callerQuery('operation', $transactionContext)->where('operation_ref', $providerFact->operationRef)->first() ?? throw new LogicException('Operation absent');
        if (self::text($op->merchant_account_ref) !== $providerFact->snapshot->merchantAccountRef || self::text($op->currency) !== $providerFact->snapshot->currency || $providerFact->snapshot->operationRef !== $providerFact->operationRef) {
            $this->refuse('Provider fact account/currency substitution');
        }
        $prior = $this->callerQuery('provider_fact', $transactionContext)->where('provider_identity_scope', $providerFact->providerIdentityScope)->where('fact_identity', $providerFact->factIdentity)->first();
        $sameRef = $this->callerQuery('provider_fact', $transactionContext)->where('fact_ref', $providerFact->factRef)->first();
        $inserted = $this->database($transactionContext)->table('billium_execution_provider_fact')->insertOrIgnore($this->callerScope($transactionContext) + ['fact_ref' => $providerFact->factRef, 'operation_ref' => $providerFact->operationRef, 'provider_identity_scope' => $providerFact->providerIdentityScope, 'fact_identity' => $providerFact->factIdentity, 'evidence_mode' => $providerFact->mode->value, 'material_digest' => $providerFact->materialDigest, 'normalized_snapshot' => ExecutionCodec::encode($providerFact->snapshot), 'evidence_refs' => ExecutionCodec::encode($providerFact->evidenceRefs)]);
        if (($prior !== null && self::text($prior->material_digest) !== $providerFact->materialDigest) || ($sameRef !== null && (self::text($sameRef->material_digest) !== $providerFact->materialDigest || self::text($sameRef->fact_identity) !== $providerFact->factIdentity || self::text($sameRef->provider_identity_scope) !== $providerFact->providerIdentityScope))) {
            $this->database($transactionContext)->table('billium_execution_discrepancy')->insertOrIgnore($this->callerScope($transactionContext) + ['discrepancy_ref' => 'fact-conflict:'.hash('sha256', ExecutionCodec::encode($providerFact)), 'root_operation_ref' => $op->root_operation_ref, 'operation_ref' => $providerFact->operationRef, 'kind' => 'provider_fact_identity_conflict', 'evidence_refs' => ExecutionCodec::encode($providerFact->evidenceRefs), 'observed' => ExecutionCodec::encode($providerFact->snapshot), 'last_consistent_applied' => $prior->normalized_snapshot ?? $sameRef->normalized_snapshot ?? '{}', 'exposure' => ExecutionCodec::encode(['financialHold' => true, 'factRef' => $providerFact->factRef])]);
            $this->callerQuery('capacity', $transactionContext)->where('root_operation_ref', $op->root_operation_ref)->update(['financial_hold' => true]);

            return false;
        }

        return $inserted === 1;
    }

    public function appendRevision(TransactionContext $transactionContext, MaterialChange $materialChange, string $receiptRef): void
    {
        $a = $materialChange->after;
        $b = $materialChange->before;
        $this->financial($transactionContext);
        $this->requireOperationLock($transactionContext, $b->operationRef);
        if (ExecutionCodec::encode($this->admittedOperation($transactionContext, $b->operationRef)) !== ExecutionCodec::encode($b)) {
            $this->refuse('Material revision differs from historical operation');
        }
        if ($a->revision !== $b->revision + 1 || ExecutionCodec::encode($a->binding) !== ExecutionCodec::encode($b->binding) || ExecutionCodec::encode($a->requested) !== ExecutionCodec::encode($b->requested) || $a->kind !== $b->kind || $a->operationRef !== $b->operationRef || $a->rootOperationRef !== $b->rootOperationRef) {
            $this->refuse('Material revision mutates immutable identity');
        }
        $updated = $this->callerQuery('operation', $transactionContext)->where('operation_ref', $b->operationRef)->where('revision', $b->revision)->where('economic_digest', $b->economicDigest)->update(['state' => $a->state, 'revision' => $a->revision, 'economic_digest' => $a->economicDigest, 'authorization_ref' => $a->authorizationRef]);
        if ($updated !== 1) {
            $this->refuse('Material revision conflict');
        }
        $currentCapacity = $this->capacity($transactionContext, $b->rootOperationRef);
        if ($materialChange->capacity->version !== $currentCapacity->version) {
            $this->writeCapacity($transactionContext, $materialChange->capacity, $currentCapacity->version);
        }
        $this->database($transactionContext)->table('billium_execution_operation_revision')->insert($this->callerScope($transactionContext) + ['operation_ref' => $a->operationRef, 'revision' => $a->revision, 'material_digest' => $a->economicDigest, 'requested_minor' => $a->requested->minor, 'currency' => $a->requested->currency->code, 'observed' => ExecutionCodec::encode($materialChange->observed), 'last_consistent_applied' => ExecutionCodec::encode($materialChange->capacity), 'financial_hold' => ExecutionCodec::encode($materialChange->capacity->financialHold), 'exposure' => ExecutionCodec::encode(['discrepancyRef' => $materialChange->discrepancyRef]), 'evidence_refs' => ExecutionCodec::encode($materialChange->observed->evidenceRefs), 'receipt_ref' => $receiptRef, 'occurred_at' => $materialChange->observed->observedAt]);
    }

    public function appendDiscrepancy(TransactionContext $transactionContext, string $ref, MaterialChange $materialChange): void
    {
        $this->financial($transactionContext);
        $prior = $this->admittedOperation($transactionContext, $materialChange->before->operationRef);
        $this->requireLock($transactionContext, $prior->rootOperationRef);
        if ($prior->rootOperationRef !== $materialChange->before->rootOperationRef) {
            $this->refuse('Discrepancy differs from historical root');
        }
        $this->database($transactionContext)->table('billium_execution_discrepancy')->insert($this->callerScope($transactionContext) + ['discrepancy_ref' => $ref, 'root_operation_ref' => $materialChange->before->rootOperationRef, 'operation_ref' => $materialChange->before->operationRef, 'kind' => 'provider_material_conflict', 'evidence_refs' => ExecutionCodec::encode($materialChange->observed->evidenceRefs), 'observed' => ExecutionCodec::encode($materialChange->observed), 'last_consistent_applied' => ExecutionCodec::encode($materialChange->capacity), 'exposure' => ExecutionCodec::encode(['financialHold' => true])]);
        $this->callerQuery('capacity', $transactionContext)->where('root_operation_ref', $materialChange->before->rootOperationRef)->update(['financial_hold' => true]);
    }

    public function ensureObservationPartition(TransactionContext $transactionContext, ExecutionScope $executionScope, string $sourceAuthority, string $lineage, string $rootRef): ObservationPartition
    {
        $this->financial($transactionContext);
        $this->assertScope($executionScope, $transactionContext, $sourceAuthority);
        $root = $this->admittedOperation($transactionContext, $rootRef);
        if ($root->operationRef !== $root->rootOperationRef) {
            $this->refuse('Partition requires original root');
        }
        $map = ['schema' => 'billium.execution.observation-partition.v1', 'installationRef' => $executionScope->installationRef, 'sellerRef' => $executionScope->sellerRef, 'environment' => $executionScope->environment, 'sourceAuthority' => $sourceAuthority, 'sourceLineage' => $lineage, 'rootOperationRef' => $rootRef];
        $part = 'execution-charge:'.hash('sha256', (new Codec)->canonicalize((object) $map));
        $this->database($transactionContext)->table('billium_execution_observation_partition')->insertOrIgnore($this->scope($executionScope) + ['source_authority' => $sourceAuthority, 'source_lineage' => $lineage, 'root_operation_ref' => $rootRef, 'partition_ref' => $part]);
        $r = $this->query('observation_partition', $executionScope, $transactionContext)->where('source_authority', $sourceAuthority)->where('source_lineage', $lineage)->where('root_operation_ref', $rootRef)->first() ?? throw new LogicException('Partition absent');

        return new ObservationPartition(self::integer($r->id), $executionScope, $sourceAuthority, $lineage, $rootRef, self::text($r->partition_ref), self::integer($r->last_sequence));
    }

    public function nextObservationSequence(TransactionContext $transactionContext, int $partitionId): int
    {
        $this->financial($transactionContext);
        if (! ($this->held[$transactionContext]['billium_execution_observation_partition:'.$partitionId] ?? false)) {
            throw new LogicException('Observation partition lock required');
        }
        $q = $this->callerQuery('observation_partition', $transactionContext)->where('id', $partitionId)->where('source_authority', $this->selectedScope($transactionContext)->sourceAuthority);
        $partition = (clone $q)->first() ?? throw new LogicException('Partition absent');
        $this->requireLock($transactionContext, self::text($partition->root_operation_ref));
        $q->increment('last_sequence');

        return self::integer($q->value('last_sequence'));
    }

    public function appendEmission(TransactionContext $transactionContext, ObservationEmission $observationEmission): void
    {
        $this->financial($transactionContext);
        $this->assertScope($observationEmission->scope, $transactionContext, $observationEmission->sourceAuthority);
        $op = $this->operation($observationEmission->scope, $observationEmission->operationRef) ?? throw new LogicException('Emission operation absent');
        $p = $observationEmission->payload;
        if ([$p->installationRef, $p->sellerRef, $p->environment, $p->sourceAuthority, $p->sourceLineage, $p->partitionRef, $p->sourceSequence, $p->operationRef, $p->operationRevision, $p->eventId, $p->contentDigest] !== [$observationEmission->scope->installationRef, $observationEmission->scope->sellerRef, $observationEmission->scope->environment, $observationEmission->sourceAuthority, $observationEmission->sourceLineage, $observationEmission->partitionRef, $observationEmission->sourceSequence, $observationEmission->operationRef, $observationEmission->operationRevision, $observationEmission->eventId, $observationEmission->payloadDigest] || $op->revision !== $observationEmission->operationRevision || (new Codec)->digestOf($p) !== $observationEmission->payloadDigest) {
            $this->refuse('Emission differs from immutable wire observation');
        }

        $this->requireLock($transactionContext, $op->rootOperationRef);
        $this->database($transactionContext)->table('billium_execution_observation_emission')->insert($this->scope($observationEmission->scope) + ['emission_ref' => $observationEmission->emissionRef, 'source_authority' => $observationEmission->sourceAuthority, 'source_lineage' => $observationEmission->sourceLineage, 'partition_ref' => $observationEmission->partitionRef, 'source_sequence' => $observationEmission->sourceSequence, 'root_operation_ref' => $op->rootOperationRef, 'operation_ref' => $observationEmission->operationRef, 'operation_revision' => $observationEmission->operationRevision, 'command_ref' => $observationEmission->commandRef, 'event_id' => $observationEmission->eventId, 'payload_digest' => $observationEmission->payloadDigest, 'payload' => ExecutionCodec::encode($observationEmission->payload), 'command_row_id' => $observationEmission->commandRowId]);
    }

    public function bindEmissionCommandRow(TransactionContext $transactionContext, string $commandRef, int $commandRowId): void
    {
        $this->financial($transactionContext);
        $q = $this->callerQuery('observation_emission', $transactionContext)->where('command_ref', $commandRef);
        $row = (clone $q)->first() ?? throw new LogicException('Emission absent');
        $this->requireLock($transactionContext, self::text($row->root_operation_ref));
        if ($commandRowId <= 0 || $q->whereNull('command_row_id')->update(['command_row_id' => $commandRowId]) !== 1) {
            throw new LogicException('Emission command row already bound or invalid');
        }
    }

    public function merchantRevision(ExecutionScope $executionScope, string $revisionRef): MerchantRevision
    {
        $r = $this->query('merchant_revision', $executionScope)->where('revision_ref', $revisionRef)->first() ?? throw new LogicException('Historical merchant revision unavailable');

        return new MerchantRevision(self::text($r->revision_ref), $executionScope, self::text($r->merchant_account_ref), self::text($r->provider_id), self::text($r->driver_id), self::text($r->driver_contract_version), self::text($r->capability_snapshot_ref), self::text($r->capability_digest), new SecretRef(self::text($r->credential_ref)), self::text($r->credential_version_ref), self::listJson(self::text($r->currency_codes)), self::text($r->digest), new DateTimeImmutable(self::text($r->created_at)), new MerchantRevisionPolicy(new DateTimeImmutable(self::text($r->valid_from)), self::date($r->valid_until), self::nullableText($r->predecessor_ref), self::text($r->api_profile_version), self::text($r->webhook_route_ref), self::text($r->continuity_evidence_ref), self::text($r->settlement_account_ref), self::text($r->mandate_namespace_ref)));
    }

    public function appendMerchantRevision(TransactionContext $transactionContext, MerchantRevision $merchantRevision, CapabilitySnapshot $capabilitySnapshot): void
    {
        $this->database($transactionContext);
        $this->assertScope($merchantRevision->scope, $transactionContext);
        if ($transactionContext->spec()->mode !== FinancialMode::Administrative) {
            throw new LogicException('Merchant configuration requires administrative root');
        }
        if ([$merchantRevision->merchantAccountRef, $merchantRevision->driverId, $merchantRevision->driverContractVersion, $merchantRevision->capabilitySnapshotRef, $merchantRevision->capabilityDigest] !== [$capabilitySnapshot->merchantAccountRef, $capabilitySnapshot->driverId, $capabilitySnapshot->driverContractVersion, $capabilitySnapshot->snapshotRef, $capabilitySnapshot->digest]) {
            $this->refuse('Merchant capability binding mismatch');
        }
        foreach ($merchantRevision->currencyCodes as $currency) {
            Currency::of($currency);
            if (! in_array($currency, $capabilitySnapshot->currencies, true)) {
                $this->refuse('Merchant currency outside capability');
            }
        }
        $this->persistMerchant($transactionContext, $merchantRevision, $capabilitySnapshot);

    }

    private function persistMerchant(TransactionContext $context, MerchantRevision $m, CapabilitySnapshot $c): void
    {
        $from = $m->policy->validFrom;
        $until = $m->policy->validUntil;
        $predecessor = $m->policy->predecessorRef;
        $profile = $m->policy->apiProfileVersion;
        $route = $m->policy->webhookRouteRef;
        if ($until !== null && $until <= $from) {
            $this->refuse('Invalid merchant effective interval');
        }
        if ($predecessor !== null) {
            $this->merchantRevision($m->scope, $predecessor);
        }
        $this->database($context)->table('billium_execution_capability_snapshot')->insert($this->scope($m->scope) + ['snapshot_ref' => $c->snapshotRef, 'merchant_account_ref' => $c->merchantAccountRef, 'driver_id' => $c->driverId, 'driver_contract_version' => $c->driverContractVersion, 'capability_version' => $c->capabilityVersion, 'evidence_mode' => $c->evidenceMode->value, 'body' => ExecutionCodec::encode($c), 'digest' => $c->digest, 'qualification_mode' => $c->qualificationMode->value, 'qualification_evidence_ref' => $c->qualificationEvidenceRef, 'created_at' => $m->createdAt, 'supported_effects' => $this->pgArray(array_map(static fn (EffectKind $e): string => $e->value, $c->supportedEffects)), 'currencies' => $this->pgArray($c->currencies), 'capture_mode' => $c->details->captureMode->value, 'partial_refund' => $c->partialRefund, 'multiple_refund' => $c->details->multipleRefund, 'lookup_by_provider_id' => $c->details->lookupByProviderId, 'lookup_by_reference' => $c->lookupByReference, 'inventory_complete' => $c->inventoryComplete, 'absence_certification' => $c->absenceCertification, 'idempotency_retention_seconds' => $c->details->idempotencyRetentionSeconds, 'lookup_retention_seconds' => $c->details->lookupRetentionSeconds]);
        $this->database($context)->table('billium_execution_merchant_revision')->insert($this->scope($m->scope) + ['revision_ref' => $m->revisionRef, 'merchant_account_ref' => $m->merchantAccountRef, 'provider_id' => $m->providerId, 'driver_id' => $m->driverId, 'driver_contract_version' => $m->driverContractVersion, 'capability_snapshot_ref' => $m->capabilitySnapshotRef, 'capability_digest' => $m->capabilityDigest, 'credential_ref' => $m->credentialRef->locator, 'credential_version_ref' => $m->credentialVersionRef, 'currency_codes' => ExecutionCodec::encode($m->currencyCodes), 'digest' => $m->digest, 'created_at' => $m->createdAt, 'valid_from' => $from, 'valid_until' => $until, 'predecessor_ref' => $predecessor, 'api_profile_version' => $profile, 'webhook_route_ref' => $route, 'continuity_evidence_ref' => $m->policy->continuityEvidenceRef, 'settlement_account_ref' => $m->policy->settlementAccountRef, 'mandate_namespace_ref' => $m->policy->mandateNamespaceRef]);
    }

    public function recordRecoveryAction(TransactionContext $transactionContext, RecoveryAction $recoveryAction): ExecutionReceipt
    {
        return $this->appendRepairReceipt($transactionContext, $recoveryAction, $recoveryAction->semanticDigest, $recoveryAction->semanticDigest, []);
    }

    public function authorization(TransactionContext $transactionContext, string $rootOperationRef): ?AuthorizationRecord
    {
        $this->financial($transactionContext);
        $this->requireLock($transactionContext, $rootOperationRef);
        $r = $this->callerQuery('authorization', $transactionContext)->where('root_operation_ref', $rootOperationRef)->first();
        if ($r === null) {
            return null;
        }
        $currency = Currency::of(self::text($r->currency));

        return new AuthorizationRecord(self::text($r->authorization_ref), $rootOperationRef, new Money(self::integer($r->authorized_minor), $currency), new Money(self::integer($r->released_minor), $currency), self::date($r->provider_expires_at), self::nullableText($r->expiry_evidence_ref), self::text($r->state), self::integer($r->revision));
    }

    public function applyAuthorization(TransactionContext $transactionContext, AuthorizationRecord $authorizationRecord, int $expectedRevision): void
    {
        $current = $this->authorization($transactionContext, $authorizationRecord->rootOperationRef);
        if ($authorizationRecord->revision !== $expectedRevision + 1 || ($current === null ? $expectedRevision !== 0 : $current->revision !== $expectedRevision)) {
            $this->refuse('Authorization revision conflict');
        }
        $row = ['authorization_ref' => $authorizationRecord->authorizationRef, 'root_operation_ref' => $authorizationRecord->rootOperationRef, 'authorized_minor' => $authorizationRecord->authorized->minor, 'released_minor' => $authorizationRecord->released->minor, 'currency' => $authorizationRecord->authorized->currency->code, 'provider_expires_at' => $authorizationRecord->providerExpiresAt, 'expiry_evidence_ref' => $authorizationRecord->expiryEvidenceRef, 'state' => $authorizationRecord->state, 'revision' => $authorizationRecord->revision];
        if ($current === null) {
            $this->database($transactionContext)->table('billium_execution_authorization')->insert($this->callerScope($transactionContext) + $row);
        } else {
            unset($row['authorization_ref'],$row['root_operation_ref'],$row['currency']);
            if ($this->callerQuery('authorization', $transactionContext)->where('authorization_ref', $authorizationRecord->authorizationRef)->where('revision', $expectedRevision)->update($row) !== 1) {
                $this->refuse('Authorization CAS conflict');
            }
        }
    }

    public function appendReconciliationRun(TransactionContext $transactionContext, ReconciliationRun $reconciliationRun): void
    {
        $this->financial($transactionContext);
        $this->assertScope($reconciliationRun->scope, $transactionContext);
        $this->database($transactionContext)->table('billium_execution_reconciliation_run')->insert($this->scope($reconciliationRun->scope) + $this->runRow($reconciliationRun));
    }

    public function advanceReconciliationRun(TransactionContext $transactionContext, ReconciliationRun $reconciliationRun, string $expectedState): void
    {
        $this->financial($transactionContext);
        $this->assertScope($reconciliationRun->scope, $transactionContext);
        $row = $this->runRow($reconciliationRun);
        unset($row['run_ref'],$row['merchant_account_ref'],$row['recovery_horizon'],$row['started_at']);
        if ($this->query('reconciliation_run', $reconciliationRun->scope, $transactionContext)->where('run_ref', $reconciliationRun->runRef)->where('state', $expectedState)->update($row) !== 1) {
            $this->refuse('Reconciliation CAS conflict');
        }
    }

    public function appendRepairReceipt(TransactionContext $transactionContext, RecoveryAction $recoveryAction, string $beforeDigest, string $afterDigest, array $correctionRefs): ExecutionReceipt
    {
        $this->financial($transactionContext);
        $this->assertScope($recoveryAction->scope, $transactionContext);
        $root = $this->operation($recoveryAction->scope, $recoveryAction->rootOperationRef) ?? throw new LogicException('Repair root unavailable');
        if ($root->operationRef !== $root->rootOperationRef) {
            $this->refuse('Repair must name original root');
        }
        $this->requireLock($transactionContext, $recoveryAction->rootOperationRef);
        if ($recoveryAction->evidenceRefs === []) {
            $this->refuse('Recovery requires evidence');
        }
        $this->database($transactionContext)->table('billium_execution_repair_receipt')->insert($this->scope($recoveryAction->scope) + ['repair_ref' => $recoveryAction->actionRef, 'root_operation_ref' => $recoveryAction->rootOperationRef, 'authorization_ref' => $recoveryAction->authorizationRef, 'reason' => $recoveryAction->reason, 'evidence_refs' => ExecutionCodec::encode($recoveryAction->evidenceRefs), 'before_digest' => $beforeDigest, 'after_digest' => $afterDigest, 'correction_refs' => ExecutionCodec::encode($correctionRefs), 'action_kind' => $recoveryAction->actionKind, 'intent_owner_namespace' => $recoveryAction->scope->intentOwnerNamespace]);

        return new ExecutionReceipt($recoveryAction->actionRef, $recoveryAction->actionRef, $recoveryAction->rootOperationRef, $recoveryAction->semanticDigest, 'recorded', false, $recoveryAction->evidenceRefs);
    }

    public function transitionNamespace(TransactionContext $transactionContext, ExecutionScope $executionScope, NamespaceState $namespaceState, int $expectedGeneration, string $authorityEvidenceRef): NamespaceRecord
    {
        $this->financial($transactionContext);
        $this->assertScope($executionScope, $transactionContext);
        if ($authorityEvidenceRef === '') {
            $this->refuse('Namespace transition lacks authority evidence');
        }
        $q = $this->query('namespace', $executionScope, $transactionContext)->where('intent_owner_namespace', $executionScope->intentOwnerNamespace);
        if ($expectedGeneration === 0) {
            $inserted = $this->database($transactionContext)->table('billium_execution_namespace')->insertOrIgnore($this->scope($executionScope) + ['intent_owner_namespace' => $executionScope->intentOwnerNamespace, 'state' => $namespaceState->value, 'canonicalization_version' => 'execution.intent.v1', 'completeness_receipt_ref' => $authorityEvidenceRef, 'generation' => 1]);
            if ($inserted !== 1) {
                $this->refuse('Namespace creation race');
            }
        } elseif ($q->where('generation', $expectedGeneration)->update(['state' => $namespaceState->value, 'completeness_receipt_ref' => $authorityEvidenceRef, 'generation' => $expectedGeneration + 1]) !== 1) {
            $this->refuse('Namespace generation conflict');
        }

        return $this->namespace($executionScope);
    }

    public function finishAttempt(TransactionContext $transactionContext, string $attemptRef, int $expectedFence, AttemptState $attemptState, string $evidenceRef): bool
    {
        $this->financial($transactionContext);
        $evidence = $this->callerQuery('provider_evidence', $transactionContext)->where('evidence_ref', $evidenceRef)->first() ?? throw new LogicException('Attempt finish evidence absent');
        $q = $this->callerQuery('attempt', $transactionContext)->where('attempt_ref', $attemptRef);
        $row = (clone $q)->first() ?? throw new LogicException('Attempt absent');
        $this->requireOperationLock($transactionContext, self::text($row->operation_ref));
        if ($row->merchant_account_ref !== $evidence->merchant_account_ref || $row->provider_id !== $evidence->provider_id || in_array($attemptState, [AttemptState::Admitted, AttemptState::Dispatching], true)) {
            $this->refuse('Attempt finish identity/state mismatch');
        }

        return $q->where('lease_fence', $expectedFence)->where('lease_expires_at', '>', new DateTimeImmutable)->whereIn('state', ['dispatching', 'pending', 'unknown'])->update(['state' => $attemptState->value, 'finish_evidence_ref' => $evidenceRef]) === 1;
    }

    public function capability(ExecutionScope $scope, string $ref): CapabilitySnapshot
    {
        $r = $this->query('capability_snapshot', $scope)->where('snapshot_ref', $ref)->first() ?? throw new LogicException('Capability snapshot absent');

        return ExecutionCodec::decode(CapabilitySnapshot::class, self::text($r->body));
    }

    private function database(?TransactionContext $context = null): Connection
    {
        if ($context !== null) {
            $this->runner->assertCurrent($context, 'execution');
        }

        return $this->databases->connection($context?->connectionName() ?? $this->options->connection);
    }

    private function financial(TransactionContext $context): void
    {
        $this->database($context);
        if (! $context->spec()->mode->financial() || $context->sessionEpoch() < 1) {
            throw new LogicException('Execution mutation requires current financial session');
        }
    }

    private function custody(TransactionContext $context): void
    {
        $this->database($context);
        if (! $context->spec()->mode->financial() && $context->spec()->mode !== FinancialMode::RawCustody) {
            throw new LogicException('Custody requires declared raw or financial root');
        }
    }

    private function selectedScope(TransactionContext $context): FinancialScope
    {
        $scope = $context->spec()->financialScope;
        $caller = $context->caller();
        if ($scope === null || $scope->installationRef !== $caller->installationRef || $scope->sellerRef !== $caller->sellerRef
            || $scope->environment !== $caller->environment || ! in_array($scope->intentOwnerNamespace, $caller->intentOwnerNamespaces, true)
            || ! in_array($scope->sourceAuthority, ['remote-billium', 'standalone-billium'], true)
            || ! in_array($scope->sourceAuthority, $caller->sourceAuthorities, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Execution requires an authenticated selected financial scope');
        }

        return $scope;
    }

    private function assertScope(ExecutionScope $scope, ?TransactionContext $context = null, ?string $profile = null): void
    {
        $caller = $context?->caller() ?? $this->callers->current();
        if ([$scope->installationRef, $scope->sellerRef, $scope->environment] !== [$caller->installationRef, $caller->sellerRef, $caller->environment] || ! in_array($scope->intentOwnerNamespace, $caller->intentOwnerNamespaces, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Execution scope is not authenticated');
        }
        if ($context !== null && $context->spec()->mode->financial()) {
            $selected = $this->selectedScope($context);
            if ($selected->intentOwnerNamespace !== $scope->intentOwnerNamespace || ($profile !== null && $selected->sourceAuthority !== $profile)) {
                throw new ProblemException(ProblemCode::AuthDenied, 'Execution differs from selected financial scope');
            }
        }
    }

    /** @return array<string,string> */
    private function scope(ExecutionScope $scope): array
    {
        return ['installation_ref' => $scope->installationRef, 'seller_ref' => $scope->sellerRef, 'environment' => $scope->environment];
    }

    /** @return array<string,string> */
    private function callerScope(TransactionContext $context): array
    {
        $c = $context->caller();

        return ['installation_ref' => $c->installationRef, 'seller_ref' => $c->sellerRef, 'environment' => $c->environment];
    }

    private function query(string $table, ExecutionScope $scope, ?TransactionContext $context = null): Builder
    {
        $this->assertScope($scope, $context);

        $query = $this->database($context)->table('billium_execution_'.$table)->where($this->scope($scope));
        if ($table === 'operation') {
            $query->where('intent_owner_namespace', $scope->intentOwnerNamespace);
        } elseif ($table === 'attempt') {
            $query->whereIn('operation_ref', $this->query('operation', $scope, $context)->select('operation_ref'));
        }

        return $query;
    }

    private function callerQuery(string $table, TransactionContext $context): Builder
    {
        return $this->database($context)->table('billium_execution_'.$table)->where($this->callerScope($context));
    }

    private function requireLock(TransactionContext $context, string $rootRef): void
    {
        if (! ($this->held[$context][$rootRef] ?? false)) {
            throw new LogicException('Execution root/capacity lock required');
        }
    }

    private function requireOperationLock(TransactionContext $context, string $operationRef): void
    {
        $this->requireLock($context, $this->admittedOperation($context, $operationRef)->rootOperationRef);
    }

    private function admittedOperation(TransactionContext $context, string $operationRef): OperationRecord
    {
        $r = $this->callerQuery('operation', $context)->where('operation_ref', $operationRef)->first() ?? throw new LogicException('Operation absent');
        $operation = $this->operationRow($r);
        $this->assertScope($operation->binding->scope, $context, $operation->binding->executionProfile);

        return $operation;
    }

    private function operationRow(stdClass $r): OperationRecord
    {
        return new OperationRecord(self::integer($r->id), self::text($r->operation_ref), self::text($r->root_operation_ref), EffectKind::from(self::text($r->kind)), ExecutionCodec::decode(FrozenBinding::class, self::text($r->binding)), new Money(self::integer($r->requested_minor), Currency::of(self::text($r->currency))), self::integer($r->revision), self::text($r->state), self::text($r->economic_digest), self::nullableText($r->authorization_ref), new DateTimeImmutable(self::text($r->created_at)));
    }

    private function attemptRow(stdClass $r): AttemptRecord
    {
        return new AttemptRecord(self::integer($r->id), self::text($r->attempt_ref), self::text($r->operation_ref), self::text($r->provider_reference), AttemptState::from(self::text($r->state)), self::integer($r->lease_fence), self::nullableText($r->lease_owner), self::date($r->lease_expires_at), $r->session_epoch === null ? null : self::integer($r->session_epoch), self::nullableText($r->owner_session_ref), self::nullableText($r->dispatch_ticket_ref));
    }

    private function capacityRow(stdClass $r): CapacitySnapshot
    {
        $currency = Currency::of(self::text($r->currency));

        return new CapacitySnapshot(self::text($r->root_operation_ref), self::integer($r->version), new Money(self::integer($r->authorized_limit_minor), $currency), new Money(self::integer($r->confirmed_capture_minor), $currency), new Money(self::integer($r->confirmed_refund_minor), $currency), new Money(self::integer($r->released_authorization_minor), $currency), new Money(self::integer($r->held_capture_minor), $currency), new Money(self::integer($r->held_void_minor), $currency), new Money(self::integer($r->held_refund_minor), $currency), (bool) $r->financial_hold);
    }

    private function writeCapacity(TransactionContext $context, CapacitySnapshot $next, int $expectedVersion): void
    {
        $this->requireLock($context, $next->rootOperationRef);
        foreach ([$next->authorizedLimit, $next->confirmedCapture, $next->confirmedRefund, $next->releasedAuthorization, $next->heldCapture, $next->heldVoid, $next->heldRefund] as $money) {
            if ($money->minor < 0 || $money->currency->code !== $next->authorizedLimit->currency->code || $money->currency->scale !== $next->authorizedLimit->currency->scale) {
                $this->refuse('Capacity counters require one exact nonnegative currency');
            }
        }
        $row = ['authorized_limit_minor' => $next->authorizedLimit->minor, 'confirmed_capture_minor' => $next->confirmedCapture->minor, 'confirmed_refund_minor' => $next->confirmedRefund->minor, 'released_authorization_minor' => $next->releasedAuthorization->minor, 'held_capture_minor' => $next->heldCapture->minor, 'held_void_minor' => $next->heldVoid->minor, 'held_refund_minor' => $next->heldRefund->minor, 'financial_hold' => $next->financialHold, 'version' => $next->version];
        if ($next->version !== $expectedVersion + 1 || $this->callerQuery('capacity', $context)->where('root_operation_ref', $next->rootOperationRef)->where('version', $expectedVersion)->where('currency', $next->authorizedLimit->currency->code)->update($row) !== 1) {
            $this->refuse('Capacity version conflict');
        }
    }

    /** @return array<string,mixed> */
    private function runRow(ReconciliationRun $r): array
    {
        return ['run_ref' => $r->runRef, 'merchant_account_ref' => $r->merchantAccountRef, 'recovery_horizon' => $r->recoveryHorizon, 'inventory_cursor' => $r->inventoryCursor, 'completeness_watermark' => $r->completenessWatermark, 'inventory_digest' => $r->inventoryDigest, 'manifest_digest' => $r->manifestDigest, 'state' => $r->state, 'unresolved' => ExecutionCodec::encode($r->unresolved), 'started_at' => $r->startedAt, 'completed_at' => $r->completedAt];
    }

    /** @param list<string> $items */
    private function pgArray(array $items): string
    {
        return '{'.implode(',', array_map(static fn (string $s): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $s).'"', $items)).'}';
    }

    /** @return list<mixed> */
    private static function listJson(string $json): array
    {
        $v = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($v) || ! array_is_list($v)) {
            throw new LogicException('Stored list required');
        }

        return $v;
    }

    private static function text(mixed $v): string
    {
        return ExecutionCodec::text($v);
    }

    private static function nullableText(mixed $v): ?string
    {
        return $v === null ? null : self::text($v);
    }

    private static function integer(mixed $v): int
    {
        return is_int($v) ? $v : (is_string($v) && preg_match('/^-?[0-9]+$/D', $v) === 1 ? (int) $v : throw new LogicException('Invalid stored integer'));
    }

    private static function date(mixed $v): ?DateTimeImmutable
    {
        return $v === null ? null : new DateTimeImmutable(self::text($v));
    }

    private function refuse(string $reason): never
    {
        throw new ProblemException(ProblemCode::ValidationInvalid, $reason);
    }
}
