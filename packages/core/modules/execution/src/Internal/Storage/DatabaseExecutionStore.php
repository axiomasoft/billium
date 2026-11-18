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
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Reducer\VerifiedReduction;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final class DatabaseExecutionStore implements ExecutionStore
{
    use ExecutionEvidencePersistence;

    private readonly ExecutionTables $tables;

    private readonly MerchantRevisionStorage $merchants;

    private readonly CapacityStorage $capacities;

    private readonly ObservationStorage $observations;

    private readonly ProviderCustodyStorage $custody;

    private readonly AttemptStorage $attempts;

    private readonly RecoveryRecordStorage $recoveryRecords;

    /** @param (Closure():?OriginalIdentityArchive)|null $identityArchive */
    public function __construct(DatabaseManager $databases, OperationRunner $runner, CallerContext $callers, BilliumOptions $options, private readonly FinancialSessionAdmission $sessionAdmission, private readonly ?AbsenceProofRegistry $absenceProofs = null, private readonly ?Closure $identityArchive = null, private readonly ?VerifiedReduction $verifiedReduction = null)
    {
        $this->tables = new ExecutionTables($databases, $runner, $callers, $options);
        $this->merchants = new MerchantRevisionStorage($this->tables);
        $this->capacities = new CapacityStorage($this->tables);
        $this->observations = new ObservationStorage($this->tables);
        $this->custody = new ProviderCustodyStorage($this->tables);
        $this->attempts = new AttemptStorage($this->tables, $this->merchants, $this->capacities, $sessionAdmission);
        $this->recoveryRecords = new RecoveryRecordStorage($this->tables);
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
            'binding' => ExecutionCodec::encode($b), 'operation_ref' => $a->operationRef, 'created_at' => $a->createdAt->format(ExecutionRows::TIMESTAMP)];
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
            || ExecutionCodec::differs(ExecutionCodec::map(ExecutionRows::text($existing->binding)), $frozenBinding)) {
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
            || ExecutionCodec::differs($plan->scope, $scope)
            || ExecutionCodec::differs($root->binding, $binding)
            || ExecutionCodec::differs($root->requested->currency, $plan->amount->currency)
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
        if (ExecutionCodec::differs($admissionPlan->scope, $b->scope) || $admissionPlan->amount->minor <= 0 || Currency::of($admissionPlan->amount->currency->code)->scale !== $admissionPlan->amount->currency->scale) {
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
                if ($existing->kind !== $admissionPlan->kind || ExecutionCodec::differs($existing->requested, $admissionPlan->amount)
                    || ExecutionCodec::differs($existing->binding, $b) || $admissionPlan->semanticDigest !== $b->semanticDigest
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
            if ($identity === null || $admissionPlan->canonicalizationVersion !== $b->canonicalizationVersion || ExecutionCodec::differs(ExecutionCodec::map(ExecutionRows::text($identity->binding)), $b)) {
                ExecutionTables::refuse('Root requires durable business binding');
            }
        } else {
            $root = $this->operation($b->scope, $b->rootOperationRef, $transactionContext) ?? throw new LogicException('Child root missing');
            $this->tables->requireLock($transactionContext, $root->rootOperationRef);
            if (ExecutionCodec::differs($root->binding, $b) || ExecutionCodec::differs($root->requested->currency, $admissionPlan->amount->currency)) {
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
        return $this->tables->attempt($executionScope, $attemptRef, $context);
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
        $this->capacities->write($transactionContext, $next, $current->version);
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
}
