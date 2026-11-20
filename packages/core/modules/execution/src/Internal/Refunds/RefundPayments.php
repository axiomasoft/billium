<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Refunds;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\RequestRefund;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Execution\Contracts\CapacityParticipants;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantActionGate;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Data\AdmissionConflict;
use Billium\Execution\Data\AdmissionPlan;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Enums\DriverCapability;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Internal\Admission\CollectionChecks;
use Billium\Execution\Internal\Admission\CommandInputReplay;
use Billium\Execution\Internal\Admission\ExecutionReadinessGuard;
use Billium\Execution\Internal\Admission\QualifiedAdapter;
use Billium\Execution\Internal\Dispatch\TransportBoundary;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Merchants\ContinuityMerchantCredentials;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use Packman\Attributes\Internal;
use RuntimeException;

#[Internal]
final readonly class RefundPayments implements RefundCommands
{
    public function __construct(
        private OperationRunner $runner,
        private CallerContext $callers,
        private ExecutionStore $store,
        private IdempotencyReceiptQueries $receipts,
        private CapacityParticipants $capacity,
        private MerchantRegistry $merchants,
        private MerchantActionGate $merchantGate,
        private QualifiedAdapter $adapters,
        private ContinuityMerchantCredentials $credentials,
        private CollectionChecks $collection,
        private TransportBoundary $boundary,
        private ExecutionReadinessGuard $readiness,
        private CommandInputReplay $inputReplay,
    ) {}

    public function admit(ExecutionScope $scope, RequestRefund $command): StoredOutcome
    {
        $this->boundary->assertOutsideTransaction();
        $codec = new Codec;
        $wire = $codec->decode($codec->encode($command));
        if (! $wire instanceof RequestRefund || ! is_string($wire->source->context ?? null) || ! is_string($wire->source->intentRef ?? null)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Refund requires a business source and intent');
        }
        $intent = $wire->source->intentRef;
        $source = $wire->source->context;
        foreach ([$source, $intent, $wire->operationRef, $wire->originalOperationRef, $wire->reasonCode, $wire->category,
            $scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace] as $field) {
            if (str_contains($field, "\0")) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Refund identity contains a null byte');
            }
        }
        if ([$wire->installationRef, $wire->sellerRef, $wire->environment] !== [$scope->installationRef, $scope->sellerRef, $scope->environment]) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Refund address differs from the selected scope');
        }
        $root = $this->store->operation($scope, $wire->originalOperationRef) ?? throw new ProblemException(ProblemCode::ReferenceUnknown, 'Original payment is unavailable');
        $binding = $root->binding;
        $selected = PaymentIdentity::select($this->callers->current(), $scope, $binding->executionProfile);
        if (get_object_vars($binding->scope) !== get_object_vars($scope) || $binding->origin === null || $binding->origin->sourceContext !== $source || $binding->sourceContext !== $source) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Refund source differs from the verified original basis');
        }
        if (isset($wire->source->orderRef) && $wire->source->orderRef !== $binding->sourceOrderRef) {
            throw new ProblemException($binding->sourceOrderRef === null ? ProblemCode::RecoveryRequired : ProblemCode::AuthDenied, 'Refund order differs from the verified original order');
        }
        if ($root->operationRef !== $root->rootOperationRef || ! in_array($root->kind, [EffectKind::Charge, EffectKind::Authorize], true)
            || $root->operationRef === $wire->operationRef || $root->requested->currency->code !== $wire->money->currency->code || $root->requested->currency->scale !== $wire->money->currency->scale) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Refund requires a distinct child of an original payment in its currency');
        }
        $terms = get_object_vars($wire->raw);
        unset($terms['commandKey'], $terms['requestedAt'], $terms['digest']);
        $semantic = PaymentIdentity::digest(['schema' => 'billium.execution.refund-semantics.v1', 'scope' => get_object_vars($scope),
            'originalBinding' => $binding->semanticDigest, 'terms' => $terms]);
        $key = PaymentIdentity::commandKey($scope, EffectKind::Refund->value, $source, $wire->commandKey);
        $retained = $this->store->childByIdentity($scope, EffectKind::Refund, $source, $intent) ?? $this->store->operation($scope, $wire->operationRef);
        try {
            $inputDigest = CommandInputReplay::digest($wire);
            $cached = $this->inputReplay->find($selected, RequestRefund::SCHEMA, $key, $inputDigest);
            if ($cached !== null) {
                return $cached;
            }
            $replay = $retained !== null || $this->receipts->byCommandKey($selected, RequestRefund::SCHEMA, $key) !== null;
            $credentials = $capability = null;
            if (! $replay) {
                $this->readiness->assertReady($binding, EffectKind::Refund, $wire->money);
                $merchant = $this->merchants->historical($scope, $root->operationRef);
                [, $capability] = $this->adapters->select($merchant, EffectKind::Refund, $wire->money->currency->code);
                try {
                    $credentials = $this->credentials->resolve($merchant, 'refund');
                } catch (ProblemException $error) {
                    $this->historicalAccessFailure($error);
                } catch (RuntimeException) {
                    throw new ProblemException(ProblemCode::RecoveryRequired, 'Historical refund access could not be verified for the original account');
                }
            }
            $modules = $this->collection->modules(null);
            $plan = new AdmissionPlan($scope, $binding, $wire->operationRef, EffectKind::Refund, $wire->money, $wire->commandKey,
                $semantic, $binding->canonicalizationVersion, $modules, [], null);
            $spec = new OperationSpec(RequestRefund::SCHEMA, $scope->sellerRef, $scope->environment, FinancialMode::Active,
                $replay ? ModuleOperationClass::ReceiptReplay : ModuleOperationClass::OriginalOwnerRefund,
                $modules, $key, $semantic, 'execution.admission', $selected, $inputDigest, originalExecutionBindingRef: $binding->rootOperationRef);

            return $this->runner->run($spec, fn (TransactionContext $ctx): ExecutionReceipt => $this->apply($ctx, $plan, $intent, $wire, $capability, $credentials));
        } catch (ProblemException $error) {
            if ($error->problem === ProblemCode::IdempotencyConflict) {
                $authorized = $retained !== null && get_object_vars($retained->binding->scope) === get_object_vars($scope)
                    && $retained->binding->executionProfile === $selected->sourceAuthority ? $retained->operationRef : null;
                $conflict = new AdmissionConflict($scope, RequestRefund::SCHEMA, $key, $semantic, $authorized);
                $this->runner->run(new OperationSpec('execution.admission_conflict', $scope->sellerRef, $scope->environment, FinancialMode::RawCustody,
                    ModuleOperationClass::CallbackCustody, ['execution', 'platform'], financialScope: $selected),
                    fn (TransactionContext $ctx): ExecutionReceipt => new ExecutionReceipt($this->store->recordAdmissionConflict($ctx, $conflict), $wire->commandKey,
                        $wire->operationRef, $semantic, 'conflict_recorded', false, []))->orThrow();
            }
            throw $error;
        }
    }

    private function historicalAccessFailure(ProblemException $error): never
    {
        if (in_array($error->problem, [ProblemCode::ValidationInvalid, ProblemCode::LookupUnavailable], true)) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Historical refund credentials or permission are unavailable for the original account');
        }
        throw $error;
    }

    private function apply(TransactionContext $ctx, AdmissionPlan $plan, string $intent, RequestRefund $wire, ?CapabilitySnapshot $capability, ?ResolvedCredentials $credentials): ExecutionReceipt
    {
        $scope = $plan->scope;
        $original = $plan->binding->rootOperationRef;
        if ($capability !== null && $credentials !== null) {
            $namespace = $this->store->lockNamespace($ctx, $scope);
            if ($namespace->state !== NamespaceState::Open || $namespace->completenessReceiptRef === null || $namespace->canonicalizationVersion !== $plan->canonicalizationVersion) {
                throw new ProblemException($namespace->state === NamespaceState::Closed ? ProblemCode::KeyExpired : ProblemCode::RecoveryRequired, 'Refund identity history is closed or incomplete');
            }
            try {
                $this->merchantGate->lock($ctx, $scope, $plan->binding->merchantRevisionRef, 'refund', $credentials);
            } catch (ProblemException $error) {
                $this->historicalAccessFailure($error);
            }
        }
        $locks = $this->store->plan($scope, $original, true, $ctx);
        // Every monetary writer locks the original payment's observation partitions before its root and children.
        // Establish that common prefix before discovering siblings added by other waiting admissions.
        $partition = array_find($locks->locks, static fn (LockRequest $lock): bool => $lock->rank === LockRank::FeedPartition);
        if ($partition === null) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Original payment has no retained observation partition');
        }
        if ($ctx->lock($partition) !== $partition->keys) {
            throw new LockPlanChanged('Refund observation partition changed during admission');
        }
        $locks = $this->store->plan($scope, $original, true, $ctx);
        $this->store->lock($ctx, $locks);
        $root = $this->store->operation($scope, $original, $ctx);
        if ($root === null || ExecutionCodec::encode($root->binding) !== ExecutionCodec::encode($plan->binding)) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Original payment binding changed');
        }
        $existing = $this->store->claimChildBinding($ctx, $plan, $intent, $wire->reasonCode, $wire->category);
        $attemptRef = PaymentIdentity::reference($scope, $plan->operationRef, 'attempt');
        if ($existing === null) {
            if ($capability === null || $credentials === null) {
                throw new ProblemException(ProblemCode::IdempotencyConflict, 'Refund replay differs from the retained intent');
            }
            $current = $this->capacity->current($ctx, $original);
            if ($current->financialHold) {
                throw new ProblemException(ProblemCode::OperationFinancialHold, 'Original payment is on financial hold');
            }
            if ($plan->amount->minor < $current->confirmedCapture->minor) {
                $capability->requireCapability(DriverCapability::PartialRefund, $scope->environment === 'production');
            }
            if ($current->confirmedRefund->minor > 0 || $current->heldRefund->minor > 0) {
                $capability->requireCapability(DriverCapability::MultipleRefund, $scope->environment === 'production');
            }
            $child = $this->store->insertOperation($ctx, $plan);
            $this->capacity->reserve($ctx, $original, $child->operationRef, EffectKind::Refund, $plan->amount, PaymentIdentity::reference($scope, $child->operationRef, 'reservation'));
            $attempt = $this->store->insertAttempt($ctx, $child, $attemptRef, PaymentIdentity::reference($scope, $child->operationRef, 'provider'));
            $this->store->insertDispatchWork($ctx, $attempt, PaymentIdentity::reference($scope, $child->operationRef, 'dispatch'), 'execution.dispatch.v1');
        }

        return new ExecutionReceipt(PaymentIdentity::reference($scope, $plan->operationRef, 'admission'), $plan->commandKey, $plan->operationRef,
            $plan->semanticDigest, 'admitted', false, [$attemptRef]);
    }
}
