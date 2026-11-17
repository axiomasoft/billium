<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Capture;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\CapturePayment;
use Billium\Contracts\Data\Envelopes\VoidAuthorization;
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
use Billium\Execution\Contracts\AuthorizationCommands;
use Billium\Execution\Contracts\CapacityParticipants;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantActionGate;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Data\AdmissionConflict;
use Billium\Execution\Data\AdmissionPlan;
use Billium\Execution\Data\CapabilitySnapshot;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ExecutionPhase;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Internal\Admission\CollectionChecks;
use Billium\Execution\Internal\Admission\CommandInputReplay;
use Billium\Execution\Internal\Admission\ExecutionReadinessGuard;
use Billium\Execution\Internal\Admission\QualifiedAdapter;
use Billium\Execution\Internal\Authorization\AuthorizationPolicy;
use Billium\Execution\Internal\Dispatch\TransportBoundary;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Merchants\ContinuityMerchantCredentials;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use DateTimeImmutable;
use Packman\Attributes\Internal;

#[Internal]
final readonly class AuthorizedPayments implements AuthorizationCommands
{
    public function __construct(
        private OperationRunner $runner,
        private CallerContext $callers,
        private ExecutionStore $store,
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

    public function admit(ExecutionScope $scope, CapturePayment|VoidAuthorization $command): StoredOutcome
    {
        $this->boundary->assertOutsideTransaction();
        $codec = new Codec;
        $wire = $codec->decode($codec->encode($command));
        if ((! $wire instanceof CapturePayment && ! $wire instanceof VoidAuthorization) || $wire::class !== $command::class
            || ! is_string($wire->source->context ?? null) || ! is_string($wire->source->intentRef ?? null)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid authorization child envelope');
        }
        $source = $wire->source->context;
        $intent = $wire->source->intentRef;
        $reason = $wire instanceof VoidAuthorization ? $wire->reasonCode : null;
        foreach ([$source, $intent, $wire->operationRef, $wire->originalOperationRef, $reason ?? '', $scope->installationRef,
            $scope->environment, $scope->sellerRef, $scope->intentOwnerNamespace] as $field) {
            if (str_contains($field, "\0")) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'Authorization identity contains a null byte');
            }
        }
        if ($wire->installationRef !== $scope->installationRef || $wire->sellerRef !== $scope->sellerRef || $wire->environment !== $scope->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Child payment address differs from the authenticated scope');
        }

        $root = $this->store->operation($scope, $wire->originalOperationRef) ?? throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown original authorization');
        $binding = $root->binding;
        $selected = PaymentIdentity::select($this->callers->current(), $scope, $binding->executionProfile);
        if ($binding->origin === null || $binding->origin->sourceContext !== $source || $binding->sourceContext !== $source) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Child source differs from the original authorization');
        }
        if (isset($wire->source->orderRef) && $wire->source->orderRef !== $binding->sourceOrderRef) {
            throw new ProblemException($binding->sourceOrderRef === null ? ProblemCode::RecoveryRequired : ProblemCode::AuthDenied, 'Child order differs from the verified original order');
        }
        if ($root->kind !== EffectKind::Authorize || $root->rootOperationRef !== $root->operationRef || $wire->operationRef === $root->operationRef) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Capture and provider void require a distinct child of an authorization');
        }
        if ($root->requested->currency->code !== $wire->money->currency->code || $root->requested->currency->scale !== $wire->money->currency->scale) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Child money differs from original authorization currency');
        }

        $kind = $wire instanceof CapturePayment ? EffectKind::Capture : EffectKind::VoidAuthorization;
        $key = PaymentIdentity::commandKey($scope, $kind->value, $source, $wire->commandKey);
        $terms = get_object_vars($wire->raw);
        unset($terms['commandKey'], $terms['requestedAt'], $terms['digest']);
        $semantic = PaymentIdentity::digest(['schema' => 'billium.execution.authorization-child.v1', 'rootBinding' => $binding->semanticDigest,
            'scope' => get_object_vars($scope), 'command' => $terms]);
        try {
            $inputDigest = CommandInputReplay::digest($wire);
            $cached = $this->inputReplay->find($selected, $wire::SCHEMA, $key, $inputDigest);
            if ($cached !== null) {
                return $cached;
            }
            $replay = $this->store->childByIdentity($scope, $kind, $source, $intent) !== null || $this->store->operation($scope, $wire->operationRef) !== null;
            $capability = $credentials = $guard = null;
            if (! $replay) {
                $this->readiness->assertReady($binding, $kind, $wire->money);
                $merchant = $this->merchants->historical($scope, $root->operationRef);
                [, $capability] = $this->adapters->select($merchant, $kind, $wire->money->currency->code);
                $credentials = $this->credentials->resolve($merchant, $kind->value);
                $guard = $this->collection->plan($binding->collectionAuthorizationRef, ExecutionPhase::Admission, $scope);
            }
            $modules = $this->collection->modules($guard);
            $plan = new AdmissionPlan($scope, $binding, $wire->operationRef, $kind, $wire->money, $wire->commandKey, $semantic,
                $binding->canonicalizationVersion, $modules, $guard->locks ?? [], $guard);
            $spec = new OperationSpec($wire::SCHEMA, $scope->sellerRef, $scope->environment, FinancialMode::Active,
                $replay ? ModuleOperationClass::ReceiptReplay : ($guard->operationClass ?? ModuleOperationClass::FrozenIntentCompletion),
                $modules, $key, $semantic, 'execution.admission', $selected, $inputDigest, originalExecutionBindingRef: $binding->rootOperationRef);

            return $this->runner->run($spec, function (TransactionContext $ctx) use ($plan, $intent, $reason, $capability, $credentials): ExecutionReceipt {
                if ($capability !== null) {
                    $namespace = $this->store->lockNamespace($ctx, $plan->scope);
                    if ($namespace->state !== NamespaceState::Open || $namespace->completenessReceiptRef === null || $namespace->canonicalizationVersion !== $plan->canonicalizationVersion) {
                        throw new ProblemException($namespace->state === NamespaceState::Closed ? ProblemCode::KeyExpired : ProblemCode::RecoveryRequired, 'Child identity history is incomplete or closed');
                    }
                    $this->merchantGate->lock($ctx, $plan->scope, $plan->binding->merchantRevisionRef, $plan->kind->value, $credentials);
                }

                return $this->apply($ctx, $plan, $intent, $reason, $capability);
            });
        } catch (ProblemException $error) {
            if ($error->problem === ProblemCode::IdempotencyConflict) {
                $conflict = new AdmissionConflict($scope, $wire::SCHEMA, $key, $semantic, $root->operationRef);
                $this->runner->run(new OperationSpec('execution.admission_conflict', $scope->sellerRef, $scope->environment, FinancialMode::RawCustody,
                    ModuleOperationClass::CallbackCustody, ['execution', 'platform'], financialScope: $selected),
                    fn (TransactionContext $ctx): ExecutionReceipt => new ExecutionReceipt($this->store->recordAdmissionConflict($ctx, $conflict), $wire->commandKey,
                        $wire->operationRef, $semantic, 'conflict_recorded', false, []))->orThrow();
            }
            throw $error;
        }
    }

    private function apply(TransactionContext $ctx, AdmissionPlan $plan, string $intent, ?string $reason, ?CapabilitySnapshot $capability): ExecutionReceipt
    {
        $scope = $plan->scope;
        $original = $plan->binding->rootOperationRef;
        $locks = $this->store->plan($scope, $original, true, $ctx);
        // Serialize admissions at the original partition before planning the mutable set of sibling operations.
        $partition = array_find($locks->locks, static fn (LockRequest $lock): bool => $lock->rank === LockRank::FeedPartition);
        if ($partition === null) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Original authorization has no retained observation partition');
        }
        if ($ctx->lock($partition) !== $partition->keys) {
            throw new LockPlanChanged('Authorization observation partition changed during admission');
        }
        $locks = $this->store->plan($scope, $original, true, $ctx);
        $this->collection->validate($ctx, $plan->collectionGuard, $locks, $plan->operationRef, $plan->semanticDigest);
        $this->store->lock($ctx, $locks);
        $root = $this->store->operation($scope, $original, $ctx);
        if ($root === null || $root->kind !== EffectKind::Authorize || ExecutionCodec::encode($root->binding) !== ExecutionCodec::encode($plan->binding)) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Original authorization binding changed');
        }
        $existing = $this->store->claimChildBinding($ctx, $plan, $intent, $reason);
        $attemptRef = PaymentIdentity::reference($scope, $plan->operationRef, 'attempt');
        if ($existing === null) {
            if ($capability === null) {
                throw new ProblemException(ProblemCode::IdempotencyConflict, 'Child replay has no matching retained identity');
            }
            AuthorizationPolicy::assertOpen($this->store->authorization($ctx, $original), new DateTimeImmutable);
            AuthorizationPolicy::assertAmount($plan->kind, $plan->amount, $capability, $this->capacity->current($ctx, $original));
            $child = $this->store->insertOperation($ctx, $plan);
            $this->capacity->reserve($ctx, $original, $plan->operationRef, $plan->kind, $plan->amount, PaymentIdentity::reference($scope, $plan->operationRef, 'reservation'));
            $attempt = $this->store->insertAttempt($ctx, $child, $attemptRef, PaymentIdentity::reference($scope, $plan->operationRef, 'provider'));
            $this->store->insertDispatchWork($ctx, $attempt, PaymentIdentity::reference($scope, $plan->operationRef, 'dispatch'), 'execution.dispatch.v1');
        }

        return new ExecutionReceipt(PaymentIdentity::reference($scope, $plan->operationRef, 'admission'), $plan->commandKey,
            $plan->operationRef, $plan->semanticDigest, 'admitted', false, [$attemptRef]);
    }
}
