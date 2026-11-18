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
use Billium\Execution\Data\MerchantRevisionPolicy;
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
use Billium\Execution\Data\SecretRef;
use Billium\Execution\Data\VerifiedAbsence;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Enums\ProviderEvidenceMode;
use Billium\Execution\Enums\ReservationState;
use Billium\Execution\Enums\WorkState;
use Billium\Execution\Internal\Authority\AbsenceProofRegistry;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Execution\Internal\Capacity\CapacityAmounts;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Reducer\VerifiedReduction;
use Closure;
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

    /** @param (Closure():?OriginalIdentityArchive)|null $identityArchive */
    public function __construct(private readonly DatabaseManager $databases, private readonly OperationRunner $runner, private readonly CallerContext $callers, private readonly BilliumOptions $options, private readonly FinancialSessionAdmission $sessionAdmission, private readonly ?AbsenceProofRegistry $absenceProofs = null, private readonly ?Closure $identityArchive = null, private readonly ?VerifiedReduction $verifiedReduction = null)
    {
        $this->held = new WeakMap;
    }

    public function plan(ExecutionScope $executionScope, string $operationRef, bool $includeChildren = false, ?TransactionContext $context = null): ExecutionLockPlan
    {
        $this->assertScope($executionScope, $context);
        $operation = $this->operation($executionScope, $operationRef, $context);
        if ($operation === null) {
            return new ExecutionLockPlan($executionScope, $operationRef, [], ['execution', 'platform'], ExecutionCodec::digest([$executionScope, $operationRef, []]));
        }
        $root = $operation->rootOperationRef;
        $locks = [];
        $parts = $this->query('observation_partition', $executionScope, $context)->where('root_operation_ref', $root)->orderBy('id')->pluck('id')->all();
        if ($parts !== []) {
            $locks[] = new LockRequest(LockRank::FeedPartition, 'billium_execution_observation_partition', array_values(array_map(self::integer(...), $parts)));
        }
        $row = $this->query('operation', $executionScope, $context)->where('operation_ref', $root)->first();
        if ($row !== null) {
            $locks[] = new LockRequest(LockRank::RootCharge, 'billium_execution_operation', [self::integer($row->id)]);
        }

        $children = $this->query('operation', $executionScope, $context)->where('root_operation_ref', $root)->where('operation_ref', '<>', $root);
        $ids = array_values(array_map(self::integer(...), $children->orderBy('id')->pluck('id')->all()));
        if ($ids !== []) {
            $locks[] = new LockRequest(LockRank::ChildEffect, 'billium_execution_operation', $ids);
        }

        $capacity = $this->query('capacity', $executionScope, $context)->where('root_operation_ref', $root)->first();
        if ($capacity !== null) {
            $locks[] = new LockRequest(LockRank::Capacity, 'billium_execution_capacity', [self::integer($capacity->id)]);
        }
        foreach ([1 => 'reservation', 2 => 'authorization'] as $segment => $table) {
            $rows = $this->query($table, $executionScope, $context)->where('root_operation_ref', $root)->orderBy('id')->pluck('id')->all();
            if ($rows !== []) {
                $locks[] = new LockRequest(LockRank::Capacity, 'billium_execution_'.$table, array_values(array_map(self::integer(...), $rows)), segment: $segment);
            }
        }
        $attemptIds = $this->query('attempt', $executionScope, $context)->whereIn('operation_ref', $this->query('operation', $executionScope, $context)->where('root_operation_ref', $root)->pluck('operation_ref')->all())->orderBy('id')->pluck('id')->all();
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
        $current = $this->plan($executionLockPlan->scope, $executionLockPlan->rootOperationRef, true, $transactionContext);
        if ($current->planDigest !== $executionLockPlan->planDigest) {
            throw new LockPlanChanged('Execution row plan changed under locks');
        }
        $held = $this->held[$transactionContext] ?? [];
        if ($this->operation($executionLockPlan->scope, $executionLockPlan->rootOperationRef, $transactionContext) !== null) {
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

    public function namespace(ExecutionScope $executionScope, ?TransactionContext $context = null): NamespaceRecord
    {
        $row = $this->query('namespace', $executionScope, $context)->where('intent_owner_namespace', $executionScope->intentOwnerNamespace)->first();
        if ($row === null) {
            return new NamespaceRecord($executionScope, NamespaceState::RecoveryRequired, 'execution.intent.v1', null, null, 0);
        }

        return new NamespaceRecord($executionScope, NamespaceState::from(self::text($row->state)), self::text($row->canonicalization_version), self::date($row->history_from), self::nullableText($row->completeness_receipt_ref), self::integer($row->generation));
    }

    public function lockNamespace(TransactionContext $context, ExecutionScope $scope): NamespaceRecord
    {
        $this->financial($context);
        $id = $this->query('namespace', $scope, $context)->where('intent_owner_namespace', $scope->intentOwnerNamespace)->value('id');
        if ($id === null) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Business namespace lacks durable history');
        }
        $request = new LockRequest(LockRank::IntentNamespace, 'billium_execution_namespace', [self::integer($id)], shared: true);
        if ($context->lock($request) !== $request->keys) {
            throw new LockPlanChanged('Business namespace changed during admission');
        }

        return $this->namespace($scope, $context);
    }

    public function importOriginalIdentity(TransactionContext $ctx, ArchivedExecutionIdentity $identity, bool $verifyOnly = false): ExecutionReceipt
    {
        $this->financial($ctx);
        $binding = $identity->binding;
        $scope = $binding->scope;
        $this->assertScope($scope, $ctx, $binding->executionProfile);
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
        $imports = $this->query('original_identity_import', $scope, $ctx)->where('intent_owner_namespace', $scope->intentOwnerNamespace);
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
        $this->database($ctx)->table('billium_execution_original_identity_import')->insert($this->scope($scope) + [
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
            $this->insertHeldReservation($ctx, $reservation, $this->capacity($ctx, $binding->rootOperationRef));
        }

        return $receipt;
    }

    private function insertArchivedExecution(TransactionContext $ctx, ArchivedExecutionIdentity $a): void
    {
        $b = $a->binding;
        $scope = $b->scope;
        $db = $this->database($ctx);
        $root = $a->operationRef === $b->rootOperationRef;
        $shared = $this->scope($scope) + ['intent_owner_namespace' => $scope->intentOwnerNamespace, 'source_context' => $b->sourceContext,
            'binding' => ExecutionCodec::encode($b), 'operation_ref' => $a->operationRef, 'created_at' => $a->createdAt->format('Y-m-d H:i:s.uP')];
        $identity = $shared + ['business_intent_ref' => $a->businessIntentRef, 'operation_kind' => $root ? 'charge' : $a->kind->value,
            'semantic_digest' => $a->semanticDigest, 'canonicalization_version' => $b->canonicalizationVersion];
        $terms = ['root_operation_ref' => $b->rootOperationRef, 'requested_minor' => $a->requested->minor,
            'currency' => $a->requested->currency->code, 'currency_exponent' => $a->requested->currency->scale];
        $table = $root ? 'business_binding' : 'child_identity';
        if (! $root) {
            $this->requireLock($ctx, $b->rootOperationRef);
            $parent = $this->operation($scope, $b->rootOperationRef, $ctx);
            if ($parent === null || ExecutionCodec::encode($parent->binding) !== ExecutionCodec::encode($b)
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
            $db->table('billium_execution_capacity')->insert($this->scope($scope) + ['root_operation_ref' => $b->rootOperationRef, 'currency' => $a->requested->currency->code]);
        }
        $db->table('billium_execution_attempt')->insert($this->scope($scope) + ['attempt_ref' => $a->attemptRef, 'operation_ref' => $a->operationRef,
            'provider_reference' => $a->providerReference, 'provider_id' => $a->providerId, 'merchant_account_ref' => $b->merchantAccountRef,
            'state' => 'unknown', 'lease_fence' => $a->originalFence + 1, 'session_epoch' => $a->originalEpoch, 'owner_session_ref' => $a->originalOwner,
            'request_digest' => $a->requestDigest, 'started_at' => $a->startedAt->format('Y-m-d H:i:s.uP'), 'request_deadline_at' => $a->deadline->format('Y-m-d H:i:s.uP'),
            'dispatch_ticket_ref' => $a->firstTicketRef, 'collection_dispatch_permit_ref' => $a->collectionPermitRef]);
    }

    public function insertBinding(TransactionContext $transactionContext, FrozenBinding $frozenBinding): ?OperationRecord
    {
        $this->financial($transactionContext);
        $this->assertScope($frozenBinding->scope, $transactionContext, $frozenBinding->executionProfile);
        $namespace = $this->lockNamespace($transactionContext, $frozenBinding->scope);
        if ($namespace->state !== NamespaceState::Open || $namespace->canonicalizationVersion !== $frozenBinding->canonicalizationVersion) {
            throw new ProblemException($namespace->state === NamespaceState::Closed ? ProblemCode::KeyExpired : ProblemCode::RecoveryRequired, 'Business namespace is not proven open');
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

        return $this->operation($frozenBinding->scope, self::text($existing->operation_ref), $transactionContext) ?? throw new LogicException('Existing business binding has no operation');
    }

    public function claimChildBinding(TransactionContext $context, AdmissionPlan $plan, string $businessIntentRef, ?string $reasonCode = null, ?string $category = null): ?OperationRecord
    {
        $this->financial($context);
        $binding = $plan->binding;
        $scope = $binding->scope;
        $this->assertScope($scope, $context, $binding->executionProfile);
        $this->requireLock($context, $binding->rootOperationRef);
        $root = $this->operation($scope, $binding->rootOperationRef, $context);
        if (! in_array($plan->kind, [EffectKind::Capture, EffectKind::VoidAuthorization, EffectKind::Refund], true)
            || $plan->operationRef === $binding->rootOperationRef || $root === null || $businessIntentRef === '' || strlen($businessIntentRef) > 200
            || $reasonCode === '' || ($reasonCode !== null && strlen($reasonCode) > 200) || $category === '' || ($category !== null && strlen($category) > 200)
            || ($plan->kind === EffectKind::Capture) !== ($reasonCode === null) || ($plan->kind === EffectKind::Refund) !== ($category !== null)
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $plan->semanticDigest) !== 1 || $plan->amount->minor <= 0
            || ExecutionCodec::encode($plan->scope) !== ExecutionCodec::encode($scope)
            || ExecutionCodec::encode($root->binding) !== ExecutionCodec::encode($binding)
            || ExecutionCodec::encode($root->requested->currency) !== ExecutionCodec::encode($plan->amount->currency)
            || $plan->canonicalizationVersion !== $binding->canonicalizationVersion) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Child identity differs from its immutable original root or requested terms');
        }
        $fields = $this->scope($scope) + [
            'intent_owner_namespace' => $scope->intentOwnerNamespace, 'source_context' => $binding->sourceContext,
            'business_intent_ref' => $businessIntentRef, 'operation_kind' => $plan->kind->value,
            'operation_ref' => $plan->operationRef, 'root_operation_ref' => $binding->rootOperationRef,
            'semantic_digest' => $plan->semanticDigest, 'canonicalization_version' => $plan->canonicalizationVersion,
            'binding' => ExecutionCodec::encode($binding), 'requested_minor' => $plan->amount->minor,
            'currency' => $plan->amount->currency->code, 'currency_exponent' => $plan->amount->currency->scale,
            'reason_code' => $reasonCode, 'category' => $category,
            'receipt_ref' => PaymentIdentity::reference($scope, $plan->operationRef, 'admission'),
        ];
        $table = $this->database($context)->table('billium_execution_child_identity');
        $identity = static fn (): Builder => (clone $table)->whereRaw('business_identity_digest = public.billium_execution_business_identity_digest(?,?,?,?,?)', [
            $scope->intentOwnerNamespace, $scope->environment, $binding->sourceContext, $businessIntentRef, $plan->kind->value,
        ]);
        $existing = $identity()->first();
        if ($existing !== null) {
            return $this->retainedChild($context, $plan, $existing, $fields);
        }
        if ($this->operation($scope, $plan->operationRef, $context) !== null) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'The operation reference already belongs to another intent');
        }
        // Callers must already have acquired this namespace at rank 27 before the root lock.
        $namespace = $this->lockNamespace($context, $scope);
        if ($namespace->state !== NamespaceState::Open || $namespace->canonicalizationVersion !== $binding->canonicalizationVersion) {
            throw new ProblemException($namespace->state === NamespaceState::Closed ? ProblemCode::KeyExpired : ProblemCode::RecoveryRequired, 'Child business namespace is not proven open');
        }
        if ((clone $table)->insertOrIgnore($fields) === 1) {
            return null;
        }
        $existing = $identity()->first();
        if ($existing === null) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'The child operation reference belongs to another retained intent');
        }

        return $this->retainedChild($context, $plan, $existing, $fields);
    }

    public function childByIdentity(ExecutionScope $scope, EffectKind $kind, string $sourceContext, string $businessIntentRef, ?TransactionContext $context = null): ?OperationRecord
    {
        $this->assertScope($scope, $context);
        if (! in_array($kind, [EffectKind::Capture, EffectKind::VoidAuthorization, EffectKind::Refund], true) || $sourceContext === '' || $businessIntentRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'A child kind and complete business identity are required');
        }
        $caller = $context?->caller() ?? $this->callers->current();
        $source = $context?->spec()->financialScope?->sourceAuthority;
        $row = $this->query('child_identity', $scope, $context)->where('intent_owner_namespace', $scope->intentOwnerNamespace)
            ->whereRaw('business_identity_digest = public.billium_execution_business_identity_digest(?,?,?,?,?)', [
                $scope->intentOwnerNamespace, $scope->environment, $sourceContext, $businessIntentRef, $kind->value,
            ])->where('source_context', $sourceContext)->where('business_intent_ref', $businessIntentRef)->where('operation_kind', $kind->value)
            ->whereIn('binding->executionProfile', $source === null ? $caller->sourceAuthorities : [$source])->first();

        return $row === null ? null : $this->operation($scope, self::text($row->operation_ref), $context);
    }

    /** @param array<string,int|string|null> $fields */
    private function retainedChild(TransactionContext $context, AdmissionPlan $plan, object $row, array $fields): OperationRecord
    {
        $actual = get_object_vars($row);
        foreach ($fields as $column => $value) {
            $stored = $actual[$column] ?? null;
            if ($column === 'binding') {
                $stored = ExecutionCodec::encode(ExecutionCodec::map(self::text($stored)));
            } elseif ($column === 'requested_minor' || $column === 'currency_exponent') {
                $stored = self::integer($stored);
            }
            if ($stored !== $value) {
                throw new ProblemException(ProblemCode::IdempotencyConflict, 'Child business identity conflicts with retained original terms');
            }
        }

        return $this->operation($plan->scope, self::text($actual['operation_ref'] ?? null), $context)
            ?? throw new LogicException('A committed child identity must retain its original operation');
    }

    public function insertOperation(TransactionContext $transactionContext, AdmissionPlan $admissionPlan): OperationRecord
    {
        $this->financial($transactionContext);
        $b = $admissionPlan->binding;
        $this->assertScope($admissionPlan->scope, $transactionContext, $b->executionProfile);
        if (ExecutionCodec::encode($admissionPlan->scope) !== ExecutionCodec::encode($b->scope) || $admissionPlan->amount->minor <= 0 || Currency::of($admissionPlan->amount->currency->code)->scale !== $admissionPlan->amount->currency->scale) {
            $this->refuse('Invalid binding, amount or currency precision');
        }
        $merchant = $this->merchantRevision($b->scope, $b->merchantRevisionRef, $transactionContext);
        if ([$merchant->merchantAccountRef, $merchant->driverId, $merchant->driverContractVersion, $merchant->capabilityDigest] !== [$b->merchantAccountRef, $b->driverId, $b->driverContractVersion, $b->capabilityDigest]) {
            $this->refuse('Merchant identity substitution');
        }
        $cap = $this->capability($b->scope, $merchant->capabilitySnapshotRef, $transactionContext);
        $cap->requireEffect($admissionPlan->kind, $admissionPlan->amount->currency->code, $b->scope->environment === 'production');
        if (! in_array($admissionPlan->amount->currency->code, $merchant->currencyCodes, true)) {
            $this->refuse('Merchant currency unsupported');
        }
        if (in_array($admissionPlan->kind, [EffectKind::Charge, EffectKind::Authorize], true)) {
            $existing = $this->operation($b->scope, $admissionPlan->operationRef, $transactionContext);
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
            $root = $this->operation($b->scope, $b->rootOperationRef, $transactionContext) ?? throw new LogicException('Child root missing');
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

    public function operation(ExecutionScope $executionScope, string $string, ?TransactionContext $context = null): ?OperationRecord
    {
        $r = $this->query('operation', $executionScope, $context)->where('operation_ref', $string)->first();

        return $r === null ? null : $this->operationRow($r);
    }

    public function attempt(ExecutionScope $executionScope, string $attemptRef, ?TransactionContext $context = null): ?AttemptRecord
    {
        $r = $this->query('attempt', $executionScope, $context)->where('attempt_ref', $attemptRef)->first();

        return $r === null ? null : $this->attemptRow($r);
    }

    public function insertAttempt(TransactionContext $transactionContext, OperationRecord $operationRecord, string $attemptRef, string $providerReference): AttemptRecord
    {
        $this->financial($transactionContext);
        $s = $operationRecord->binding->scope;
        $this->assertScope($s, $transactionContext, $operationRecord->binding->executionProfile);
        $row = $this->operation($s, $operationRecord->operationRef, $transactionContext);
        if (ExecutionCodec::encode($row) !== ExecutionCodec::encode($operationRecord)) {
            $this->refuse('Operation changed before attempt');
        }
        $merchant = $this->merchantRevision($s, $operationRecord->binding->merchantRevisionRef, $transactionContext);
        $digest = AuthorityClaims::immutableDriverRequestDigest($operationRecord->binding, $operationRecord->operationRef, $attemptRef, $providerReference, $operationRecord->kind, $operationRecord->requested);
        $id = $this->database($transactionContext)->table('billium_execution_attempt')->insertGetId($this->scope($s) + ['attempt_ref' => $attemptRef, 'operation_ref' => $operationRecord->operationRef, 'provider_reference' => $providerReference, 'provider_id' => $merchant->providerId, 'merchant_account_ref' => $merchant->merchantAccountRef, 'request_digest' => $digest]);

        return new AttemptRecord($id, $attemptRef, $operationRecord->operationRef, $providerReference, AttemptState::Admitted, 0, null, null, null, null, null, $digest, null, null, null);
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
        $r = (clone $q)->first() ?? throw new LogicException('Attempt absent');
        $this->requireOperationLock($transactionContext, self::text($r->operation_ref));
        $until = $leaseUntil->format('Y-m-d H:i:s.uP');
        $fence = self::integer($r->lease_fence) + 1;
        $work = $this->callerQuery('dispatch_work', $transactionContext)->where('attempt_ref', $attemptRef)->where('operation_ref', $r->operation_ref)
            ->where('lease_fence', $fence - 1)->whereRaw('?::timestamptz > clock_timestamp()', [$until])
            ->whereRaw("((state = 'due' AND due_at <= clock_timestamp()) OR (state = 'leased' AND lease_expires_at <= clock_timestamp()))");
        if ($leaseOwner === '' || ! in_array($r->state, ['admitted', 'dispatching', 'pending', 'unknown'], true)
            || $work->incrementEach(['attempt_count' => 1], ['state' => 'leased', 'lease_owner' => $leaseOwner, 'lease_fence' => $fence, 'lease_expires_at' => $until]) !== 1) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Dispatch work is not available for this attempt');
        }
        $q->update(['lease_owner' => $leaseOwner, 'lease_fence' => $fence, 'lease_expires_at' => $until]);

        return $this->attemptRow($q->first() ?? throw new LogicException('Attempt absent'));
    }

    public function recordAdmissionConflict(TransactionContext $context, AdmissionConflict $conflict): string
    {
        $db = $this->database($context);
        $scope = $conflict->scope;
        $this->assertScope($scope, $context);
        $selected = $context->spec()->financialScope;
        if ($context->spec()->mode !== FinancialMode::RawCustody || $selected === null || $selected->installationRef !== $scope->installationRef
            || $selected->sellerRef !== $scope->sellerRef || $selected->environment !== $scope->environment
            || $selected->intentOwnerNamespace !== $scope->intentOwnerNamespace || $selected->sourceAuthority === null) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Conflict audit requires exact authenticated raw custody');
        }
        if ($conflict->originalOperationRef !== null) {
            $original = $this->operation($scope, $conflict->originalOperationRef, $context);
            if ($original === null || ! in_array($original->binding->executionProfile, $context->caller()->sourceAuthorities, true)
                || $original->binding->executionProfile !== $selected->sourceAuthority) {
                throw new ProblemException(ProblemCode::AuthDenied, 'Original operation is outside the selected scope');
            }
        }
        $material = $this->scope($scope) + ['intent_owner_namespace' => $scope->intentOwnerNamespace, 'source_authority' => $selected->sourceAuthority, 'actor_ref' => $context->caller()->actorRef,
            'command_kind' => $conflict->commandKind, 'command_key_digest' => $conflict->commandKeyDigest, 'semantic_digest' => $conflict->semanticDigest,
            'original_operation_ref' => $conflict->originalOperationRef, 'problem_code' => ProblemCode::IdempotencyConflict->value];
        $ref = 'conflict:'.hash('sha256', (new Codec)->canonicalize(['schema' => 'billium.execution.admission-conflict.v1', ...$material]));
        $table = $db->table('billium_execution_admission_conflict');
        if ($table->insertOrIgnore(['audit_ref' => $ref, ...$material]) !== 1 && ! $table->where('audit_ref', $ref)->where($material)->exists()) {
            throw new LogicException('Admission conflict receipt identity collision');
        }

        return $ref;
    }

    public function cancelAdmitted(TransactionContext $context, OperationRecord $operation, string $attemptRef, string $receiptRef): bool
    {
        $this->financial($context);
        $current = $this->admittedOperation($context, $operation->operationRef);
        $this->requireLock($context, $current->rootOperationRef);
        if ($receiptRef === '' || ExecutionCodec::encode($current) !== ExecutionCodec::encode($operation) || $current->rootOperationRef !== $current->operationRef
            || ! in_array($current->kind, [EffectKind::Charge, EffectKind::Authorize], true) || $current->state !== 'admitted') {
            return false;
        }
        $attempt = $this->callerQuery('attempt', $context)->where('attempt_ref', $attemptRef)->where('operation_ref', $current->operationRef);
        if ($attempt->where('state', 'admitted')->whereNull('started_at')->whereNull('dispatch_ticket_ref')->whereNull('collection_dispatch_permit_ref')
            ->update(['state' => 'cancelled', 'finish_evidence_ref' => $receiptRef, 'lease_owner' => null, 'lease_expires_at' => null]) !== 1) {
            return false;
        }
        $work = $this->callerQuery('dispatch_work', $context)->where('operation_ref', $current->operationRef)->where('attempt_ref', $attemptRef);
        if ($work->whereIn('state', ['due', 'leased'])->update(['state' => 'processed', 'lease_owner' => null, 'lease_expires_at' => null, 'due_at' => null]) !== 1) {
            throw new LogicException('Local cancellation requires the original dispatch work');
        }
        $revision = $current->revision + 1;
        $material = ['schema' => 'billium.execution.cancel-before-dispatch.v1', 'operationRef' => $current->operationRef, 'attemptRef' => $attemptRef,
            'originalBindingDigest' => AuthorityClaims::bindingDigest($current->binding), 'receiptRef' => $receiptRef];
        $digest = 'sha256:'.hash('sha256', (new Codec)->canonicalize($material));
        $this->callerQuery('operation', $context)->where('id', $current->id)->update(['state' => 'cancelled', 'revision' => $revision, 'economic_digest' => $digest]);
        $capacity = $this->capacity($context, $current->rootOperationRef);
        $this->database($context)->table('billium_execution_operation_revision')->insert($this->callerScope($context) + [
            'operation_ref' => $current->operationRef, 'revision' => $revision, 'material_digest' => $digest,
            'requested_minor' => $current->requested->minor, 'currency' => $current->requested->currency->code,
            'observed' => ExecutionCodec::encode($material), 'last_consistent_applied' => ExecutionCodec::encode($capacity),
            'financial_hold' => ExecutionCodec::encode($capacity->financialHold),
            'exposure' => '{}', 'evidence_refs' => ExecutionCodec::encode([$receiptRef]), 'receipt_ref' => $receiptRef, 'occurred_at' => new DateTimeImmutable,
        ]);

        return true;
    }

    public function finishDispatchWork(TransactionContext $context, string $attemptRef, int $expectedFence, WorkState $state, ?DateTimeImmutable $dueAt, ?string $errorCode): bool
    {
        $this->financial($context);
        if ($state === WorkState::Leased || (($state === WorkState::Due) !== ($dueAt !== null))) {
            $this->refuse('Invalid dispatch work completion');
        }
        $q = $this->callerQuery('attempt', $context)->where('attempt_ref', $attemptRef);
        $attempt = (clone $q)->first() ?? throw new LogicException('Attempt absent');
        $this->requireOperationLock($context, self::text($attempt->operation_ref));
        if (self::integer($attempt->lease_fence) !== $expectedFence || $attempt->lease_owner === null) {
            return false;
        }
        $work = $this->callerQuery('dispatch_work', $context)->where('operation_ref', $attempt->operation_ref)->where('attempt_ref', $attemptRef)
            ->where('state', 'leased')->where('lease_owner', $attempt->lease_owner)->where('lease_fence', $expectedFence)->whereRaw('lease_expires_at > clock_timestamp()');
        if (! (clone $work)->exists()) {
            return false;
        }
        if ($state === WorkState::Processed && ! $this->hasQueuedEvidence($context, $attempt)) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Dispatch completion requires original durable provider evidence work');
        }
        if ($work->update(['state' => $state->value, 'due_at' => $dueAt?->format('Y-m-d H:i:s.uP'), 'lease_owner' => null,
            'lease_expires_at' => null, 'last_error_code' => $errorCode, 'last_error_at' => $errorCode === null ? null : new DateTimeImmutable]) !== 1) {
            return false;
        }
        $q->update(['lease_owner' => null, 'lease_expires_at' => null, 'dispatch_ticket_ref' => null]);

        return true;
    }

    private function hasQueuedEvidence(TransactionContext $context, stdClass $attempt): bool
    {
        $scope = $this->selectedScope($context);
        $records = $this->callerQuery('provider_evidence', $context)->where('intent_owner_namespace', $scope->intentOwnerNamespace)
            ->where('provider_id', $attempt->provider_id)->where('merchant_account_ref', $attempt->merchant_account_ref);
        $record = (clone $records)->where('evidence_ref', $attempt->finish_evidence_ref)->first();
        if ($record === null) {
            return false;
        }
        $data = $this->transportData($record, $attempt, self::integer($attempt->lease_fence));
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
            && $this->callerQuery('provider_work', $context)->where('evidence_ref', $evidence->evidence_ref)
                ->where('handler_contract_version', $evidence->handler_contract_version)->where('state', '<>', 'quarantined')->exists();
    }

    /** @return array<string,mixed>|null */
    private function transportData(stdClass $record, stdClass $attempt, int $fence): ?array
    {
        if ($record->handler_contract_version !== 'execution.transport.v1' || ! in_array($record->signature_verdict, ['local_transport', 'verified_no_effect'], true)) {
            return null;
        }
        $data = ExecutionCodec::map(self::text($record->sanitized_payload));
        if (($data['schema'] ?? null) !== 'billium.execution.transport-observation.v1' || ($data['operationRef'] ?? null) !== $attempt->operation_ref
            || ($data['attemptRef'] ?? null) !== $attempt->attempt_ref || ($data['providerReference'] ?? null) !== $attempt->provider_reference
            || ($data['leaseFence'] ?? null) !== (string) $fence) {
            return null;
        }

        return $data;
    }

    public function startDispatch(TransactionContext $transactionContext, DispatchDecision $dispatchDecision): bool
    {
        $this->financial($transactionContext);
        $this->requireOperationLock($transactionContext, $dispatchDecision->operationRef);
        $operation = $this->admittedOperation($transactionContext, $dispatchDecision->operationRef);
        $attempt = $this->attempt($operation->binding->scope, $dispatchDecision->attemptRef, $transactionContext);
        if ($attempt === null || $attempt->operationRef !== $operation->operationRef
            || AuthorityClaims::immutableDriverRequestDigest($operation->binding, $operation->operationRef, $attempt->attemptRef, $attempt->providerReference, $operation->kind, $operation->requested) !== $dispatchDecision->immutableRequestDigest) {
            return false;
        }
        if ($dispatchDecision->sessionEpoch !== $transactionContext->sessionEpoch() || $dispatchDecision->requestDeadlineAt === null
            || $operation->state !== 'admitted' || (($operation->binding->collectionAuthorizationRef === null) !== ($dispatchDecision->collectionDispatchPermitRef === null))) {
            return false;
        }

        $token = $this->sessionAdmission->currentToken($transactionContext->caller()->installationRef);
        if ($token === null || $token->installationRef !== $transactionContext->caller()->installationRef || $token->expired(new DateTimeImmutable) || ! $token->permits($transactionContext->spec()->mode)) {
            throw new LogicException('Cached admitted owner identity is absent');
        }

        $deadline = $dispatchDecision->requestDeadlineAt->format('Y-m-d H:i:s.uP');

        return $this->callerQuery('attempt', $transactionContext)->where('attempt_ref', $dispatchDecision->attemptRef)->where('operation_ref', $dispatchDecision->operationRef)
            ->where('state', 'admitted')->where('request_digest', $dispatchDecision->immutableRequestDigest)->where('lease_fence', $dispatchDecision->leaseFence)
            ->whereNotNull('lease_owner')->where('lease_expires_at', '>=', $deadline)->whereRaw('?::timestamptz > clock_timestamp()', [$deadline])
            ->update(['state' => 'dispatching', 'session_epoch' => $transactionContext->sessionEpoch(), 'owner_session_ref' => $token->ownerSessionRef,
                'dispatch_ticket_ref' => $dispatchDecision->ticketRef, 'collection_dispatch_permit_ref' => $dispatchDecision->collectionDispatchPermitRef,
                'started_at' => $this->database($transactionContext)->raw('clock_timestamp()'), 'request_deadline_at' => $deadline]) === 1;
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
        if (ExecutionCodec::encode($current) !== ExecutionCodec::encode($expected) || $reservation->state !== ReservationState::Held || $reservation->consumed->minor !== 0 || $reservation->requested->minor <= 0 || ExecutionCodec::encode($reservation->requested->currency) !== ExecutionCodec::encode($current->authorizedLimit->currency) || $reservation->requested->minor > CapacityAmounts::available($current, $reservation->kind)) {
            $this->refuse('Capacity reservation conflict');
        }
        $this->insertHeldReservation($transactionContext, $reservation, $current);

        return $this->capacity($transactionContext, $reservation->rootOperationRef);
    }

    private function insertHeldReservation(TransactionContext $transactionContext, Reservation $reservation, CapacitySnapshot $expected): void
    {
        $this->database($transactionContext)->table('billium_execution_reservation')->insert($this->callerScope($transactionContext) + ['reservation_ref' => $reservation->reservationRef, 'root_operation_ref' => $reservation->rootOperationRef, 'operation_ref' => $reservation->operationRef, 'kind' => $reservation->kind->value, 'requested_minor' => $reservation->requested->minor, 'currency' => $reservation->requested->currency->code, 'state' => 'held']);
        $column = match ($reservation->kind) {
            EffectKind::Capture => 'held_capture_minor',EffectKind::VoidAuthorization => 'held_void_minor',EffectKind::Refund => 'held_refund_minor',default => throw new LogicException('Invalid reservation kind')
        };
        $updated = $this->callerQuery('capacity', $transactionContext)->where('root_operation_ref', $reservation->rootOperationRef)
            ->where('version', $expected->version)->incrementEach([$column => $reservation->requested->minor, 'version' => 1]);
        if ($updated !== 1) {
            $this->refuse('Held reservation capacity changed');
        }
    }

    public function settleReservation(TransactionContext $transactionContext, string $reservationRef, ReservationState $reservationState, Money $consumed, ?string $releaseEvidenceRef, CapacitySnapshot $next): void
    {
        $current = $this->capacity($transactionContext, $next->rootOperationRef);
        $q = $this->callerQuery('reservation', $transactionContext)->where('reservation_ref', $reservationRef);
        $r = (clone $q)->first() ?? throw new LogicException('Reservation absent');
        if ($next->version !== $current->version + 1 || self::text($r->root_operation_ref) !== $next->rootOperationRef || self::text($r->state) !== 'held' || $reservationState === ReservationState::Held || $consumed->minor < 0 || $consumed->minor > self::integer($r->requested_minor) || $consumed->currency->code !== self::text($r->currency)) {
            $this->refuse('Reservation settlement conflict');
        }
        $heldColumn = match (EffectKind::from(self::text($r->kind))) {
            EffectKind::Capture => 'heldCapture', EffectKind::Refund => 'heldRefund', EffectKind::VoidAuthorization => 'heldVoid',
            default => throw new LogicException('Invalid reservation kind'),
        };
        foreach (['heldCapture', 'heldRefund', 'heldVoid'] as $column) {
            $expectedHeld = $current->{$column}->minor - ($column === $heldColumn ? self::integer($r->requested_minor) : 0);
            if ($expectedHeld < 0 || $next->{$column}->minor !== $expectedHeld) {
                $this->refuse('Settlement must remove the original held reservation exactly once');
            }
        }
        $operationRef = self::text($r->operation_ref);
        $this->requireOperationLock($transactionContext, $operationRef);
        $fact = $releaseEvidenceRef === null ? null : $this->factForEvidence($transactionContext, $releaseEvidenceRef, $operationRef);
        if ($consumed->minor < self::integer($r->requested_minor) && $this->hasGreaterActual($transactionContext, $operationRef, $consumed->minor)) {
            $this->refuse('Reservation release contradicts accepted original operation amount');
        }
        $signed = $fact?->snapshot;
        $valid = $signed !== null && $signed->kind->value === $r->kind && $signed->operationAmount !== null
            && $signed->operationAmount->minor === $consumed->minor && $signed->operationAmount->currency->code === $r->currency
            && ($reservationState === ReservationState::Released
                ? $consumed->minor === 0 && in_array($signed->normalizedStatus, ['declined', 'failed', 'no_effect'], true)
                : $consumed->minor > 0 && $signed->normalizedStatus === 'succeeded');
        if (! $valid) {
            $attempt = $this->attemptForOperation($this->admittedOperation($transactionContext, $operationRef)->binding->scope, $operationRef, $transactionContext);
            if ($reservationState !== ReservationState::Released || $consumed->minor !== 0 || $releaseEvidenceRef === null || $attempt === null
                || ! $this->callerQuery('certified_absence', $transactionContext)->where('attempt_ref', $attempt->attemptRef)->where('evidence_ref', $releaseEvidenceRef)->exists()) {
                $this->refuse('Reservation requires accepted original operation amount or verified absence');
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
        $db = $this->database($transactionContext);
        $scope = $this->scope($providerEvidence->scope);
        $fields = ['intent_owner_namespace' => $providerEvidence->scope->intentOwnerNamespace, 'provider_id' => $providerEvidence->providerId,
            'merchant_account_ref' => $providerEvidence->merchantAccountRef, 'provider_event_id' => $providerEvidence->providerEventId,
            'payload_digest' => $providerEvidence->payloadDigest, 'signature_verdict' => $providerEvidence->signatureVerdict,
            'handler_contract_version' => $providerEvidence->handlerContractVersion, 'secure_payload_ref' => $providerEvidence->securePayloadRef];
        $delivery = $scope + $fields + ['received_at' => $providerEvidence->receivedAt->format('Y-m-d H:i:s.uP'), 'sanitized_payload' => $payload, 'evidence_ref' => $providerEvidence->evidenceRef];
        $rows = $db->table('billium_execution_provider_evidence')->where($scope);
        $conflict = false;
        if ($providerEvidence->providerEventId !== null && $providerEvidence->signatureVerdict !== 'unverified') {
            // Serialize first deliveries with different receipt references for the same provider event.
            $key = ['schema' => 'billium.execution.evidence-event.v1', ...$scope, 'provider' => $providerEvidence->providerId,
                'account' => $providerEvidence->merchantAccountRef, 'event' => $providerEvidence->providerEventId];
            $db->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [(new Codec)->canonicalize($key)]);
            $event = (clone $rows)->where('provider_id', $providerEvidence->providerId)->where('merchant_account_ref', $providerEvidence->merchantAccountRef)
                ->where('provider_event_id', $providerEvidence->providerEventId)->whereNotIn('signature_verdict', ['unverified', 'quarantined_conflict'])->orderBy('id')->first();
            $conflict = $event !== null && ! $this->sameDelivery($event, $fields, $payload);
        }
        if (! $conflict) {
            if ($db->table('billium_execution_provider_evidence')->insertOrIgnore($delivery) === 1) {
                return $providerEvidence->evidenceRef;
            }
            $existing = $rows->where('evidence_ref', $providerEvidence->evidenceRef)->first();
            if ($existing !== null && $this->sameDelivery($existing, $fields, $payload)) {
                return $providerEvidence->evidenceRef;
            }
        }
        $ref = 'conflict:'.bin2hex(random_bytes(20));
        $delivery['evidence_ref'] = $ref;
        $delivery['signature_verdict'] = 'quarantined_conflict';
        $delivery['sanitized_payload'] = ExecutionCodec::encode(['externalEvidenceRef' => $providerEvidence->evidenceRef,
            'originalVerdict' => $providerEvidence->signatureVerdict, 'originalPayload' => $providerEvidence->sanitizedPayload]);
        $db->table('billium_execution_provider_evidence')->insert($delivery);

        return $ref;
    }

    /** @param array<string,string|null> $fields */
    private function sameDelivery(stdClass $row, array $fields, string $payload): bool
    {
        foreach ($fields as $column => $value) {
            if ($row->{$column} !== $value) {
                return false;
            }
        }

        return ExecutionCodec::encode(json_decode(self::text($row->sanitized_payload), true, 64, JSON_THROW_ON_ERROR)) === $payload;
    }

    public function attemptForOperation(ExecutionScope $scope, string $operationRef, ?TransactionContext $context = null): ?AttemptRecord
    {
        $row = $this->query('attempt', $scope, $context)->where('operation_ref', $operationRef)->first();

        return $row === null ? null : $this->attemptRow($row);
    }

    public function reservation(TransactionContext $context, string $reservationRef): ?Reservation
    {
        $this->financial($context);
        $row = $this->callerQuery('reservation', $context)->where('reservation_ref', $reservationRef)
            ->whereIn('operation_ref', $this->callerQuery('operation', $context)->where('intent_owner_namespace', $this->selectedScope($context)->intentOwnerNamespace)->select('operation_ref'))->first();

        return $row === null ? null : $this->reservationRow($context, $row);
    }

    public function reservationForOperation(TransactionContext $context, string $operationRef): ?Reservation
    {
        $this->financial($context);
        $this->requireOperationLock($context, $operationRef);
        $row = $this->callerQuery('reservation', $context)->where('operation_ref', $operationRef)->first();

        return $row === null ? null : $this->reservationRow($context, $row);
    }

    private function reservationRow(TransactionContext $context, stdClass $row): Reservation
    {
        $this->requireOperationLock($context, self::text($row->operation_ref));
        $currency = Currency::of(self::text($row->currency));

        return new Reservation(self::text($row->reservation_ref), self::text($row->root_operation_ref), self::text($row->operation_ref),
            EffectKind::from(self::text($row->kind)), new Money(self::integer($row->requested_minor), $currency),
            ReservationState::from(self::text($row->state)), new Money(self::integer($row->consumed_minor), $currency), self::nullableText($row->release_evidence_ref));
    }

    public function lastObserved(TransactionContext $context, string $operationRef): ?MaterialSnapshot
    {
        $this->financial($context);
        $this->requireOperationLock($context, $operationRef);
        // An accepted provider revision can advance ordering without changing money or creating an operation revision.
        $value = $this->orderingFacts($context)->where('operation_ref', $operationRef)->orderByDesc('id')->value('normalized_snapshot');

        return $value === null ? null : ExecutionCodec::decode(MaterialSnapshot::class, self::text($value));
    }

    private function orderingFacts(TransactionContext $context): Builder
    {
        return $this->callerQuery('provider_fact', $context)->where('evidence_mode', ProviderEvidenceMode::CumulativeSnapshot->value)
            ->whereColumn('normalized_snapshot->operationRef', 'operation_ref')->whereNotNull('normalized_snapshot->bindingDigest')
            ->whereNotNull('normalized_snapshot->providerReference')->where(static fn (Builder $q): Builder => $q
            ->whereNotNull('normalized_snapshot->operationAmount')->orWhereIn('normalized_snapshot->normalizedStatus', ['pending', 'unknown']));
    }

    public function rootOrderingFrontier(TransactionContext $context, string $rootRef, MaterialSnapshot $incoming): ?MaterialSnapshot
    {
        $this->financial($context);
        $this->requireOperationLock($context, $rootRef);
        $root = $this->admittedOperation($context, $rootRef);
        $operations = $this->query('operation', $root->binding->scope, $context)->where('root_operation_ref', $rootRef)->select('operation_ref');
        $query = $this->orderingFacts($context)->whereIn('operation_ref', $operations);
        if ($incoming->providerRevision !== null && preg_match('/^(0|[1-9][0-9]*)$/D', $incoming->providerRevision) === 1) {
            // Comparable decimal revisions sort exactly even when they exceed bigint. A late sibling can have a newer journal id.
            $revision = "(normalized_snapshot->>'providerRevision')";
            $numeric = 'CASE WHEN '.$revision." ~ '^(0|[1-9][0-9]*)$' THEN ".$revision.' END';
            $query->orderByRaw('length('.$numeric.') DESC NULLS LAST')->orderByRaw($numeric.' COLLATE "C" DESC NULLS LAST');
        }
        $value = $query->orderByDesc('id')->value('normalized_snapshot');

        return $value === null ? null : ExecutionCodec::decode(MaterialSnapshot::class, self::text($value));
    }

    public function latestRootObservation(TransactionContext $context, string $rootRef): ?MaterialSnapshot
    {
        $this->financial($context);
        $this->requireOperationLock($context, $rootRef);
        $value = $this->callerQuery('operation_revision', $context)->where('operation_ref', $rootRef)
            ->whereNotNull('observed->authorizedTotal')->orderByDesc('id')->value('observed');

        return $value === null ? null : ExecutionCodec::decode(MaterialSnapshot::class, self::text($value));
    }

    public function providerWork(string $workRef, ?TransactionContext $context = null): ?ProviderWorkInput
    {
        $db = $this->database($context);
        $caller = $context?->caller() ?? $this->callers->current();
        $base = ['installation_ref' => $caller->installationRef, 'seller_ref' => $caller->sellerRef, 'environment' => $caller->environment];
        $deliveries = $db->table('billium_execution_provider_evidence')->where($base)->whereIn('intent_owner_namespace', $caller->intentOwnerNamespaces);
        if ($context !== null) {
            $deliveries->where('intent_owner_namespace', $context->spec()->financialScope?->intentOwnerNamespace);
        }
        $row = $db->table('billium_execution_provider_work')->where($base)->where('work_ref', $workRef)
            ->whereIn('evidence_ref', (clone $deliveries)->select('evidence_ref'))->first();
        if ($row === null) {
            return null;
        }
        $raw = $deliveries->where('evidence_ref', $row->evidence_ref)->sole();
        $scope = new ExecutionScope($caller->installationRef, $caller->sellerRef, $caller->environment, self::text($raw->intent_owner_namespace));
        $evidence = new ProviderEvidence(self::text($raw->evidence_ref), $scope, self::text($raw->provider_id), self::text($raw->merchant_account_ref),
            self::nullableText($raw->provider_event_id), self::text($raw->payload_digest), self::text($raw->signature_verdict), self::text($raw->handler_contract_version),
            new DateTimeImmutable(self::text($raw->received_at)), ExecutionCodec::map(self::text($raw->sanitized_payload)), self::nullableText($raw->secure_payload_ref));
        $hint = self::nullableText($row->operation_hint) ?? $this->transportHint($evidence, $context);

        return new ProviderWorkInput($evidence, self::text($row->handler_contract_version), self::text($row->state), $hint, self::nullableText($row->result_ref), self::nullableText($row->last_error_code));
    }

    private function transportHint(ProviderEvidence $evidence, ?TransactionContext $context): ?string
    {
        $links = $this->query('provider_evidence', $evidence->scope, $context)->where('intent_owner_namespace', $evidence->scope->intentOwnerNamespace)
            ->where('provider_id', $evidence->providerId)->where('merchant_account_ref', $evidence->merchantAccountRef)
            ->where('handler_contract_version', 'execution.transport.v1')->whereIn('signature_verdict', ['local_transport', 'verified_no_effect'])
            ->where('sanitized_payload->schema', 'billium.execution.transport-observation.v1')
            ->where(function (Builder $q) use ($evidence): void {
                $q->where('evidence_ref', $evidence->evidenceRef)->orWhere('sanitized_payload->retainedProviderEvidenceRef', $evidence->evidenceRef);
            })->get();
        $hint = null;
        foreach ($links as $link) {
            $data = ExecutionCodec::map(self::text($link->sanitized_payload));
            $ref = $data['attemptRef'] ?? null;
            $attempt = is_string($ref) ? $this->attempt($evidence->scope, $ref, $context) : null;
            if ($attempt === null || ($data['providerReference'] ?? null) !== $attempt->providerReference || ($data['operationRef'] ?? null) !== $attempt->operationRef
                || ($hint !== null && $hint !== $attempt->operationRef)) {
                return null;
            }
            $hint = $attempt->operationRef;
        }

        return $hint;
    }

    private function scopedWork(TransactionContext $context): Builder
    {
        $caller = $context->caller();
        $selected = $context->spec()->financialScope;
        $owner = $context->spec()->mode->financial() ? $this->selectedScope($context)->intentOwnerNamespace : $selected?->intentOwnerNamespace;
        if ($owner === null || ! in_array($owner, $caller->intentOwnerNamespaces, true) || $selected?->installationRef !== $caller->installationRef
            || $selected->sellerRef !== $caller->sellerRef || $selected->environment !== $caller->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Provider work requires the authorized custody owner');
        }
        $evidence = $this->callerQuery('provider_evidence', $context)->where('intent_owner_namespace', $owner);

        return $this->callerQuery('provider_work', $context)->whereIn('evidence_ref', $evidence->select('evidence_ref'));
    }

    public function enqueueProviderWork(TransactionContext $transactionContext, string $evidenceRef, string $workRef, string $handlerVersion, ?string $operationHint = null): string
    {
        $this->custody($transactionContext);
        $evidence = $this->callerQuery('provider_evidence', $transactionContext)->where('evidence_ref', $evidenceRef)->first() ?? throw new LogicException('Evidence absent');
        $caller = $transactionContext->caller();
        $namespace = self::text($evidence->intent_owner_namespace);
        if (! in_array($namespace, $caller->intentOwnerNamespaces, true)
            || ($transactionContext->spec()->financialScope !== null && $transactionContext->spec()->financialScope->intentOwnerNamespace !== $namespace)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Provider work differs from custody scope');
        }
        $state = $evidence->signature_verdict === 'quarantined_conflict' ? 'quarantined' : 'due';
        $this->database($transactionContext)->table('billium_execution_provider_work')->insertOrIgnore($this->callerScope($transactionContext) + ['evidence_ref' => $evidenceRef,
            'work_ref' => $workRef, 'handler_contract_version' => $handlerVersion, 'state' => $state, 'operation_hint' => $operationHint]);
        $stored = $this->scopedWork($transactionContext)->where('work_ref', $workRef)->first();
        $stored ??= $this->scopedWork($transactionContext)->where('evidence_ref', $evidenceRef)->where('handler_contract_version', $handlerVersion)->first();
        if ($stored === null || $stored->evidence_ref !== $evidenceRef || $stored->handler_contract_version !== $handlerVersion
            || ($operationHint !== null && $stored->operation_hint !== null && $stored->operation_hint !== $operationHint)) {
            $this->refuse('Provider work identity conflict');
        }

        return self::text($stored->work_ref);
    }

    public function claimProviderWork(TransactionContext $transactionContext, string $workRef, string $leaseOwner, DateTimeImmutable $until): ?ProviderWorkClaim
    {
        $this->custody($transactionContext);
        $q = $this->scopedWork($transactionContext)->where('work_ref', $workRef);
        $r = (clone $q)->first();
        if ($r === null || $leaseOwner === '' || $until <= new DateTimeImmutable || ! in_array($r->state, ['due', 'leased'], true) || new DateTimeImmutable(self::text($r->due_at)) > new DateTimeImmutable || ($r->state === 'leased' && self::date($r->lease_expires_at) > new DateTimeImmutable)) {
            return null;
        }
        $fence = self::integer($r->lease_fence) + 1;
        $count = self::integer($r->attempt_count) + 1;
        $updated = $q->where('lease_fence', self::integer($r->lease_fence))->where('state', self::text($r->state))
            ->whereRaw('due_at <= clock_timestamp()')->whereRaw('?::timestamptz > clock_timestamp()', [$until->format('Y-m-d H:i:s.uP')])
            ->where(static fn (Builder $eligible): Builder => $eligible->where('state', 'due')->orWhere(static fn (Builder $expired): Builder => $expired->where('state', 'leased')->whereRaw('lease_expires_at <= clock_timestamp()')))
            ->update(['state' => 'leased', 'lease_owner' => $leaseOwner, 'lease_fence' => $fence, 'lease_expires_at' => $until->format('Y-m-d H:i:s.uP'), 'attempt_count' => $count]);
        if ($updated !== 1) {
            return null;
        }

        return new ProviderWorkClaim($workRef, self::text($r->evidence_ref), self::text($r->handler_contract_version), $count, $fence, $leaseOwner, $until);
    }

    public function finishProviderWork(TransactionContext $transactionContext, ProviderWorkClaim $providerWorkClaim, string $state, ?string $resultRef, ?string $errorCode, ?DateTimeImmutable $dueAt): bool
    {
        $this->custody($transactionContext);
        if (! in_array($state, ['due', 'processed', 'parked', 'quarantined'], true) || ($state === 'due' && ($dueAt === null || $dueAt <= new DateTimeImmutable))) {
            $this->refuse('Invalid work completion');
        }

        return $this->scopedWork($transactionContext)->where('evidence_ref', $providerWorkClaim->evidenceRef)->where('work_ref', $providerWorkClaim->workRef)->where('handler_contract_version', $providerWorkClaim->handlerContractVersion)->where('state', 'leased')->where('lease_owner', $providerWorkClaim->leaseOwner)->where('lease_fence', $providerWorkClaim->leaseFence)->whereRaw('lease_expires_at > clock_timestamp()')->update(['state' => $state, 'lease_owner' => null, 'lease_expires_at' => null, 'result_ref' => $resultRef, 'last_error_code' => $errorCode, 'last_error_at' => $errorCode === null ? null : new DateTimeImmutable, 'due_at' => $dueAt ?? new DateTimeImmutable]) === 1;
    }

    public function providerOperationId(ExecutionScope $scope, string $attemptRef): ?string
    {
        $attempt = $this->query('attempt', $scope)->where('attempt_ref', $attemptRef);

        return self::nullableText($this->query('provider_identity', $scope)->whereIn('attempt_ref', $attempt->select('attempt_ref'))->value('provider_operation_id'));
    }

    public function operationByProviderId(ExecutionScope $scope, string $providerId, string $merchantAccountRef, string $providerNamespace, string $providerOperationId): ?OperationRecord
    {
        $row = $this->query('provider_identity', $scope)->whereRaw('public.billium_execution_provider_identity_key(environment,provider_id,merchant_account_ref,provider_namespace,provider_operation_id) = public.billium_execution_provider_identity_key(?,?,?,?,?)',
            [$scope->environment, $providerId, $merchantAccountRef, $providerNamespace, $providerOperationId])
            ->where('provider_id', $providerId)->where('merchant_account_ref', $merchantAccountRef)
            ->where('provider_namespace', $providerNamespace)->where('provider_operation_id', $providerOperationId)->first();
        $attempt = $row === null ? null : $this->attempt($scope, self::text($row->attempt_ref));

        return $attempt === null ? null : $this->operation($scope, $attempt->operationRef);
    }

    public function bindProviderOperationId(TransactionContext $context, AttemptRecord $attempt, ProviderFact $fact): bool
    {
        $identity = $this->qualifiedFactIdentity($context, $attempt, $fact);
        if ($identity === null) {
            return true;
        }
        $scope = $this->selectedScope($context);
        $byAttempt = $this->callerQuery('provider_identity', $context)->where('attempt_ref', $attempt->attemptRef);
        if ((clone $byAttempt)->exists()) {
            unset($identity['evidence_ref']);

            return $byAttempt->where($identity)->exists();
        }

        return ! $this->database($context)->table('billium_execution_provider_identity')->whereRaw('public.billium_execution_provider_identity_key(environment,provider_id,merchant_account_ref,provider_namespace,provider_operation_id) = public.billium_execution_provider_identity_key(?,?,?,?,?)',
            [$scope->environment, $identity['provider_id'], $identity['merchant_account_ref'], $identity['provider_namespace'], $identity['provider_operation_id']])->exists();
    }

    /** @return array<string,string>|null */
    private function qualifiedFactIdentity(TransactionContext $context, AttemptRecord $attempt, ProviderFact $fact): ?array
    {
        $this->financial($context);
        $operation = $this->admittedOperation($context, $fact->operationRef);
        $this->requireOperationLock($context, $operation->operationRef);
        $scope = $operation->binding->scope;
        $stored = $this->attemptForOperation($scope, $fact->operationRef, $context);
        $snapshot = $fact->snapshot;
        if ($stored === null || $attempt->id !== $stored->id || $attempt->operationRef !== $stored->operationRef || $attempt->attemptRef !== $stored->attemptRef
            || $snapshot->providerReference !== $stored->providerReference || $snapshot->bindingDigest !== ExecutionCodec::bindingDigest($operation->binding)
            || $snapshot->operationRef !== $operation->operationRef || $snapshot->kind !== $operation->kind
            || $snapshot->merchantAccountRef !== $operation->binding->merchantAccountRef || $snapshot->currency !== $operation->requested->currency->code
            || ($snapshot->operationAmount === null && (! in_array($snapshot->normalizedStatus, ['pending', 'unknown'], true) || $fact->mode !== ProviderEvidenceMode::CumulativeSnapshot))
            || ($snapshot->operationAmount !== null && ExecutionCodec::encode($snapshot->operationAmount->currency) !== ExecutionCodec::encode($operation->requested->currency))
            || $fact->evidenceRefs === [] || $fact->evidenceRefs !== $snapshot->evidenceRefs) {
            $this->refuse('Provider fact differs from original attempt, binding or actual amount');
        }
        $merchant = $this->merchantRevision($scope, $operation->binding->merchantRevisionRef, $context);
        $refs = array_unique($fact->evidenceRefs);
        $evidence = $this->query('provider_evidence', $scope, $context)->where('intent_owner_namespace', $scope->intentOwnerNamespace)
            ->where('merchant_account_ref', $snapshot->merchantAccountRef)->where('provider_id', $merchant->providerId)
            ->whereNotIn('signature_verdict', ['quarantined_conflict', 'verified_no_effect', 'local_transport'])->whereIn('evidence_ref', $refs);
        if ($evidence->count() !== count($refs)) {
            $this->refuse('Authenticated evidence absent from original financial scope');
        }
        $capability = $this->capability($scope, $merchant->capabilitySnapshotRef, $context);
        if ($fact->providerIdentityScope !== ExecutionCodec::providerIdentityScope($merchant, $capability)) {
            $this->refuse('Provider fact namespace differs from frozen provider, account and environment');
        }
        if ($snapshot->providerOperationId === null) {
            return null;
        }
        $namespace = $capability->details->lookupNamespace;

        return ['attempt_ref' => $attempt->attemptRef, 'provider_id' => $merchant->providerId, 'merchant_account_ref' => $merchant->merchantAccountRef,
            'provider_namespace' => $namespace, 'provider_operation_id' => $snapshot->providerOperationId, 'evidence_ref' => $refs[0]];
    }

    public function factByIdentity(TransactionContext $context, string $providerIdentityScope, string $factIdentity): ?ProviderFact
    {
        $this->financial($context);
        $owned = $this->callerQuery('operation', $context)->where('intent_owner_namespace', $this->selectedScope($context)->intentOwnerNamespace)->select('operation_ref');
        $row = $this->callerQuery('provider_fact', $context)->where('provider_identity_scope', $providerIdentityScope)->where('fact_identity', $factIdentity)
            ->whereNotNull('normalized_snapshot->bindingDigest')->whereNotNull('normalized_snapshot->providerReference')->whereIn('operation_ref', $owned)->first();

        return $row === null ? null : $this->factRow($row);
    }

    public function factForEvidence(TransactionContext $context, string $evidenceRef, string $operationRef): ?ProviderFact
    {
        $this->financial($context);
        $this->requireOperationLock($context, $operationRef);
        $query = $this->callerQuery('provider_fact', $context)->where('operation_ref', $operationRef)->whereJsonContains('evidence_refs', $evidenceRef)
            ->whereNotNull('normalized_snapshot->bindingDigest')->whereNotNull('normalized_snapshot->operationAmount');
        $rows = (clone $query)->where('evidence_mode', ProviderEvidenceMode::CumulativeSnapshot->value)->limit(2)->get();
        if ($rows->isEmpty()) {
            $rows = $query->limit(2)->get();
        }

        return $rows->count() === 1 ? $this->factRow($rows->sole()) : null;
    }

    private function factRow(stdClass $r): ProviderFact
    {
        return new ProviderFact(self::text($r->fact_ref), self::text($r->operation_ref), self::text($r->provider_identity_scope), self::text($r->fact_identity),
            self::text($r->material_digest), ProviderEvidenceMode::from(self::text($r->evidence_mode)), ExecutionCodec::decode(MaterialSnapshot::class, self::text($r->normalized_snapshot)),
            self::listJson(self::text($r->evidence_refs)));
    }

    public function appendFact(TransactionContext $transactionContext, ProviderFact $providerFact): bool
    {
        $this->financial($transactionContext);
        $operation = $this->admittedOperation($transactionContext, $providerFact->operationRef);
        $this->requireOperationLock($transactionContext, $operation->operationRef);
        $attempt = $this->attemptForOperation($operation->binding->scope, $operation->operationRef, $transactionContext) ?? throw new LogicException('Original attempt absent');
        $db = $this->database($transactionContext);
        $identity = $this->qualifiedFactIdentity($transactionContext, $attempt, $providerFact);
        $row = $this->callerScope($transactionContext) + ['fact_ref' => $providerFact->factRef, 'operation_ref' => $providerFact->operationRef,
            'provider_identity_scope' => $providerFact->providerIdentityScope, 'fact_identity' => $providerFact->factIdentity, 'evidence_mode' => $providerFact->mode->value,
            'material_digest' => $providerFact->materialDigest, 'normalized_snapshot' => $providerFact->snapshot, 'evidence_refs' => $providerFact->evidenceRefs];
        $legacy = $this->hasUnqualifiedHistory($transactionContext, $operation, $providerFact);
        $result = $legacy ? null : $db->selectOne('SELECT public.billium_execution_accept_provider_fact(?::jsonb, ?::jsonb) AS inserted',
            [ExecutionCodec::encode($row), $identity === null ? null : ExecutionCodec::encode($identity)]);
        if ($result instanceof stdClass && $result->inserted === true) {
            return true;
        }
        $winner = $this->factByIdentity($transactionContext, $providerFact->providerIdentityScope, $providerFact->factIdentity);
        if ($legacy || $winner === null || $winner->operationRef !== $operation->operationRef || $winner->materialDigest !== $providerFact->materialDigest
            || $winner->mode !== $providerFact->mode || $winner->snapshot->providerOperationId !== $providerFact->snapshot->providerOperationId) {
            $db->table('billium_execution_discrepancy')->insertOrIgnore($this->callerScope($transactionContext) + ['discrepancy_ref' => 'fact-conflict:'.hash('sha256', ExecutionCodec::encode($providerFact)),
                'root_operation_ref' => $operation->rootOperationRef, 'operation_ref' => $operation->operationRef, 'kind' => 'provider_fact_identity_conflict',
                'evidence_refs' => ExecutionCodec::encode($providerFact->evidenceRefs), 'observed' => ExecutionCodec::encode($providerFact->snapshot),
                'last_consistent_applied' => ExecutionCodec::encode($this->capacity($transactionContext, $operation->rootOperationRef)),
                'exposure' => ExecutionCodec::encode(['disposition' => 'quarantined_identity', 'factRef' => $providerFact->factRef])]);
        }

        return false;
    }

    private function hasUnqualifiedHistory(TransactionContext $context, OperationRecord $operation, ProviderFact $fact): bool
    {
        $merchant = $this->merchantRevision($operation->binding->scope, $operation->binding->merchantRevisionRef, $context);
        $namespace = $this->capability($operation->binding->scope, $merchant->capabilitySnapshotRef, $context)->details->lookupNamespace;
        $history = $this->database($context)->selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM billium_execution_provider_fact f
                JOIN billium_execution_operation o USING (installation_ref,seller_ref,environment,operation_ref)
                JOIN billium_execution_merchant_revision m ON m.installation_ref=o.installation_ref AND m.seller_ref=o.seller_ref
                    AND m.environment=o.environment AND m.revision_ref=o.merchant_revision_ref
                JOIN billium_execution_capability_snapshot c ON c.installation_ref=m.installation_ref AND c.seller_ref=m.seller_ref
                    AND c.environment=m.environment AND c.snapshot_ref=m.capability_snapshot_ref
                WHERE (f.normalized_snapshot->>'bindingDigest' IS NULL OR f.normalized_snapshot->>'providerReference' IS NULL OR
                    (f.normalized_snapshot->>'operationAmount' IS NULL AND f.normalized_snapshot->>'normalizedStatus' NOT IN ('pending','unknown')))
                    AND f.environment=? AND m.provider_id=? AND m.merchant_account_ref=? AND c.body#>>'{details,lookupNamespace}'=?
                    AND o.kind=? AND (f.fact_identity=? OR f.normalized_snapshot->>'stableEffectId'=?
                        OR (f.operation_ref=? AND f.normalized_snapshot->>'providerRevision' IS NOT DISTINCT FROM ?))
            ) AS present
            SQL, [$operation->binding->scope->environment, $merchant->providerId, $merchant->merchantAccountRef, $namespace, $operation->kind->value,
            $fact->factIdentity, $fact->snapshot->stableEffectId, $operation->operationRef, $fact->snapshot->providerRevision]);

        return $history instanceof stdClass && $history->present === true;
    }

    public function applyAttemptFact(TransactionContext $context, AttemptRecord $attempt, ProviderFact $fact, AttemptState $state, ?DateTimeImmutable $lookupDueAt): void
    {
        $stored = $this->factByIdentity($context, $fact->providerIdentityScope, $fact->factIdentity);
        $status = $fact->snapshot->normalizedStatus;
        $valid = match ($state) {
            AttemptState::Succeeded => $status === 'succeeded',
            AttemptState::Failed => in_array($status, ['failed', 'declined', 'no_effect'], true) && $fact->snapshot->operationAmount?->minor === 0,
            AttemptState::Pending, AttemptState::Unknown => in_array($status, ['pending', 'unknown'], true),
            default => false,
        };
        if (! $valid || $stored === null || $stored->operationRef !== $attempt->operationRef || $fact->evidenceRefs === [] || ExecutionCodec::encode($stored) !== ExecutionCodec::encode($fact)) {
            $this->refuse('Attempt transition requires original accepted fact');
        }
        $this->advanceAttempt($context, $attempt, $state, $fact->evidenceRefs[0], $lookupDueAt);
    }

    private function advanceAttempt(TransactionContext $context, AttemptRecord $attempt, AttemptState $state, string $evidenceRef, ?DateTimeImmutable $lookupDueAt): void
    {
        $this->requireOperationLock($context, $attempt->operationRef);
        $q = $this->callerQuery('attempt', $context)->where('id', $attempt->id)->where('attempt_ref', $attempt->attemptRef)->where('operation_ref', $attempt->operationRef);
        $r = (clone $q)->first() ?? throw new LogicException('Original attempt absent');
        if (! ($this->held[$context]['billium_execution_attempt:'.$attempt->id] ?? false) || $r->lease_fence !== $attempt->leaseFence
            || $r->provider_reference !== $attempt->providerReference || $r->request_digest !== $attempt->immutableRequestDigest) {
            $this->refuse('Original locked attempt and current fence required');
        }
        $terminal = in_array($state, [AttemptState::Succeeded, AttemptState::Failed], true);
        if ($terminal !== ($lookupDueAt === null) || ($lookupDueAt !== null && $lookupDueAt <= new DateTimeImmutable)) {
            $this->refuse('Attempt lookup deadline differs from factual state');
        }
        if (in_array($r->state, ['succeeded', 'failed', 'cancelled'], true)) {
            if ($state->value === $r->state) {
                return;
            }
            if (in_array($r->state, ['cancelled', 'failed'], true) && $state === AttemptState::Succeeded
                && $this->capacity($context, $this->admittedOperation($context, $attempt->operationRef)->rootOperationRef)->financialHold) {
                return;
            }
            $this->refuse('Terminal attempt cannot regress');
        }
        if ($r->started_at === null || ! in_array($r->state, ['dispatching', 'pending', 'unknown'], true)) {
            $this->refuse('Factual completion requires original post-dispatch attempt');
        }
        $work = $this->callerQuery('dispatch_work', $context)->where('attempt_ref', $attempt->attemptRef)->where('operation_ref', $attempt->operationRef)->where('lease_fence', $attempt->leaseFence);
        $revoke = ['lease_owner' => null, 'lease_expires_at' => null, 'lease_fence' => $attempt->leaseFence + 1];
        $updated = $work->update($revoke + ['state' => $terminal ? 'processed' : 'due', 'due_at' => $lookupDueAt?->format('Y-m-d H:i:s.uP')]);
        $imported = $updated === 0 && $this->callerQuery('original_identity_import', $context)->where('attempt_ref', $attempt->attemptRef)
            ->where('operation_ref', $attempt->operationRef)->where('intent_owner_namespace', $this->selectedScope($context)->intentOwnerNamespace)->exists()
            && ! $this->callerQuery('dispatch_work', $context)->where('attempt_ref', $attempt->attemptRef)->exists();
        if (($updated !== 1 && ! $imported)
            || $q->where('lease_fence', $attempt->leaseFence)->update($revoke + ['state' => $state->value, 'finish_evidence_ref' => $evidenceRef, 'dispatch_ticket_ref' => null]) !== 1) {
            $this->refuse('Dispatch fence changed during factual settlement');
        }
    }

    public function applyCertifiedAbsence(TransactionContext $context, AttemptRecord $attempt, ProviderWorkClaim $claim, VerifiedAbsence $proof, ?CapacitySnapshot $releasedCapacity): void
    {
        $this->financial($context);
        $this->requireIssuedProof($proof);
        $operation = $this->admittedOperation($context, $attempt->operationRef);
        $scope = $operation->binding->scope;
        $this->requireOperationLock($context, $operation->operationRef);
        $certificate = $proof->certificate;
        $stored = $this->attemptForOperation($scope, $operation->operationRef, $context);
        $now = new DateTimeImmutable;
        if ($stored === null || ExecutionCodec::encode($attempt) !== ExecutionCodec::encode($stored)
            || ! in_array($stored->state, [AttemptState::Dispatching, AttemptState::Unknown, AttemptState::Pending], true)
            || $proof->attemptRef !== $stored->attemptRef || $proof->operationRef !== $operation->operationRef
            || $stored->providerReference !== $certificate->providerReference || $stored->startedAt === null || $stored->requestDeadlineAt === null
            || $certificate->coverageFrom > $stored->startedAt || $certificate->coverageThrough < $stored->requestDeadlineAt
            || $certificate->coverageThrough > $now || $certificate->expiresAt <= $now || ExecutionCodec::encode($certificate->binding) !== ExecutionCodec::encode($operation->binding)) {
            $this->refuse('Certified absence does not cover this current attempt');
        }
        $merchant = $this->merchantRevision($scope, $operation->binding->merchantRevisionRef, $context);
        $cap = $this->capability($scope, $merchant->capabilitySnapshotRef, $context);
        $work = $this->providerWork($claim->workRef, $context);
        if ($work === null || $work->state !== 'leased' || $work->handlerVersion !== $claim->handlerContractVersion || $work->operationHint !== $operation->operationRef
            || $work->evidence->evidenceRef !== $claim->evidenceRef || $work->evidence->handlerContractVersion !== 'execution.transport.v1'
            || ExecutionCodec::encode($work->evidence->scope) !== ExecutionCodec::encode($scope)
            || $work->evidence->providerId !== $merchant->providerId || $work->evidence->merchantAccountRef !== $merchant->merchantAccountRef
            || $certificate->providerNamespace !== $cap->details->lookupNamespace || $certificate->qualificationEvidenceRef !== $cap->qualificationEvidenceRef) {
            $this->refuse('Verified absence is not the original qualified transport claim');
        }
        $transport = $work->evidence->sanitizedPayload;
        $expected = ['schema' => 'billium.execution.transport-observation.v1', 'attemptRef' => $attempt->attemptRef, 'operationRef' => $operation->operationRef,
            'providerReference' => $attempt->providerReference, 'lookupState' => 'certified_absent', 'providerEvidenceRef' => null,
            'absenceCertificate' => ExecutionCodec::value($certificate)];
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $transport) || ExecutionCodec::encode($transport[$key]) !== ExecutionCodec::encode($value)) {
                $this->refuse('Absence receipt differs from original certificate');
            }
        }
        $fence = $transport['leaseFence'] ?? null;
        if (! is_string($fence) || ! ctype_digit($fence) || (int) $fence > $attempt->leaseFence || ($transport['source'] ?? null) !== 'verified_absence'
            || ($transport['verifiedAbsence'] ?? null) !== true || $work->evidence->signatureVerdict !== 'verified_no_effect') {
            $this->refuse('Absence receipt lacks original verified lookup');
        }
        $lookup = $this->query('provider_evidence', $scope, $context)->where('intent_owner_namespace', $scope->intentOwnerNamespace)
            ->where('provider_id', $merchant->providerId)->where('merchant_account_ref', $merchant->merchantAccountRef)->where('handler_contract_version', 'execution.transport.v1')
            ->where('signature_verdict', 'local_transport')->whereJsonContains('sanitized_payload', $expected + ['source' => 'lookup', 'leaseFence' => $fence, 'verifiedAbsence' => false]);
        if (! $lookup->exists() || $this->hasGreaterActual($context, $operation->operationRef, 0)) {
            $this->refuse('Absence lacks complete original lookup or contradicts recorded operation effect');
        }
        if (! $this->finishProviderWork($context, $claim, 'processed', $certificate->certificateRef, null, null)) {
            $this->refuse('Verified absence processing lease changed');
        }
        $this->database($context)->table('billium_execution_certified_absence')->insert($this->callerScope($context) + ['attempt_ref' => $attempt->attemptRef,
            'evidence_ref' => $claim->evidenceRef, 'certificate_ref' => $certificate->certificateRef, 'certificate_digest' => ExecutionCodec::digest($certificate)]);
        $reservation = $this->reservationForOperation($context, $operation->operationRef);
        $capacity = $this->capacity($context, $operation->rootOperationRef);
        if ($reservation !== null) {
            if ($reservation->state !== ReservationState::Held || $releasedCapacity === null) {
                $this->refuse('Certified release requires the original held reservation');
            }
            $held = match ($reservation->kind) {
                EffectKind::Capture => 'heldCapture', EffectKind::VoidAuthorization => 'heldVoid', EffectKind::Refund => 'heldRefund',
                default => throw new LogicException('Invalid reservation kind'),
            };
            $expectedCapacity = get_object_vars($capacity);
            $expectedCapacity['version'] = $capacity->version + 1;
            $expectedCapacity[$held] = new Money($capacity->{$held}->minor - $reservation->requested->minor, $reservation->requested->currency);
            if (ExecutionCodec::encode($expectedCapacity) !== ExecutionCodec::encode($releasedCapacity)) {
                $this->refuse('Certified release differs from original reservation amount');
            }
            $this->settleReservation($context, $reservation->reservationRef, ReservationState::Released, new Money(0, $reservation->requested->currency), $claim->evidenceRef, $releasedCapacity);
            $capacity = $releasedCapacity;
        } elseif ($releasedCapacity !== null) {
            $this->refuse('Certified release has no original reservation');
        }
        $this->advanceAttempt($context, $attempt, AttemptState::Failed, $claim->evidenceRef, null);
        $decision = ['schema' => 'billium.execution.certified-no-effect.v1', 'operationRef' => $operation->operationRef, 'attemptRef' => $attempt->attemptRef,
            'certificateRef' => $certificate->certificateRef, 'bindingDigest' => ExecutionCodec::bindingDigest($operation->binding),
            'operationAmount' => new Money(0, $operation->requested->currency)->toWire(), 'aggregateDisposition' => 'no_provider_material'];
        $digest = ExecutionCodec::digest($decision);
        $revision = $operation->revision + 1;
        $updated = $this->callerQuery('operation', $context)->where('id', $operation->id)->where('revision', $operation->revision)
            ->update(['state' => 'failed', 'revision' => $revision, 'economic_digest' => $digest]);
        if ($updated !== 1) {
            $this->refuse('Original operation changed during certified absence');
        }
        $this->database($context)->table('billium_execution_operation_revision')->insert($this->callerScope($context) + ['operation_ref' => $operation->operationRef,
            'revision' => $revision, 'material_digest' => $digest, 'requested_minor' => $operation->requested->minor, 'currency' => $operation->requested->currency->code,
            'observed' => ExecutionCodec::encode($decision), 'last_consistent_applied' => ExecutionCodec::encode($capacity), 'financial_hold' => ExecutionCodec::encode($capacity->financialHold),
            'exposure' => '{}', 'evidence_refs' => ExecutionCodec::encode([$claim->evidenceRef]), 'receipt_ref' => $claim->workRef, 'occurred_at' => $now]);
    }

    private function hasGreaterActual(TransactionContext $context, string $operationRef, int $minor): bool
    {
        return $this->callerQuery('provider_fact', $context)->where('operation_ref', $operationRef)
            ->where(function (Builder $q) use ($minor): void {
                $q->whereRaw("(normalized_snapshot#>>'{operationAmount,minor}')::bigint > ?", [$minor])
                    ->orWhere(function (Builder $legacy): void {
                        $legacy->whereNull('normalized_snapshot->operationAmount')->where('normalized_snapshot->normalizedStatus', 'succeeded');
                    });
            })->exists();
    }

    private function requireIssuedProof(VerifiedAbsence $proof): void
    {
        if ($this->absenceProofs === null) {
            $this->refuse('Configured absence verifier unavailable');
        }
        $this->absenceProofs->assertIssued($proof);
    }

    public function assertCertifiedDecision(TransactionContext $context, OperationRecord $operation, VerifiedAbsence $proof): void
    {
        $this->financial($context);
        $this->requireIssuedProof($proof);
        $this->requireOperationLock($context, $operation->operationRef);
        $current = $this->admittedOperation($context, $operation->operationRef);
        $cert = $proof->certificate;
        $record = $this->callerQuery('certified_absence', $context)->where('certificate_ref', $cert->certificateRef)->where('attempt_ref', $proof->attemptRef)
            ->where('certificate_digest', ExecutionCodec::digest($cert))->first();
        $revision = $this->callerQuery('operation_revision', $context)->where('operation_ref', $operation->operationRef)->where('revision', $operation->revision)
            ->where('observed->schema', 'billium.execution.certified-no-effect.v1')->where('observed->certificateRef', $cert->certificateRef)
            ->where('material_digest', $operation->economicDigest)->where('observed->attemptRef', $proof->attemptRef);
        if ($record === null || $operation->state !== 'failed' || $proof->operationRef !== $operation->operationRef
            || ExecutionCodec::encode($current) !== ExecutionCodec::encode($operation) || ! $revision->whereJsonContains('evidence_refs', self::text($record->evidence_ref))->exists()) {
            $this->refuse('Publication requires the original persisted certified absence revision');
        }
    }

    public function assertMaterialRevision(TransactionContext $context, MaterialChange $change): void
    {
        $this->financial($context);
        $op = $change->after;
        $this->requireOperationLock($context, $op->operationRef);
        $current = $this->admittedOperation($context, $op->operationRef);
        $row = $this->callerQuery('operation_revision', $context)->where('operation_ref', $op->operationRef)->where('revision', $op->revision)->first();
        if ($row === null || $change->observed->operationAmount === null || $change->before->operationRef !== $op->operationRef || $change->before->revision + 1 !== $op->revision
            || ExecutionCodec::encode($current) !== ExecutionCodec::encode($op) || $row->material_digest !== $op->economicDigest
            || ExecutionCodec::encode(ExecutionCodec::map(self::text($row->observed))) !== ExecutionCodec::encode($change->observed)
            || ExecutionCodec::encode(ExecutionCodec::map(self::text($row->last_consistent_applied))) !== ExecutionCodec::encode($change->capacity)
            || ExecutionCodec::encode(ExecutionCodec::map(self::text($row->exposure))) !== $this->exposure($change)
            || ExecutionCodec::encode($this->capacity($context, $op->rootOperationRef)) !== ExecutionCodec::encode($change->capacity)) {
            $this->refuse('Publication requires the original persisted material revision');
        }
    }

    public function appendRevision(TransactionContext $transactionContext, MaterialChange $materialChange, string $receiptRef): void
    {
        $a = $materialChange->after;
        $b = $materialChange->before;
        $this->financial($transactionContext);
        $this->requireOperationLock($transactionContext, $b->operationRef);
        $observed = $materialChange->observed;
        $subject = $this->admittedOperation($transactionContext, $observed->operationRef);
        $accepted = isset($observed->evidenceRefs[0]) ? $this->factForEvidence($transactionContext, $observed->evidenceRefs[0], $subject->operationRef) : null;
        if (($materialChange->discrepancyRef !== null && ! $materialChange->capacity->financialHold)
            || $accepted === null || ExecutionCodec::encode($accepted->snapshot) !== ExecutionCodec::encode($observed)
            || $subject->rootOperationRef !== $b->rootOperationRef || ($b->operationRef !== $subject->operationRef && $b->operationRef !== $subject->rootOperationRef)
            || $materialChange->capacity->rootOperationRef !== $b->rootOperationRef) {
            $this->refuse('Material revision requires accepted original operation evidence');
        }
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
        } elseif (ExecutionCodec::encode($materialChange->capacity) !== ExecutionCodec::encode($currentCapacity)) {
            $this->refuse('Material revision differs from unchanged capacity version');
        }
        $this->database($transactionContext)->table('billium_execution_operation_revision')->insert($this->callerScope($transactionContext) + ['operation_ref' => $a->operationRef, 'revision' => $a->revision, 'material_digest' => $a->economicDigest, 'requested_minor' => $a->requested->minor, 'currency' => $a->requested->currency->code, 'observed' => ExecutionCodec::encode($materialChange->observed), 'last_consistent_applied' => ExecutionCodec::encode($materialChange->capacity), 'financial_hold' => ExecutionCodec::encode($materialChange->capacity->financialHold), 'exposure' => $this->exposure($materialChange), 'evidence_refs' => ExecutionCodec::encode($materialChange->observed->evidenceRefs), 'receipt_ref' => $receiptRef, 'occurred_at' => $materialChange->observed->observedAt]);
    }

    public function appendDiscrepancy(TransactionContext $transactionContext, string $ref, MaterialChange $materialChange): void
    {
        $this->financial($transactionContext);
        $prior = $this->admittedOperation($transactionContext, $materialChange->before->operationRef);
        $this->requireLock($transactionContext, $prior->rootOperationRef);
        if ($prior->rootOperationRef !== $materialChange->before->rootOperationRef) {
            $this->refuse('Discrepancy differs from historical root');
        }
        $accepted = $this->factForEvidence($transactionContext, $materialChange->observed->evidenceRefs[0] ?? '', $prior->operationRef);
        if (! $materialChange->capacity->financialHold || $materialChange->discrepancyRef !== $ref || $accepted === null || ExecutionCodec::encode($accepted->snapshot) !== ExecutionCodec::encode($materialChange->observed)) {
            $this->refuse('Accepted material discrepancy requires the original qualified fact');
        }
        $this->database($transactionContext)->table('billium_execution_discrepancy')->insertOrIgnore($this->callerScope($transactionContext) + ['discrepancy_ref' => $ref, 'root_operation_ref' => $materialChange->before->rootOperationRef, 'operation_ref' => $materialChange->before->operationRef, 'kind' => 'provider_material_conflict', 'evidence_refs' => ExecutionCodec::encode($materialChange->observed->evidenceRefs), 'observed' => ExecutionCodec::encode($materialChange->observed), 'last_consistent_applied' => ExecutionCodec::encode($materialChange->capacity), 'exposure' => $this->exposure($materialChange)]);
    }

    public function recordQuarantineHold(TransactionContext $context, ProviderWorkClaim $claim, MaterialSnapshot $conflict, string $discrepancyRef, string $reason): ?OperationRecord
    {
        $this->financial($context);
        $delivery = $this->verifiedReduction?->verifiedIn($context, $claim, $conflict) ?? throw new LogicException('Configured provider verification is required');
        $subject = $this->admittedOperation($context, $conflict->operationRef);
        $this->requireOperationLock($context, $subject->operationRef);
        $root = $this->admittedOperation($context, $subject->rootOperationRef);
        $work = $this->providerWork($claim->workRef, $context);
        $attempt = $this->attemptForOperation($subject->binding->scope, $subject->operationRef, $context);
        if ($work === null || $work->state !== 'leased' || $work->operationHint !== $subject->operationRef || $work->evidence->evidenceRef !== $claim->evidenceRef
            || ExecutionCodec::encode($work->evidence) !== ExecutionCodec::encode($delivery->evidence) || $conflict->evidenceRefs !== [$claim->evidenceRef]
            || $conflict->bindingDigest !== ExecutionCodec::bindingDigest($subject->binding) || $conflict->merchantAccountRef !== $subject->binding->merchantAccountRef
            || $attempt === null || $conflict->providerReference !== $attempt->providerReference || $conflict->kind !== $subject->kind) {
            $this->refuse('Financial hold requires authenticated work for the original locked operation');
        }
        $current = $this->capacity($context, $root->operationRef);
        $held = new CapacitySnapshot($root->operationRef, $current->version + 1, $current->authorizedLimit, $current->confirmedCapture, $current->confirmedRefund,
            $current->releasedAuthorization, $current->heldCapture, $current->heldVoid, $current->heldRefund, true);
        $exposure = ExecutionCodec::encode(['discrepancyRef' => $discrepancyRef, 'financialHold' => true, 'reason' => $reason]);
        $evidence = ExecutionCodec::encode($conflict->evidenceRefs);
        $db = $this->database($context);
        $scope = $this->callerScope($context);
        $db->table('billium_execution_discrepancy')->insertOrIgnore($scope + ['discrepancy_ref' => $discrepancyRef, 'root_operation_ref' => $root->operationRef,
            'operation_ref' => $subject->operationRef, 'kind' => 'provider_material_conflict', 'evidence_refs' => $evidence, 'observed' => ExecutionCodec::encode($conflict),
            'last_consistent_applied' => ExecutionCodec::encode($current), 'exposure' => $exposure]);
        // Existing discrepancy rows may predate local hold publications. Deduplicate against the actual financial revision.
        if ($this->callerQuery('operation_revision', $context)->where('operation_ref', $root->operationRef)
            ->where('observed->discrepancyRef', $discrepancyRef)->where('observed->schema', 'billium.execution.quarantine-hold.v1')->exists()) {
            return null;
        }
        $decision = ['schema' => 'billium.execution.quarantine-hold.v1', 'discrepancyRef' => $discrepancyRef, 'operationRef' => $subject->operationRef,
            'reason' => $reason, 'priorEconomicDigest' => $root->economicDigest];
        $digest = ExecutionCodec::digest($decision);
        $revision = $root->revision + 1;
        if ($this->callerQuery('operation', $context)->where('id', $root->id)->where('revision', $root->revision)->where('economic_digest', $root->economicDigest)
            ->update(['revision' => $revision, 'economic_digest' => $digest]) !== 1) {
            $this->refuse('Root changed before recording the financial hold');
        }
        $this->writeCapacity($context, $held, $current->version);
        $db->table('billium_execution_operation_revision')->insert($scope + ['operation_ref' => $root->operationRef, 'revision' => $revision,
            'material_digest' => $digest, 'requested_minor' => $root->requested->minor, 'currency' => $root->requested->currency->code,
            'observed' => ExecutionCodec::encode($decision), 'last_consistent_applied' => ExecutionCodec::encode($held), 'financial_hold' => 'true',
            'exposure' => $exposure, 'evidence_refs' => $evidence, 'receipt_ref' => $claim->workRef, 'occurred_at' => new DateTimeImmutable]);

        return $this->admittedOperation($context, $root->operationRef);
    }

    private function exposure(MaterialChange $change): string
    {
        return ExecutionCodec::encode(['discrepancyRef' => $change->discrepancyRef, 'financialHold' => $change->capacity->financialHold || $change->discrepancyRef !== null,
            'overCaptured' => $change->overCaptured, 'overRefunded' => $change->overRefunded, 'reason' => $change->reason]);
    }

    public function ensureObservationPartition(TransactionContext $transactionContext, ExecutionScope $executionScope, string $sourceAuthority, string $lineage, string $rootRef): ObservationPartition
    {
        $this->financial($transactionContext);
        $this->assertScope($executionScope, $transactionContext, $sourceAuthority);
        $root = $this->admittedOperation($transactionContext, $rootRef);
        if ($root->operationRef !== $root->rootOperationRef) {
            $this->refuse('Partition requires original root');
        }

        return $this->persistObservationPartition($transactionContext, $executionScope, $sourceAuthority, $lineage, $rootRef);
    }

    public function prepareObservationPartition(TransactionContext $context, FrozenBinding $binding, string $lineage): ObservationPartition
    {
        $this->financial($context);
        $this->assertScope($binding->scope, $context, $binding->executionProfile);
        if ($binding->origin === null || $lineage === '' || strlen($lineage) > 200) {
            $this->refuse('Initial partition requires an authenticated original basis and complete lineage');
        }

        return $this->persistObservationPartition($context, $binding->scope, $binding->executionProfile, $lineage, $binding->rootOperationRef);
    }

    private function persistObservationPartition(TransactionContext $transactionContext, ExecutionScope $executionScope, string $sourceAuthority, string $lineage, string $rootRef): ObservationPartition
    {
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
        $op = $this->operation($observationEmission->scope, $observationEmission->operationRef, $transactionContext) ?? throw new LogicException('Emission operation absent');
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

    public function merchantRevision(ExecutionScope $executionScope, string $revisionRef, ?TransactionContext $context = null): MerchantRevision
    {
        $r = $this->query('merchant_revision', $executionScope, $context)->where('revision_ref', $revisionRef)->first() ?? throw new LogicException('Historical merchant revision unavailable');

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
            $this->merchantRevision($m->scope, $predecessor, $context);
        }
        $inserted = $this->database($context)->table('billium_execution_capability_snapshot')->insertOrIgnore($this->scope($m->scope) + ['snapshot_ref' => $c->snapshotRef, 'merchant_account_ref' => $c->merchantAccountRef, 'driver_id' => $c->driverId, 'driver_contract_version' => $c->driverContractVersion, 'capability_version' => $c->capabilityVersion, 'evidence_mode' => $c->evidenceMode->value, 'body' => ExecutionCodec::encode($c), 'digest' => $c->digest, 'qualification_mode' => $c->qualificationMode->value, 'qualification_evidence_ref' => $c->qualificationEvidenceRef, 'created_at' => $m->createdAt, 'supported_effects' => $this->pgArray(array_map(static fn (EffectKind $e): string => $e->value, $c->supportedEffects)), 'currencies' => $this->pgArray($c->currencies), 'capture_mode' => $c->details->captureMode->value, 'partial_refund' => $c->partialRefund, 'multiple_refund' => $c->details->multipleRefund, 'lookup_by_provider_id' => $c->details->lookupByProviderId, 'lookup_by_reference' => $c->lookupByReference, 'inventory_complete' => $c->inventoryComplete, 'absence_certification' => $c->absenceCertification, 'idempotency_retention_seconds' => $c->details->idempotencyRetentionSeconds, 'lookup_retention_seconds' => $c->details->lookupRetentionSeconds]);
        if ($inserted === 0 && ExecutionCodec::encode($this->capability($m->scope, $c->snapshotRef, $context)) !== ExecutionCodec::encode($c)) {
            $this->refuse('Merchant rotation cannot change the original capability snapshot');
        }
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

        return new AuthorizationRecord(self::text($r->authorization_ref), $rootOperationRef, new Money(self::integer($r->authorized_minor), $currency), new Money(self::integer($r->released_minor), $currency), self::date($r->provider_expires_at), self::nullableText($r->expiry_evidence_ref), self::text($r->state), self::integer($r->revision), self::nullableText($r->release_evidence_ref));
    }

    public function applyAuthorization(TransactionContext $transactionContext, AuthorizationRecord $authorizationRecord, int $expectedRevision): void
    {
        $current = $this->authorization($transactionContext, $authorizationRecord->rootOperationRef);
        if ($authorizationRecord->revision !== $expectedRevision + 1 || ($current === null ? $expectedRevision !== 0 : $current->revision !== $expectedRevision)) {
            $this->refuse('Authorization revision conflict');
        }
        $row = ['authorization_ref' => $authorizationRecord->authorizationRef, 'root_operation_ref' => $authorizationRecord->rootOperationRef, 'authorized_minor' => $authorizationRecord->authorized->minor, 'released_minor' => $authorizationRecord->released->minor, 'currency' => $authorizationRecord->authorized->currency->code, 'provider_expires_at' => $authorizationRecord->providerExpiresAt?->format('Y-m-d H:i:s.uP'), 'expiry_evidence_ref' => $authorizationRecord->expiryEvidenceRef, 'release_evidence_ref' => $authorizationRecord->releaseEvidenceRef, 'state' => $authorizationRecord->state, 'revision' => $authorizationRecord->revision];
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
        $root = $this->operation($recoveryAction->scope, $recoveryAction->rootOperationRef, $transactionContext) ?? throw new LogicException('Repair root unavailable');
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
        } else {
            $id = (clone $q)->value('id');
            if ($id === null || $transactionContext->lock(new LockRequest(LockRank::IntentNamespace, 'billium_execution_namespace', [self::integer($id)])) !== [self::integer($id)]
                || $q->where('generation', $expectedGeneration)->update(['state' => $namespaceState->value, 'completeness_receipt_ref' => $authorityEvidenceRef, 'generation' => $expectedGeneration + 1]) !== 1) {
                $this->refuse('Namespace generation conflict');
            }
        }

        return $this->namespace($executionScope, $transactionContext);
    }

    public function finishAttempt(TransactionContext $transactionContext, string $attemptRef, int $expectedFence, AttemptState $attemptState, string $evidenceRef): bool
    {
        $this->financial($transactionContext);
        $evidence = $this->callerQuery('provider_evidence', $transactionContext)->where('evidence_ref', $evidenceRef)->first() ?? throw new LogicException('Attempt finish evidence absent');
        $q = $this->callerQuery('attempt', $transactionContext)->where('attempt_ref', $attemptRef);
        $row = (clone $q)->first() ?? throw new LogicException('Attempt absent');
        $this->requireOperationLock($transactionContext, self::text($row->operation_ref));
        if (self::integer($row->lease_fence) !== $expectedFence) {
            return false;
        }
        if ($row->merchant_account_ref !== $evidence->merchant_account_ref || $row->provider_id !== $evidence->provider_id
            || $evidence->intent_owner_namespace !== $this->selectedScope($transactionContext)->intentOwnerNamespace
            || ! in_array($attemptState, [AttemptState::Pending, AttemptState::Unknown], true)
            || $this->transportData($evidence, $row, $expectedFence) === null) {
            $this->refuse('Attempt completion requires the current scoped transport observation');
        }

        return $q->where('lease_fence', $expectedFence)->whereRaw('lease_expires_at > clock_timestamp()')->whereIn('state', ['dispatching', 'pending', 'unknown'])->update(['state' => $attemptState->value, 'finish_evidence_ref' => $evidenceRef]) === 1;
    }

    public function capability(ExecutionScope $scope, string $ref, ?TransactionContext $context = null): CapabilitySnapshot
    {
        $r = $this->query('capability_snapshot', $scope, $context)->where('snapshot_ref', $ref)->first() ?? throw new LogicException('Capability snapshot absent');

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
        if ($context !== null) {
            $this->runner->assertCurrent($context, 'execution');
        }
        $caller = $context?->caller() ?? $this->callers->current();
        if ([$scope->installationRef, $scope->sellerRef, $scope->environment] !== [$caller->installationRef, $caller->sellerRef, $caller->environment] || ! in_array($scope->intentOwnerNamespace, $caller->intentOwnerNamespaces, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Execution scope is not authenticated');
        }
        $declared = $context?->spec()->financialScope;
        if ($declared !== null && ($declared->installationRef !== $scope->installationRef || $declared->sellerRef !== $scope->sellerRef
            || $declared->environment !== $scope->environment || $declared->intentOwnerNamespace !== $scope->intentOwnerNamespace)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Execution differs from the declared root scope');
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
        return new AttemptRecord(self::integer($r->id), self::text($r->attempt_ref), self::text($r->operation_ref), self::text($r->provider_reference), AttemptState::from(self::text($r->state)), self::integer($r->lease_fence), self::nullableText($r->lease_owner), self::date($r->lease_expires_at), $r->session_epoch === null ? null : self::integer($r->session_epoch), self::nullableText($r->owner_session_ref), self::nullableText($r->dispatch_ticket_ref), self::text($r->request_digest), self::date($r->started_at), self::date($r->request_deadline_at), self::nullableText($r->collection_dispatch_permit_ref));
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
        return ['run_ref' => $r->runRef, 'merchant_account_ref' => $r->merchantAccountRef, 'recovery_horizon' => $r->recoveryHorizon->format('Y-m-d H:i:s.uP'), 'inventory_cursor' => $r->inventoryCursor, 'completeness_watermark' => $r->completenessWatermark, 'inventory_digest' => $r->inventoryDigest, 'manifest_digest' => $r->manifestDigest, 'state' => $r->state, 'unresolved' => ExecutionCodec::encode($r->unresolved), 'started_at' => $r->startedAt->format('Y-m-d H:i:s.uP'), 'completed_at' => $r->completedAt?->format('Y-m-d H:i:s.uP')];
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
