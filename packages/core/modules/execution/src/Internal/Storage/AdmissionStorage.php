<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Storage;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Data\AdmissionConflict;
use Billium\Execution\Data\AdmissionPlan;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use LogicException;
use Packman\Attributes\Internal;

/** Business identity bindings, child identities, operation admission and the audit of admission conflicts. */
#[Internal]
final readonly class AdmissionStorage
{
    public function __construct(private ExecutionTables $tables, private MerchantRevisionStorage $merchants, private NamespaceStorage $namespaces) {}

    public function insertBinding(TransactionContext $transactionContext, FrozenBinding $frozenBinding): ?OperationRecord
    {
        $this->tables->financial($transactionContext);
        $this->tables->assertScope($frozenBinding->scope, $transactionContext, $frozenBinding->executionProfile);
        $namespace = $this->namespaces->lockNamespace($transactionContext, $frozenBinding->scope);
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

        return $this->tables->operation($frozenBinding->scope, ExecutionRows::text($existing->operation_ref), $transactionContext) ?? throw new LogicException('Existing business binding has no operation');
    }

    public function claimChildBinding(TransactionContext $context, AdmissionPlan $plan, string $businessIntentRef, ?string $reasonCode = null, ?string $category = null): ?OperationRecord
    {
        $this->tables->financial($context);
        $binding = $plan->binding;
        $scope = $binding->scope;
        $this->tables->assertScope($scope, $context, $binding->executionProfile);
        $this->tables->requireLock($context, $binding->rootOperationRef);
        $root = $this->tables->operation($scope, $binding->rootOperationRef, $context);
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
        if ($this->tables->operation($scope, $plan->operationRef, $context) !== null) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'The operation reference already belongs to another intent');
        }
        // Callers must already have acquired this namespace at rank 27 before the root lock.
        $namespace = $this->namespaces->lockNamespace($context, $scope);
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

        return $row === null ? null : $this->tables->operation($scope, ExecutionRows::text($row->operation_ref), $context);
    }

    public function insertOperation(TransactionContext $transactionContext, AdmissionPlan $admissionPlan): OperationRecord
    {
        $this->tables->financial($transactionContext);
        $b = $admissionPlan->binding;
        $this->tables->assertScope($admissionPlan->scope, $transactionContext, $b->executionProfile);
        if (ExecutionCodec::differs($admissionPlan->scope, $b->scope) || $admissionPlan->amount->minor <= 0 || Currency::of($admissionPlan->amount->currency->code)->scale !== $admissionPlan->amount->currency->scale) {
            ExecutionTables::refuse('Invalid binding, amount or currency precision');
        }
        $merchant = $this->merchants->merchantRevision($b->scope, $b->merchantRevisionRef, $transactionContext);
        if ([$merchant->merchantAccountRef, $merchant->driverId, $merchant->driverContractVersion, $merchant->capabilityDigest] !== [$b->merchantAccountRef, $b->driverId, $b->driverContractVersion, $b->capabilityDigest]) {
            ExecutionTables::refuse('Merchant identity substitution');
        }
        $cap = $this->merchants->capability($b->scope, $merchant->capabilitySnapshotRef, $transactionContext);
        $cap->requireEffect($admissionPlan->kind, $admissionPlan->amount->currency->code, $b->scope->environment === 'production');
        if (! in_array($admissionPlan->amount->currency->code, $merchant->currencyCodes, true)) {
            ExecutionTables::refuse('Merchant currency unsupported');
        }
        if (in_array($admissionPlan->kind, [EffectKind::Charge, EffectKind::Authorize], true)) {
            $existing = $this->tables->operation($b->scope, $admissionPlan->operationRef, $transactionContext);
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
            $root = $this->tables->operation($b->scope, $b->rootOperationRef, $transactionContext) ?? throw new LogicException('Child root missing');
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
            $original = $this->tables->operation($scope, $conflict->originalOperationRef, $context);
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

        return $this->tables->operation($plan->scope, ExecutionRows::text($actual['operation_ref'] ?? null), $context)
            ?? throw new LogicException('A committed child identity must retain its original operation');
    }
}
