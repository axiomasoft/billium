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
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
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
use Billium\Execution\Enums\ExecutionPhase;
use Billium\Execution\Internal\Admission\ChildAdmission;
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
        private ChildAdmission $children,
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
        PaymentIdentity::assertText('Refund identity contains a null byte', $source, $intent, $wire->operationRef, $wire->originalOperationRef,
            $wire->reasonCode, $wire->category, $scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace);
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
        $semantic = PaymentIdentity::digest(['schema' => 'billium.execution.refund-semantics.v1', 'scope' => get_object_vars($scope),
            'originalBinding' => $binding->semanticDigest, 'terms' => PaymentIdentity::terms($wire->raw)]);
        $key = PaymentIdentity::commandKey($scope, EffectKind::Refund->value, $source, $wire->commandKey);
        $retained = $this->store->childByIdentity($scope, EffectKind::Refund, $source, $intent) ?? $this->store->operation($scope, $wire->operationRef);
        try {
            $inputDigest = CommandInputReplay::digest($wire);
            $cached = $this->inputReplay->find($selected, RequestRefund::SCHEMA, $key, $inputDigest);
            if ($cached !== null) {
                return $cached;
            }
            $replay = $retained !== null || $this->receipts->byCommandKey($selected, RequestRefund::SCHEMA, $key) !== null;
            $guard = $credentials = $capability = null;
            if (! $replay) {
                $this->readiness->assertReady($binding, EffectKind::Refund, $wire->money);
                $merchant = $this->merchants->historical($scope, $root->operationRef);
                [, $capability] = $this->adapters->select($merchant, EffectKind::Refund, $wire->money->currency->code);
                $credentials = ChildAdmission::historicalCredentials('refund', fn (): ResolvedCredentials => $this->credentials->resolve($merchant, 'refund'));
                $guard = $this->collection->plan($binding->collectionAuthorizationRef, ExecutionPhase::Admission, $scope);
            }
            $modules = $this->collection->modules($guard);
            $plan = new AdmissionPlan($scope, $binding, $wire->operationRef, EffectKind::Refund, $wire->money, $wire->commandKey,
                $semantic, $binding->canonicalizationVersion, $modules, $guard->locks ?? [], $guard);
            $spec = new OperationSpec(RequestRefund::SCHEMA, $scope->sellerRef, $scope->environment, FinancialMode::Active,
                $replay ? ModuleOperationClass::ReceiptReplay : ModuleOperationClass::OriginalOwnerRefund,
                $modules, $key, $semantic, 'execution.admission', $selected, $inputDigest, originalExecutionBindingRef: $binding->rootOperationRef);

            return $this->runner->run($spec, fn (TransactionContext $ctx): ExecutionReceipt => $this->apply($ctx, $plan, $intent, $wire, $capability, $credentials));
        } catch (ProblemException $error) {
            if ($error->problem === ProblemCode::IdempotencyConflict) {
                $authorized = $retained !== null && get_object_vars($retained->binding->scope) === get_object_vars($scope)
                    && $retained->binding->executionProfile === $selected->sourceAuthority ? $retained->operationRef : null;
                $this->children->recordConflict($selected, new AdmissionConflict($scope, RequestRefund::SCHEMA, $key, $semantic, $authorized), $wire->commandKey, $wire->operationRef);
            }
            throw $error;
        }
    }

    private function apply(TransactionContext $ctx, AdmissionPlan $plan, string $intent, RequestRefund $wire, ?CapabilitySnapshot $capability, ?ResolvedCredentials $credentials): ExecutionReceipt
    {
        $scope = $plan->scope;
        $original = $plan->binding->rootOperationRef;
        if ($capability !== null && $credentials !== null) {
            $this->children->assertNamespaceOpen($ctx, $plan, 'Refund identity history is closed or incomplete');
            try {
                $this->merchantGate->lock($ctx, $scope, $plan->binding->merchantRevisionRef, 'refund', $credentials);
            } catch (ProblemException $error) {
                ChildAdmission::historicalAccessFailure($error, 'refund');
            }
        }
        $this->children->lockOriginal($ctx, $plan, 'payment');
        $root = $this->store->operation($scope, $original, $ctx);
        if ($root === null || ExecutionCodec::differs($root->binding, $plan->binding)) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Original payment binding changed');
        }
        $existing = $this->store->claimChildBinding($ctx, $plan, $intent, $wire->reasonCode, $wire->category);
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
            $this->children->insertChild($ctx, $plan);
        }

        return ChildAdmission::receipt($plan);
    }
}
