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
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
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
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ExecutionPhase;
use Billium\Execution\Internal\Admission\ChildAdmission;
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
        private ChildAdmission $children,
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
        PaymentIdentity::assertText('Authorization identity contains a null byte', $source, $intent, $wire->operationRef, $wire->originalOperationRef,
            $reason ?? '', $scope->installationRef, $scope->environment, $scope->sellerRef, $scope->intentOwnerNamespace);
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
        $action = $wire instanceof CapturePayment ? 'capture' : 'void';
        $key = PaymentIdentity::commandKey($scope, $kind->value, $source, $wire->commandKey);
        $semantic = PaymentIdentity::digest(['schema' => 'billium.execution.authorization-child.v1', 'rootBinding' => $binding->semanticDigest,
            'scope' => get_object_vars($scope), 'command' => PaymentIdentity::terms($wire->raw)]);
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
                $credentials = ChildAdmission::historicalCredentials($action, fn (): ResolvedCredentials => $this->credentials->resolve($merchant, $kind->value));
                $guard = $this->collection->plan($binding->collectionAuthorizationRef, ExecutionPhase::Admission, $scope);
            }
            $modules = $this->collection->modules($guard);
            $plan = new AdmissionPlan($scope, $binding, $wire->operationRef, $kind, $wire->money, $wire->commandKey, $semantic,
                $binding->canonicalizationVersion, $modules, $guard->locks ?? [], $guard);
            $spec = new OperationSpec($wire::SCHEMA, $scope->sellerRef, $scope->environment, FinancialMode::Active,
                $replay ? ModuleOperationClass::ReceiptReplay : ($guard->operationClass ?? ModuleOperationClass::FrozenIntentCompletion),
                $modules, $key, $semantic, 'execution.admission', $selected, $inputDigest, originalExecutionBindingRef: $binding->rootOperationRef);

            return $this->runner->run($spec, function (TransactionContext $ctx) use ($plan, $intent, $reason, $capability, $credentials, $action): ExecutionReceipt {
                if ($capability !== null) {
                    $this->children->assertNamespaceOpen($ctx, $plan, 'Child identity history is incomplete or closed');
                    try {
                        $this->merchantGate->lock($ctx, $plan->scope, $plan->binding->merchantRevisionRef, $plan->kind->value, $credentials);
                    } catch (ProblemException $error) {
                        ChildAdmission::historicalAccessFailure($error, $action);
                    }
                }

                return $this->apply($ctx, $plan, $intent, $reason, $capability);
            });
        } catch (ProblemException $error) {
            if ($error->problem === ProblemCode::IdempotencyConflict) {
                $this->children->recordConflict($selected, new AdmissionConflict($scope, $wire::SCHEMA, $key, $semantic, $root->operationRef), $wire->commandKey, $wire->operationRef);
            }
            throw $error;
        }
    }

    private function apply(TransactionContext $ctx, AdmissionPlan $plan, string $intent, ?string $reason, ?CapabilitySnapshot $capability): ExecutionReceipt
    {
        $scope = $plan->scope;
        $original = $plan->binding->rootOperationRef;
        $this->children->lockOriginal($ctx, $plan, 'authorization');
        $root = $this->store->operation($scope, $original, $ctx);
        if ($root === null || $root->kind !== EffectKind::Authorize || ExecutionCodec::differs($root->binding, $plan->binding)) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Original authorization binding changed');
        }
        $existing = $this->store->claimChildBinding($ctx, $plan, $intent, $reason);
        if ($existing === null) {
            if ($capability === null) {
                throw new ProblemException(ProblemCode::IdempotencyConflict, 'Child replay has no matching retained identity');
            }
            AuthorizationPolicy::assertOpen($this->store->authorization($ctx, $original), new DateTimeImmutable);
            AuthorizationPolicy::assertAmount($plan->kind, $plan->amount, $capability, $this->capacity->current($ctx, $original));
            $this->children->insertChild($ctx, $plan);
        }

        return ChildAdmission::receipt($plan);
    }
}
