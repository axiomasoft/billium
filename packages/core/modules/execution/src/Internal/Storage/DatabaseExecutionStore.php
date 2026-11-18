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
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\NamespaceRecord;
use Billium\Execution\Data\ObservationEmission;
use Billium\Execution\Data\ObservationPartition;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ReconciliationRun;
use Billium\Execution\Data\RecoveryAction;
use Billium\Execution\Data\Reservation;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Enums\ReservationState;
use Billium\Execution\Enums\WorkState;
use Billium\Execution\Internal\Authority\AbsenceProofRegistry;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Execution\Internal\Capacity\CapacityAmounts;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Reducer\VerifiedReduction;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final class DatabaseExecutionStore implements ExecutionStore
{
    use ExecutionEvidencePersistence;

    private readonly ExecutionTables $tables;

    private readonly MerchantRevisionStorage $merchants;

    /** @param (Closure():?OriginalIdentityArchive)|null $identityArchive */
    public function __construct(DatabaseManager $databases, OperationRunner $runner, CallerContext $callers, BilliumOptions $options, private readonly FinancialSessionAdmission $sessionAdmission, private readonly ?AbsenceProofRegistry $absenceProofs = null, private readonly ?Closure $identityArchive = null, private readonly ?VerifiedReduction $verifiedReduction = null)
    {
        $this->tables = new ExecutionTables($databases, $runner, $callers, $options);
        $this->merchants = new MerchantRevisionStorage($this->tables);
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

    public function namespace(ExecutionScope $executionScope, ?TransactionContext $context = null): NamespaceRecord
    {
        $row = $this->tables->query('namespace', $executionScope, $context)->where('intent_owner_namespace', $executionScope->intentOwnerNamespace)->first();

        return ExecutionRecords::namespace($executionScope, $row);
    }

    public function lockNamespace(TransactionContext $context, ExecutionScope $scope): NamespaceRecord
    {
        $this->tables->financial($context);
        $id = $this->tables->query('namespace', $scope, $context)->where('intent_owner_namespace', $scope->intentOwnerNamespace)->value('id');
        if ($id === null) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Business namespace lacks durable history');
        }
        $request = new LockRequest(LockRank::IntentNamespace, 'billium_execution_namespace', [ExecutionRows::integer($id)], shared: true);
        if ($context->lock($request) !== $request->keys) {
            throw new LockPlanChanged('Business namespace changed during admission');
        }

        return $this->namespace($scope, $context);
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
            $this->insertHeldReservation($ctx, $reservation, $this->capacity($ctx, $binding->rootOperationRef));
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
            'binding' => ExecutionCodec::encode($b), 'operation_ref' => $a->operationRef, 'created_at' => $a->createdAt->format(ExecutionRows::TIMESTAMP)];
        $identity = $shared + ['business_intent_ref' => $a->businessIntentRef, 'operation_kind' => $root ? 'charge' : $a->kind->value,
            'semantic_digest' => $a->semanticDigest, 'canonicalization_version' => $b->canonicalizationVersion];
        $terms = ['root_operation_ref' => $b->rootOperationRef, 'requested_minor' => $a->requested->minor,
            'currency' => $a->requested->currency->code, 'currency_exponent' => $a->requested->currency->scale];
        $table = $root ? 'business_binding' : 'child_identity';
        if (! $root) {
            $this->tables->requireLock($ctx, $b->rootOperationRef);
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
            $db->table('billium_execution_capacity')->insert(ExecutionTables::scope($scope) + ['root_operation_ref' => $b->rootOperationRef, 'currency' => $a->requested->currency->code]);
        }
        $db->table('billium_execution_attempt')->insert(ExecutionTables::scope($scope) + ['attempt_ref' => $a->attemptRef, 'operation_ref' => $a->operationRef,
            'provider_reference' => $a->providerReference, 'provider_id' => $a->providerId, 'merchant_account_ref' => $b->merchantAccountRef,
            'state' => 'unknown', 'lease_fence' => $a->originalFence + 1, 'session_epoch' => $a->originalEpoch, 'owner_session_ref' => $a->originalOwner,
            'request_digest' => $a->requestDigest, 'started_at' => $a->startedAt->format(ExecutionRows::TIMESTAMP), 'request_deadline_at' => $a->deadline->format(ExecutionRows::TIMESTAMP),
            'dispatch_ticket_ref' => $a->firstTicketRef, 'collection_dispatch_permit_ref' => $a->collectionPermitRef]);
    }

    public function insertBinding(TransactionContext $transactionContext, FrozenBinding $frozenBinding): ?OperationRecord
    {
        $this->tables->financial($transactionContext);
        $this->tables->assertScope($frozenBinding->scope, $transactionContext, $frozenBinding->executionProfile);
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
        $row = ExecutionTables::scope($frozenBinding->scope) + $identity + ['semantic_digest' => $frozenBinding->semanticDigest, 'canonicalization_version' => $frozenBinding->canonicalizationVersion, 'operation_ref' => $frozenBinding->rootOperationRef, 'binding' => ExecutionCodec::encode($frozenBinding), 'created_at' => new DateTimeImmutable];
        $db = $this->tables->database($transactionContext);
        $inserted = $db->table('billium_execution_business_binding')->insertOrIgnore($row);
        if ($inserted === 1) {
            return null;
        }
        $existing = $db->table('billium_execution_business_binding')->where('business_identity_digest', ExecutionCodec::businessIdentityDigest($frozenBinding, 'charge'))->where($identity)->first();
        if ($existing === null || $existing->tombstone_reason !== null || ExecutionRows::text($existing->installation_ref) !== $frozenBinding->scope->installationRef
            || ExecutionRows::text($existing->seller_ref) !== $frozenBinding->scope->sellerRef
            || ExecutionCodec::encode(ExecutionCodec::map(ExecutionRows::text($existing->binding))) !== ExecutionCodec::encode($frozenBinding)) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'Business identity conflicts with immutable binding');
        }

        return $this->operation($frozenBinding->scope, ExecutionRows::text($existing->operation_ref), $transactionContext) ?? throw new LogicException('Existing business binding has no operation');
    }

    public function claimChildBinding(TransactionContext $context, AdmissionPlan $plan, string $businessIntentRef, ?string $reasonCode = null, ?string $category = null): ?OperationRecord
    {
        $this->tables->financial($context);
        $binding = $plan->binding;
        $scope = $binding->scope;
        $this->tables->assertScope($scope, $context, $binding->executionProfile);
        $this->tables->requireLock($context, $binding->rootOperationRef);
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
        $fields = ExecutionTables::scope($scope) + [
            'intent_owner_namespace' => $scope->intentOwnerNamespace, 'source_context' => $binding->sourceContext,
            'business_intent_ref' => $businessIntentRef, 'operation_kind' => $plan->kind->value,
            'operation_ref' => $plan->operationRef, 'root_operation_ref' => $binding->rootOperationRef,
            'semantic_digest' => $plan->semanticDigest, 'canonicalization_version' => $plan->canonicalizationVersion,
            'binding' => ExecutionCodec::encode($binding), 'requested_minor' => $plan->amount->minor,
            'currency' => $plan->amount->currency->code, 'currency_exponent' => $plan->amount->currency->scale,
            'reason_code' => $reasonCode, 'category' => $category,
            'receipt_ref' => PaymentIdentity::reference($scope, $plan->operationRef, 'admission'),
        ];
        $table = $this->tables->database($context)->table('billium_execution_child_identity');
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
        $this->tables->assertScope($scope, $context);
        if (! in_array($kind, [EffectKind::Capture, EffectKind::VoidAuthorization, EffectKind::Refund], true) || $sourceContext === '' || $businessIntentRef === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'A child kind and complete business identity are required');
        }
        $caller = $this->tables->caller($context);
        $source = $context?->spec()->financialScope?->sourceAuthority;
        $row = $this->tables->query('child_identity', $scope, $context)->where('intent_owner_namespace', $scope->intentOwnerNamespace)
            ->whereRaw('business_identity_digest = public.billium_execution_business_identity_digest(?,?,?,?,?)', [
                $scope->intentOwnerNamespace, $scope->environment, $sourceContext, $businessIntentRef, $kind->value,
            ])->where('source_context', $sourceContext)->where('business_intent_ref', $businessIntentRef)->where('operation_kind', $kind->value)
            ->whereIn('binding->executionProfile', $source === null ? $caller->sourceAuthorities : [$source])->first();

        return $row === null ? null : $this->operation($scope, ExecutionRows::text($row->operation_ref), $context);
    }

    /** @param array<string,int|string|null> $fields */
    private function retainedChild(TransactionContext $context, AdmissionPlan $plan, object $row, array $fields): OperationRecord
    {
        $actual = get_object_vars($row);
        foreach ($fields as $column => $value) {
            $stored = $actual[$column] ?? null;
            if ($column === 'binding') {
                $stored = ExecutionCodec::encode(ExecutionCodec::map(ExecutionRows::text($stored)));
            } elseif ($column === 'requested_minor' || $column === 'currency_exponent') {
                $stored = ExecutionRows::integer($stored);
            }
            if ($stored !== $value) {
                throw new ProblemException(ProblemCode::IdempotencyConflict, 'Child business identity conflicts with retained original terms');
            }
        }

        return $this->operation($plan->scope, ExecutionRows::text($actual['operation_ref'] ?? null), $context)
            ?? throw new LogicException('A committed child identity must retain its original operation');
    }

    public function insertOperation(TransactionContext $transactionContext, AdmissionPlan $admissionPlan): OperationRecord
    {
        $this->tables->financial($transactionContext);
        $b = $admissionPlan->binding;
        $this->tables->assertScope($admissionPlan->scope, $transactionContext, $b->executionProfile);
        if (ExecutionCodec::encode($admissionPlan->scope) !== ExecutionCodec::encode($b->scope) || $admissionPlan->amount->minor <= 0 || Currency::of($admissionPlan->amount->currency->code)->scale !== $admissionPlan->amount->currency->scale) {
            ExecutionTables::refuse('Invalid binding, amount or currency precision');
        }
        $merchant = $this->merchantRevision($b->scope, $b->merchantRevisionRef, $transactionContext);
        if ([$merchant->merchantAccountRef, $merchant->driverId, $merchant->driverContractVersion, $merchant->capabilityDigest] !== [$b->merchantAccountRef, $b->driverId, $b->driverContractVersion, $b->capabilityDigest]) {
            ExecutionTables::refuse('Merchant identity substitution');
        }
        $cap = $this->capability($b->scope, $merchant->capabilitySnapshotRef, $transactionContext);
        $cap->requireEffect($admissionPlan->kind, $admissionPlan->amount->currency->code, $b->scope->environment === 'production');
        if (! in_array($admissionPlan->amount->currency->code, $merchant->currencyCodes, true)) {
            ExecutionTables::refuse('Merchant currency unsupported');
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
            $enabled = $this->tables->query('merchant_lifecycle_event', $b->scope, $transactionContext)->where('revision_ref', $merchant->revisionRef)->where('purpose', 'new')->where('effective_at', '<=', $now)->orderByDesc('effective_at')->orderByDesc('id')->value('action');
            if ($merchant->policy->validFrom > $now || ($merchant->policy->validUntil !== null && $merchant->policy->validUntil <= $now) || $enabled !== 'enable_new') {
                ExecutionTables::refuse('New merchant binding is not explicitly enabled/effective');
            }
            $identity = $this->tables->query('business_binding', $b->scope, $transactionContext)->where('operation_ref', $admissionPlan->operationRef)->where('source_context', $b->sourceContext)->where('business_intent_ref', $b->businessIntentRef)->where('intent_owner_namespace', $b->scope->intentOwnerNamespace)->where('semantic_digest', $admissionPlan->semanticDigest)->whereNull('tombstone_reason')->first();
            if ($identity === null || $admissionPlan->canonicalizationVersion !== $b->canonicalizationVersion || ExecutionCodec::encode(ExecutionCodec::map(ExecutionRows::text($identity->binding))) !== ExecutionCodec::encode($b)) {
                ExecutionTables::refuse('Root requires durable business binding');
            }
        } else {
            $root = $this->operation($b->scope, $b->rootOperationRef, $transactionContext) ?? throw new LogicException('Child root missing');
            $this->tables->requireLock($transactionContext, $root->rootOperationRef);
            if (ExecutionCodec::encode($root->binding) !== ExecutionCodec::encode($b) || ExecutionCodec::encode($root->requested->currency) !== ExecutionCodec::encode($admissionPlan->amount->currency)) {
                ExecutionTables::refuse('Child binding differs from historical root');
            }
        }
        $now = new DateTimeImmutable;
        $row = ExecutionTables::scope($b->scope) + ['operation_ref' => $admissionPlan->operationRef, 'root_operation_ref' => $b->rootOperationRef, 'kind' => $admissionPlan->kind->value, 'binding' => ExecutionCodec::encode($b), 'binding_digest' => ExecutionCodec::bindingDigest($b), 'source_context' => $b->sourceContext, 'business_intent_ref' => $b->businessIntentRef, 'intent_owner_namespace' => $b->scope->intentOwnerNamespace, 'merchant_revision_ref' => $b->merchantRevisionRef, 'merchant_account_ref' => $b->merchantAccountRef, 'driver_id' => $b->driverId, 'driver_contract_version' => $b->driverContractVersion, 'capability_digest' => $b->capabilityDigest, 'requested_minor' => $admissionPlan->amount->minor, 'currency' => $admissionPlan->amount->currency->code, 'currency_exponent' => $admissionPlan->amount->currency->scale, 'state' => 'admitted', 'revision' => 0, 'economic_digest' => $admissionPlan->semanticDigest, 'collection_authorization_ref' => $b->collectionAuthorizationRef, 'created_at' => $now];
        $id = $this->tables->database($transactionContext)->table('billium_execution_operation')->insertGetId($row);
        if ($admissionPlan->operationRef === $b->rootOperationRef) {
            $this->tables->database($transactionContext)->table('billium_execution_capacity')->insert(ExecutionTables::scope($b->scope) + ['root_operation_ref' => $b->rootOperationRef, 'currency' => $admissionPlan->amount->currency->code]);
        }

        return ExecutionRecords::operation($this->tables->query('operation', $b->scope, $transactionContext)->where('id', $id)->first() ?? throw new LogicException('Inserted operation is not visible on current root connection'));
    }

    public function operation(ExecutionScope $executionScope, string $string, ?TransactionContext $context = null): ?OperationRecord
    {
        return $this->tables->operation($executionScope, $string, $context);
    }

    public function attempt(ExecutionScope $executionScope, string $attemptRef, ?TransactionContext $context = null): ?AttemptRecord
    {
        $r = $this->tables->query('attempt', $executionScope, $context)->where('attempt_ref', $attemptRef)->first();

        return $r === null ? null : ExecutionRecords::attempt($r);
    }

    public function insertAttempt(TransactionContext $transactionContext, OperationRecord $operationRecord, string $attemptRef, string $providerReference): AttemptRecord
    {
        $this->tables->financial($transactionContext);
        $s = $operationRecord->binding->scope;
        $this->tables->assertScope($s, $transactionContext, $operationRecord->binding->executionProfile);
        $row = $this->operation($s, $operationRecord->operationRef, $transactionContext);
        if (ExecutionCodec::encode($row) !== ExecutionCodec::encode($operationRecord)) {
            ExecutionTables::refuse('Operation changed before attempt');
        }
        $merchant = $this->merchantRevision($s, $operationRecord->binding->merchantRevisionRef, $transactionContext);
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
        $until = $leaseUntil->format(ExecutionRows::TIMESTAMP);
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

    public function recordAdmissionConflict(TransactionContext $context, AdmissionConflict $conflict): string
    {
        $db = $this->tables->database($context);
        $scope = $conflict->scope;
        $this->tables->assertScope($scope, $context);
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
        $material = ExecutionTables::scope($scope) + ['intent_owner_namespace' => $scope->intentOwnerNamespace, 'source_authority' => $selected->sourceAuthority, 'actor_ref' => $context->caller()->actorRef,
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
        $this->tables->financial($context);
        $current = $this->tables->admittedOperation($context, $operation->operationRef);
        $this->tables->requireLock($context, $current->rootOperationRef);
        if ($receiptRef === '' || ExecutionCodec::encode($current) !== ExecutionCodec::encode($operation) || $current->rootOperationRef !== $current->operationRef
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
        $digest = 'sha256:'.hash('sha256', (new Codec)->canonicalize($material));
        $this->tables->callerQuery('operation', $context)->where('id', $current->id)->update(['state' => 'cancelled', 'revision' => $revision, 'economic_digest' => $digest]);
        $capacity = $this->capacity($context, $current->rootOperationRef);
        $this->tables->database($context)->table('billium_execution_operation_revision')->insert($this->tables->callerScope($context) + [
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
        if ($work->update(['state' => $state->value, 'due_at' => $dueAt?->format(ExecutionRows::TIMESTAMP), 'lease_owner' => null,
            'lease_expires_at' => null, 'last_error_code' => $errorCode, 'last_error_at' => $errorCode === null ? null : new DateTimeImmutable]) !== 1) {
            return false;
        }
        $q->update(['lease_owner' => null, 'lease_expires_at' => null, 'dispatch_ticket_ref' => null]);

        return true;
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

    public function startDispatch(TransactionContext $transactionContext, DispatchDecision $dispatchDecision): bool
    {
        $this->tables->financial($transactionContext);
        $this->tables->requireOperationLock($transactionContext, $dispatchDecision->operationRef);
        $operation = $this->tables->admittedOperation($transactionContext, $dispatchDecision->operationRef);
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

        $deadline = $dispatchDecision->requestDeadlineAt->format(ExecutionRows::TIMESTAMP);

        return $this->tables->callerQuery('attempt', $transactionContext)->where('attempt_ref', $dispatchDecision->attemptRef)->where('operation_ref', $dispatchDecision->operationRef)
            ->where('state', 'admitted')->where('request_digest', $dispatchDecision->immutableRequestDigest)->where('lease_fence', $dispatchDecision->leaseFence)
            ->whereNotNull('lease_owner')->where('lease_expires_at', '>=', $deadline)->whereRaw('?::timestamptz > clock_timestamp()', [$deadline])
            ->update(['state' => 'dispatching', 'session_epoch' => $transactionContext->sessionEpoch(), 'owner_session_ref' => $token->ownerSessionRef,
                'dispatch_ticket_ref' => $dispatchDecision->ticketRef, 'collection_dispatch_permit_ref' => $dispatchDecision->collectionDispatchPermitRef,
                'started_at' => $this->tables->database($transactionContext)->raw('clock_timestamp()'), 'request_deadline_at' => $deadline]) === 1;
    }

    public function capacity(TransactionContext $transactionContext, string $rootRef): CapacitySnapshot
    {
        $this->tables->financial($transactionContext);
        $this->tables->requireLock($transactionContext, $rootRef);

        return ExecutionRecords::capacity($this->tables->callerQuery('capacity', $transactionContext)->where('root_operation_ref', $rootRef)->first() ?? throw new LogicException('Capacity absent'));
    }

    public function reserveCapacity(TransactionContext $transactionContext, Reservation $reservation, CapacitySnapshot $expected): CapacitySnapshot
    {
        $current = $this->capacity($transactionContext, $reservation->rootOperationRef);
        if (ExecutionCodec::encode($current) !== ExecutionCodec::encode($expected) || $reservation->state !== ReservationState::Held || $reservation->consumed->minor !== 0 || $reservation->requested->minor <= 0 || ExecutionCodec::encode($reservation->requested->currency) !== ExecutionCodec::encode($current->authorizedLimit->currency) || $reservation->requested->minor > CapacityAmounts::available($current, $reservation->kind)) {
            ExecutionTables::refuse('Capacity reservation conflict');
        }
        $this->insertHeldReservation($transactionContext, $reservation, $current);

        return $this->capacity($transactionContext, $reservation->rootOperationRef);
    }

    private function insertHeldReservation(TransactionContext $transactionContext, Reservation $reservation, CapacitySnapshot $expected): void
    {
        $this->tables->database($transactionContext)->table('billium_execution_reservation')->insert($this->tables->callerScope($transactionContext) + ['reservation_ref' => $reservation->reservationRef, 'root_operation_ref' => $reservation->rootOperationRef, 'operation_ref' => $reservation->operationRef, 'kind' => $reservation->kind->value, 'requested_minor' => $reservation->requested->minor, 'currency' => $reservation->requested->currency->code, 'state' => 'held']);
        $column = match ($reservation->kind) {
            EffectKind::Capture => 'held_capture_minor',EffectKind::VoidAuthorization => 'held_void_minor',EffectKind::Refund => 'held_refund_minor',default => throw new LogicException('Invalid reservation kind')
        };
        $updated = $this->tables->callerQuery('capacity', $transactionContext)->where('root_operation_ref', $reservation->rootOperationRef)
            ->where('version', $expected->version)->incrementEach([$column => $reservation->requested->minor, 'version' => 1]);
        if ($updated !== 1) {
            ExecutionTables::refuse('Held reservation capacity changed');
        }
    }

    public function settleReservation(TransactionContext $transactionContext, string $reservationRef, ReservationState $reservationState, Money $consumed, ?string $releaseEvidenceRef, CapacitySnapshot $next): void
    {
        $current = $this->capacity($transactionContext, $next->rootOperationRef);
        $q = $this->tables->callerQuery('reservation', $transactionContext)->where('reservation_ref', $reservationRef);
        $r = (clone $q)->first() ?? throw new LogicException('Reservation absent');
        if ($next->version !== $current->version + 1 || ExecutionRows::text($r->root_operation_ref) !== $next->rootOperationRef || ExecutionRows::text($r->state) !== 'held' || $reservationState === ReservationState::Held || $consumed->minor < 0 || $consumed->minor > ExecutionRows::integer($r->requested_minor) || $consumed->currency->code !== ExecutionRows::text($r->currency)) {
            ExecutionTables::refuse('Reservation settlement conflict');
        }
        $heldColumn = match (EffectKind::from(ExecutionRows::text($r->kind))) {
            EffectKind::Capture => 'heldCapture', EffectKind::Refund => 'heldRefund', EffectKind::VoidAuthorization => 'heldVoid',
            default => throw new LogicException('Invalid reservation kind'),
        };
        foreach (['heldCapture', 'heldRefund', 'heldVoid'] as $column) {
            $expectedHeld = $current->{$column}->minor - ($column === $heldColumn ? ExecutionRows::integer($r->requested_minor) : 0);
            if ($expectedHeld < 0 || $next->{$column}->minor !== $expectedHeld) {
                ExecutionTables::refuse('Settlement must remove the original held reservation exactly once');
            }
        }
        $operationRef = ExecutionRows::text($r->operation_ref);
        $this->tables->requireOperationLock($transactionContext, $operationRef);
        $fact = $releaseEvidenceRef === null ? null : $this->factForEvidence($transactionContext, $releaseEvidenceRef, $operationRef);
        if ($consumed->minor < ExecutionRows::integer($r->requested_minor) && $this->hasGreaterActual($transactionContext, $operationRef, $consumed->minor)) {
            ExecutionTables::refuse('Reservation release contradicts accepted original operation amount');
        }
        $signed = $fact?->snapshot;
        $valid = $signed !== null && $signed->kind->value === $r->kind && $signed->operationAmount !== null
            && $signed->operationAmount->minor === $consumed->minor && $signed->operationAmount->currency->code === $r->currency
            && ($reservationState === ReservationState::Released
                ? $consumed->minor === 0 && in_array($signed->normalizedStatus, ['declined', 'failed', 'no_effect'], true)
                : $consumed->minor > 0 && $signed->normalizedStatus === 'succeeded');
        if (! $valid) {
            $attempt = $this->attemptForOperation($this->tables->admittedOperation($transactionContext, $operationRef)->binding->scope, $operationRef, $transactionContext);
            if ($reservationState !== ReservationState::Released || $consumed->minor !== 0 || $releaseEvidenceRef === null || $attempt === null
                || ! $this->tables->callerQuery('certified_absence', $transactionContext)->where('attempt_ref', $attempt->attemptRef)->where('evidence_ref', $releaseEvidenceRef)->exists()) {
                ExecutionTables::refuse('Reservation requires accepted original operation amount or verified absence');
            }
        }
        $q->where('state', 'held')->update(['state' => $reservationState->value, 'consumed_minor' => $consumed->minor, 'release_evidence_ref' => $releaseEvidenceRef, 'version' => ExecutionRows::integer($r->version) + 1]);
        $this->writeCapacity($transactionContext, $next, $current->version);
    }

    public function ensureObservationPartition(TransactionContext $transactionContext, ExecutionScope $executionScope, string $sourceAuthority, string $lineage, string $rootRef): ObservationPartition
    {
        $this->tables->financial($transactionContext);
        $this->tables->assertScope($executionScope, $transactionContext, $sourceAuthority);
        $root = $this->tables->admittedOperation($transactionContext, $rootRef);
        if ($root->operationRef !== $root->rootOperationRef) {
            ExecutionTables::refuse('Partition requires original root');
        }

        return $this->persistObservationPartition($transactionContext, $executionScope, $sourceAuthority, $lineage, $rootRef);
    }

    public function prepareObservationPartition(TransactionContext $context, FrozenBinding $binding, string $lineage): ObservationPartition
    {
        $this->tables->financial($context);
        $this->tables->assertScope($binding->scope, $context, $binding->executionProfile);
        if ($binding->origin === null || $lineage === '' || strlen($lineage) > 200) {
            ExecutionTables::refuse('Initial partition requires an authenticated original basis and complete lineage');
        }

        return $this->persistObservationPartition($context, $binding->scope, $binding->executionProfile, $lineage, $binding->rootOperationRef);
    }

    private function persistObservationPartition(TransactionContext $transactionContext, ExecutionScope $executionScope, string $sourceAuthority, string $lineage, string $rootRef): ObservationPartition
    {
        $map = ['schema' => 'billium.execution.observation-partition.v1', 'installationRef' => $executionScope->installationRef, 'sellerRef' => $executionScope->sellerRef, 'environment' => $executionScope->environment, 'sourceAuthority' => $sourceAuthority, 'sourceLineage' => $lineage, 'rootOperationRef' => $rootRef];
        $part = 'execution-charge:'.hash('sha256', (new Codec)->canonicalize((object) $map));
        $this->tables->database($transactionContext)->table('billium_execution_observation_partition')->insertOrIgnore(ExecutionTables::scope($executionScope) + ['source_authority' => $sourceAuthority, 'source_lineage' => $lineage, 'root_operation_ref' => $rootRef, 'partition_ref' => $part]);
        $r = $this->tables->query('observation_partition', $executionScope, $transactionContext)->where('source_authority', $sourceAuthority)->where('source_lineage', $lineage)->where('root_operation_ref', $rootRef)->first() ?? throw new LogicException('Partition absent');

        return new ObservationPartition(ExecutionRows::integer($r->id), $executionScope, $sourceAuthority, $lineage, $rootRef, ExecutionRows::text($r->partition_ref), ExecutionRows::integer($r->last_sequence));
    }

    public function nextObservationSequence(TransactionContext $transactionContext, int $partitionId): int
    {
        $this->tables->financial($transactionContext);
        if (! $this->tables->holds($transactionContext, 'billium_execution_observation_partition:'.$partitionId)) {
            throw new LogicException('Observation partition lock required');
        }
        $q = $this->tables->callerQuery('observation_partition', $transactionContext)->where('id', $partitionId)->where('source_authority', $this->tables->selectedScope($transactionContext)->sourceAuthority);
        $partition = (clone $q)->first() ?? throw new LogicException('Partition absent');
        $this->tables->requireLock($transactionContext, ExecutionRows::text($partition->root_operation_ref));
        $q->increment('last_sequence');

        return ExecutionRows::integer($q->value('last_sequence'));
    }

    public function appendEmission(TransactionContext $transactionContext, ObservationEmission $observationEmission): void
    {
        $this->tables->financial($transactionContext);
        $this->tables->assertScope($observationEmission->scope, $transactionContext, $observationEmission->sourceAuthority);
        $op = $this->operation($observationEmission->scope, $observationEmission->operationRef, $transactionContext) ?? throw new LogicException('Emission operation absent');
        $p = $observationEmission->payload;
        if ([$p->installationRef, $p->sellerRef, $p->environment, $p->sourceAuthority, $p->sourceLineage, $p->partitionRef, $p->sourceSequence, $p->operationRef, $p->operationRevision, $p->eventId, $p->contentDigest] !== [$observationEmission->scope->installationRef, $observationEmission->scope->sellerRef, $observationEmission->scope->environment, $observationEmission->sourceAuthority, $observationEmission->sourceLineage, $observationEmission->partitionRef, $observationEmission->sourceSequence, $observationEmission->operationRef, $observationEmission->operationRevision, $observationEmission->eventId, $observationEmission->payloadDigest] || $op->revision !== $observationEmission->operationRevision || (new Codec)->digestOf($p) !== $observationEmission->payloadDigest) {
            ExecutionTables::refuse('Emission differs from immutable wire observation');
        }

        $this->tables->requireLock($transactionContext, $op->rootOperationRef);
        $this->tables->database($transactionContext)->table('billium_execution_observation_emission')->insert(ExecutionTables::scope($observationEmission->scope) + ['emission_ref' => $observationEmission->emissionRef, 'source_authority' => $observationEmission->sourceAuthority, 'source_lineage' => $observationEmission->sourceLineage, 'partition_ref' => $observationEmission->partitionRef, 'source_sequence' => $observationEmission->sourceSequence, 'root_operation_ref' => $op->rootOperationRef, 'operation_ref' => $observationEmission->operationRef, 'operation_revision' => $observationEmission->operationRevision, 'command_ref' => $observationEmission->commandRef, 'event_id' => $observationEmission->eventId, 'payload_digest' => $observationEmission->payloadDigest, 'payload' => ExecutionCodec::encode($observationEmission->payload), 'command_row_id' => $observationEmission->commandRowId]);
    }

    public function bindEmissionCommandRow(TransactionContext $transactionContext, string $commandRef, int $commandRowId): void
    {
        $this->tables->financial($transactionContext);
        $q = $this->tables->callerQuery('observation_emission', $transactionContext)->where('command_ref', $commandRef);
        $row = (clone $q)->first() ?? throw new LogicException('Emission absent');
        $this->tables->requireLock($transactionContext, ExecutionRows::text($row->root_operation_ref));
        if ($commandRowId <= 0 || $q->whereNull('command_row_id')->update(['command_row_id' => $commandRowId]) !== 1) {
            throw new LogicException('Emission command row already bound or invalid');
        }
    }

    public function recordRecoveryAction(TransactionContext $transactionContext, RecoveryAction $recoveryAction): ExecutionReceipt
    {
        return $this->appendRepairReceipt($transactionContext, $recoveryAction, $recoveryAction->semanticDigest, $recoveryAction->semanticDigest, []);
    }

    public function authorization(TransactionContext $transactionContext, string $rootOperationRef): ?AuthorizationRecord
    {
        $this->tables->financial($transactionContext);
        $this->tables->requireLock($transactionContext, $rootOperationRef);
        $r = $this->tables->callerQuery('authorization', $transactionContext)->where('root_operation_ref', $rootOperationRef)->first();
        if ($r === null) {
            return null;
        }

        return ExecutionRecords::authorization($r);
    }

    public function applyAuthorization(TransactionContext $transactionContext, AuthorizationRecord $authorizationRecord, int $expectedRevision): void
    {
        $current = $this->authorization($transactionContext, $authorizationRecord->rootOperationRef);
        if ($authorizationRecord->revision !== $expectedRevision + 1 || ($current === null ? $expectedRevision !== 0 : $current->revision !== $expectedRevision)) {
            ExecutionTables::refuse('Authorization revision conflict');
        }
        $row = ['authorization_ref' => $authorizationRecord->authorizationRef, 'root_operation_ref' => $authorizationRecord->rootOperationRef, 'authorized_minor' => $authorizationRecord->authorized->minor, 'released_minor' => $authorizationRecord->released->minor, 'currency' => $authorizationRecord->authorized->currency->code, 'provider_expires_at' => $authorizationRecord->providerExpiresAt?->format(ExecutionRows::TIMESTAMP), 'expiry_evidence_ref' => $authorizationRecord->expiryEvidenceRef, 'release_evidence_ref' => $authorizationRecord->releaseEvidenceRef, 'state' => $authorizationRecord->state, 'revision' => $authorizationRecord->revision];
        if ($current === null) {
            $this->tables->database($transactionContext)->table('billium_execution_authorization')->insert($this->tables->callerScope($transactionContext) + $row);
        } else {
            unset($row['authorization_ref'],$row['root_operation_ref'],$row['currency']);
            if ($this->tables->callerQuery('authorization', $transactionContext)->where('authorization_ref', $authorizationRecord->authorizationRef)->where('revision', $expectedRevision)->update($row) !== 1) {
                ExecutionTables::refuse('Authorization CAS conflict');
            }
        }
    }

    public function appendReconciliationRun(TransactionContext $transactionContext, ReconciliationRun $reconciliationRun): void
    {
        $this->tables->financial($transactionContext);
        $this->tables->assertScope($reconciliationRun->scope, $transactionContext);
        $this->tables->database($transactionContext)->table('billium_execution_reconciliation_run')->insert(ExecutionTables::scope($reconciliationRun->scope) + $this->runRow($reconciliationRun));
    }

    public function advanceReconciliationRun(TransactionContext $transactionContext, ReconciliationRun $reconciliationRun, string $expectedState): void
    {
        $this->tables->financial($transactionContext);
        $this->tables->assertScope($reconciliationRun->scope, $transactionContext);
        $row = $this->runRow($reconciliationRun);
        unset($row['run_ref'],$row['merchant_account_ref'],$row['recovery_horizon'],$row['started_at']);
        if ($this->tables->query('reconciliation_run', $reconciliationRun->scope, $transactionContext)->where('run_ref', $reconciliationRun->runRef)->where('state', $expectedState)->update($row) !== 1) {
            ExecutionTables::refuse('Reconciliation CAS conflict');
        }
    }

    public function appendRepairReceipt(TransactionContext $transactionContext, RecoveryAction $recoveryAction, string $beforeDigest, string $afterDigest, array $correctionRefs): ExecutionReceipt
    {
        $this->tables->financial($transactionContext);
        $this->tables->assertScope($recoveryAction->scope, $transactionContext);
        $root = $this->operation($recoveryAction->scope, $recoveryAction->rootOperationRef, $transactionContext) ?? throw new LogicException('Repair root unavailable');
        if ($root->operationRef !== $root->rootOperationRef) {
            ExecutionTables::refuse('Repair must name original root');
        }
        $this->tables->requireLock($transactionContext, $recoveryAction->rootOperationRef);
        if ($recoveryAction->evidenceRefs === []) {
            ExecutionTables::refuse('Recovery requires evidence');
        }
        $this->tables->database($transactionContext)->table('billium_execution_repair_receipt')->insert(ExecutionTables::scope($recoveryAction->scope) + ['repair_ref' => $recoveryAction->actionRef, 'root_operation_ref' => $recoveryAction->rootOperationRef, 'authorization_ref' => $recoveryAction->authorizationRef, 'reason' => $recoveryAction->reason, 'evidence_refs' => ExecutionCodec::encode($recoveryAction->evidenceRefs), 'before_digest' => $beforeDigest, 'after_digest' => $afterDigest, 'correction_refs' => ExecutionCodec::encode($correctionRefs), 'action_kind' => $recoveryAction->actionKind, 'intent_owner_namespace' => $recoveryAction->scope->intentOwnerNamespace]);

        return new ExecutionReceipt($recoveryAction->actionRef, $recoveryAction->actionRef, $recoveryAction->rootOperationRef, $recoveryAction->semanticDigest, 'recorded', false, $recoveryAction->evidenceRefs);
    }

    public function transitionNamespace(TransactionContext $transactionContext, ExecutionScope $executionScope, NamespaceState $namespaceState, int $expectedGeneration, string $authorityEvidenceRef): NamespaceRecord
    {
        $this->tables->financial($transactionContext);
        $this->tables->assertScope($executionScope, $transactionContext);
        if ($authorityEvidenceRef === '') {
            ExecutionTables::refuse('Namespace transition lacks authority evidence');
        }
        $q = $this->tables->query('namespace', $executionScope, $transactionContext)->where('intent_owner_namespace', $executionScope->intentOwnerNamespace);
        if ($expectedGeneration === 0) {
            $inserted = $this->tables->database($transactionContext)->table('billium_execution_namespace')->insertOrIgnore(ExecutionTables::scope($executionScope) + ['intent_owner_namespace' => $executionScope->intentOwnerNamespace, 'state' => $namespaceState->value, 'canonicalization_version' => 'execution.intent.v1', 'completeness_receipt_ref' => $authorityEvidenceRef, 'generation' => 1]);
            if ($inserted !== 1) {
                ExecutionTables::refuse('Namespace creation race');
            }
        } else {
            $id = (clone $q)->value('id');
            if ($id === null || $transactionContext->lock(new LockRequest(LockRank::IntentNamespace, 'billium_execution_namespace', [ExecutionRows::integer($id)])) !== [ExecutionRows::integer($id)]
                || $q->where('generation', $expectedGeneration)->update(['state' => $namespaceState->value, 'completeness_receipt_ref' => $authorityEvidenceRef, 'generation' => $expectedGeneration + 1]) !== 1) {
                ExecutionTables::refuse('Namespace generation conflict');
            }
        }

        return $this->namespace($executionScope, $transactionContext);
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

    private function writeCapacity(TransactionContext $context, CapacitySnapshot $next, int $expectedVersion): void
    {
        $this->tables->requireLock($context, $next->rootOperationRef);
        foreach ([$next->authorizedLimit, $next->confirmedCapture, $next->confirmedRefund, $next->releasedAuthorization, $next->heldCapture, $next->heldVoid, $next->heldRefund] as $money) {
            if ($money->minor < 0 || $money->currency->code !== $next->authorizedLimit->currency->code || $money->currency->scale !== $next->authorizedLimit->currency->scale) {
                ExecutionTables::refuse('Capacity counters require one exact nonnegative currency');
            }
        }
        $row = ['authorized_limit_minor' => $next->authorizedLimit->minor, 'confirmed_capture_minor' => $next->confirmedCapture->minor, 'confirmed_refund_minor' => $next->confirmedRefund->minor, 'released_authorization_minor' => $next->releasedAuthorization->minor, 'held_capture_minor' => $next->heldCapture->minor, 'held_void_minor' => $next->heldVoid->minor, 'held_refund_minor' => $next->heldRefund->minor, 'financial_hold' => $next->financialHold, 'version' => $next->version];
        if ($next->version !== $expectedVersion + 1 || $this->tables->callerQuery('capacity', $context)->where('root_operation_ref', $next->rootOperationRef)->where('version', $expectedVersion)->where('currency', $next->authorizedLimit->currency->code)->update($row) !== 1) {
            ExecutionTables::refuse('Capacity version conflict');
        }
    }

    /** @return array<string,mixed> */
    private function runRow(ReconciliationRun $r): array
    {
        return ['run_ref' => $r->runRef, 'merchant_account_ref' => $r->merchantAccountRef, 'recovery_horizon' => $r->recoveryHorizon->format(ExecutionRows::TIMESTAMP), 'inventory_cursor' => $r->inventoryCursor, 'completeness_watermark' => $r->completenessWatermark, 'inventory_digest' => $r->inventoryDigest, 'manifest_digest' => $r->manifestDigest, 'state' => $r->state, 'unresolved' => ExecutionCodec::encode($r->unresolved), 'started_at' => $r->startedAt->format(ExecutionRows::TIMESTAMP), 'completed_at' => $r->completedAt?->format(ExecutionRows::TIMESTAMP)];
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
}
