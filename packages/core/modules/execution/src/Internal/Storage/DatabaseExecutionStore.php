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
use Billium\Execution\Data\MerchantRevision;
use Billium\Execution\Data\MerchantRevisionPolicy;
use Billium\Execution\Data\NamespaceRecord;
use Billium\Execution\Data\ObservationEmission;
use Billium\Execution\Data\ObservationPartition;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ReconciliationRun;
use Billium\Execution\Data\RecoveryAction;
use Billium\Execution\Data\Reservation;
use Billium\Execution\Data\SecretRef;
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
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;
use stdClass;
use WeakMap;

#[Internal]
final class DatabaseExecutionStore implements ExecutionStore, RootIdentityStore
{
    use ExecutionEvidencePersistence;

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
