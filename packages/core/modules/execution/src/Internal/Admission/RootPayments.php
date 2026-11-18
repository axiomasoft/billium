<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Admission;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\AuthorizePayment;
use Billium\Contracts\Data\Envelopes\CancelOperation;
use Billium\Contracts\Data\Envelopes\ExecutePayment;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Contracts\CollectionCancellationParticipant;
use Billium\Execution\Contracts\ExecutionOriginResolver;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantRegistry;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\AdmissionConflict;
use Billium\Execution\Data\AdmissionPlan;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\FrozenBinding;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ExecutionPhase;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Internal\Dispatch\TransportBoundary;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Closure;
use Packman\Attributes\Internal;

#[Internal]
final readonly class RootPayments implements RootPaymentCommands
{
    /** @param Closure():ExecutionOriginResolver $origins */
    public function __construct(private OperationRunner $runner, private CallerContext $callers, private ExecutionStore $store,
        private Closure $origins, private MerchantRegistry $merchants, private QualifiedAdapter $adapters,
        private CollectionChecks $collection, private AdmitRoot $admission, private TransportBoundary $boundary,
        private ExecutionReadinessGuard $readiness, private CommandInputReplay $inputReplay, private ChildAdmission $children,
        private ?CollectionCancellationParticipant $cancellation = null) {}

    public function admit(ExecutionScope $scope, ExecutePayment|AuthorizePayment $command): StoredOutcome
    {
        $this->boundary->assertOutsideTransaction();
        $codec = new Codec;
        $wire = $codec->decode($codec->encode($command));
        if ((! $wire instanceof ExecutePayment && ! $wire instanceof AuthorizePayment) || $wire::class !== $command::class
            || ! is_string($wire->source->context ?? null) || ! is_string($wire->source->intentRef ?? null)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid root payment envelope');
        }
        PaymentIdentity::assertText('Payment identities must be representable as PostgreSQL text', $wire->source->context, $wire->source->intentRef,
            $wire->operationRef, $wire->bindingRevision, $wire->merchantAccountRef, $scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace);
        $this->assertAddress($scope, $wire);
        $selected = PaymentIdentity::select($this->callers->current(), $scope, $wire->executionProfile);
        try {
            return $this->admitValidated($scope, $wire, $selected);
        } catch (ProblemException $exception) {
            if ($exception->problem === ProblemCode::IdempotencyConflict) {
                $this->auditConflict($scope, $wire, $selected);
            }
            throw $exception;
        }
    }

    private function auditConflict(ExecutionScope $scope, ExecutePayment|AuthorizePayment $wire, FinancialScope $selected): void
    {
        $digest = PaymentIdentity::digest(['schema' => 'billium.execution.conflicting-terms.v1', 'scope' => get_object_vars($scope), 'terms' => PaymentIdentity::terms($wire->raw)]);
        $original = $this->store->operation($scope, $wire->operationRef);
        $authorized = $original?->binding->executionProfile === $selected->sourceAuthority ? $original?->operationRef : null;
        $record = new AdmissionConflict($scope, $wire::SCHEMA, PaymentIdentity::commandKey($scope, 'charge', $this->sourceContext($wire), $wire->commandKey), $digest, $authorized);
        $this->children->recordConflict($selected, $record, $wire->commandKey, $wire->operationRef);
    }

    private function sourceContext(ExecutePayment|AuthorizePayment $wire): string
    {
        return is_string($wire->source->context ?? null) ? $wire->source->context : throw new ProblemException(ProblemCode::ValidationInvalid, 'Missing validated source context');
    }

    private function admitValidated(ExecutionScope $scope, ExecutePayment|AuthorizePayment $wire, FinancialScope $selected): StoredOutcome
    {
        $inputDigest = CommandInputReplay::digest($wire);
        $key = PaymentIdentity::commandKey($scope, 'charge', $this->sourceContext($wire), $wire->commandKey);
        $cached = $this->inputReplay->find($selected, $wire::SCHEMA, $key, $inputDigest);
        if ($cached !== null) {
            return $cached;
        }
        $intent = is_string($wire->source->intentRef ?? null) ? $wire->source->intentRef : throw new ProblemException(ProblemCode::ValidationInvalid, 'Missing validated business intent');
        $existing = $this->store->operation($scope, $wire->operationRef);
        if ($existing === null && $this->store->namespace($scope)->state === NamespaceState::Closed) {
            // A recovery import can commit between the initial key read and observing namespace closure.
            $cached = $this->inputReplay->find($selected, $wire::SCHEMA, $key, $inputDigest);
            if ($cached !== null) {
                return $cached;
            }
            $existing = $this->store->operation($scope, $wire->operationRef);
            if ($existing === null) {
                throw new ProblemException(ProblemCode::KeyExpired, 'The closed business namespace has no retained original identity');
            }
        }
        $origin = $existing === null ? ($this->origins)()->resolve($scope, $wire->operationRef, $intent)
            : ($existing->binding->origin ?? throw new ProblemException(ProblemCode::RecoveryRequired, 'Stored original has no verified commercial basis'));
        if (get_object_vars($origin->scope) !== get_object_vars($scope) || $origin->operationRef !== $wire->operationRef || $origin->businessIntentRef !== $wire->source->intentRef
            || $origin->sourceContext !== $wire->source->context || $origin->executionProfile !== $wire->executionProfile || $origin->routingEpoch !== $wire->routingEpoch) {
            throw new ProblemException($existing === null ? ProblemCode::AuthDenied : ProblemCode::IdempotencyConflict, 'Payment source differs from the original commercial basis');
        }

        $kind = $wire instanceof ExecutePayment ? EffectKind::Charge : EffectKind::Authorize;
        if ($existing !== null && ($existing->kind !== $kind || $existing->binding->merchantRevisionRef !== $wire->bindingRevision
            || $existing->binding->merchantAccountRef !== $wire->merchantAccountRef)) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'Existing operation has different payment terms');
        }
        $merchant = $existing === null ? $this->merchants->forAdmission($scope, $wire->bindingRevision) : $this->merchants->historical($scope, $wire->operationRef);
        if ($merchant->scope->installationRef !== $scope->installationRef || $merchant->scope->environment !== $scope->environment
            || $merchant->scope->sellerRef !== $scope->sellerRef || $merchant->revisionRef !== $wire->bindingRevision || $merchant->merchantAccountRef !== $wire->merchantAccountRef) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Requested merchant differs from the authorized merchant revision');
        }
        if ($existing === null) {
            $this->adapters->select($merchant, $kind, $wire->money->currency->code);
        }
        $semantic = PaymentIdentity::semantic($scope, $wire, $merchant, $origin);
        $orderRef = $wire->source->orderRef ?? null;
        if ($orderRef !== null && ! is_string($orderRef)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Original order reference must be a string');
        }
        $binding = new FrozenBinding($scope, $wire->executionProfile, $origin->businessIntentRef, $wire->operationRef, $merchant->revisionRef,
            $merchant->merchantAccountRef, $merchant->driverId, $merchant->driverContractVersion, $merchant->capabilityDigest, $semantic,
            PaymentIdentity::VERSION, $origin->authorizationRef, $origin->sourceContext, $origin, $orderRef);
        $collection = $existing === null ? $this->collection->plan($origin->authorizationRef, ExecutionPhase::Admission, $scope) : null;
        $modules = $this->collection->modules($collection);
        if ($existing === null) {
            $this->readiness->assertReady($binding, $kind, $wire->money);
        }
        $plan = new AdmissionPlan($scope, $binding, $wire->operationRef, $kind, $wire->money, $wire->commandKey, $semantic, PaymentIdentity::VERSION,
            $modules, $collection->locks ?? [], $collection);
        $spec = new OperationSpec($wire::SCHEMA, $scope->sellerRef, $scope->environment, FinancialMode::Active,
            $existing === null ? ($collection->operationClass ?? ModuleOperationClass::NewIntent) : ModuleOperationClass::ReceiptReplay, $modules,
            PaymentIdentity::commandKey($scope, 'charge', $origin->sourceContext, $wire->commandKey), $semantic, 'execution.admission', $selected, $inputDigest,
            originalExecutionBindingRef: $binding->rootOperationRef);

        return $this->runner->run($spec, fn (TransactionContext $ctx): ExecutionReceipt => $this->admission->apply($ctx, $plan, $existing !== null));
    }

    public function cancel(ExecutionScope $scope, CancelOperation $command): StoredOutcome
    {
        $this->boundary->assertOutsideTransaction();
        $codec = new Codec;
        $wire = $codec->decode($codec->encode($command));
        if (! $wire instanceof CancelOperation) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Invalid local cancellation command');
        }
        $this->assertAddress($scope, $wire);
        $operation = $this->store->operation($scope, $wire->operationRef) ?? throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown operation in this scope');
        $binding = $operation->binding;
        $selected = PaymentIdentity::select($this->callers->current(), $scope, $binding->executionProfile);
        $key = PaymentIdentity::commandKey($scope, 'cancel', $binding->sourceContext, $wire->commandKey);
        $digest = PaymentIdentity::digest(['schema' => 'billium.execution.cancel.v1', 'operationRef' => $operation->operationRef, 'binding' => $binding->semanticDigest]);
        $guard = $operation->state === 'cancelled' ? null : $this->collection->plan($binding->collectionAuthorizationRef, ExecutionPhase::Cancellation, $scope);
        if ($guard !== null && $this->cancellation === null) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Collection cancellation owner is unavailable');
        }
        $spec = new OperationSpec(CancelOperation::SCHEMA, $scope->sellerRef, $scope->environment, FinancialMode::Active,
            ModuleOperationClass::FrozenIntentCompletion, $this->collection->modules($guard), $key, $digest, 'execution.cancel', $selected,
            originalExecutionBindingRef: $binding->rootOperationRef);

        return $this->runner->run($spec, function (TransactionContext $ctx) use ($scope, $operation, $wire, $digest, $guard): ExecutionReceipt {
            $execution = $this->store->plan($scope, $operation->operationRef);
            $this->collection->prelock($ctx, $guard, $execution);
            $this->store->lock($ctx, $execution);
            $attemptRef = PaymentIdentity::reference($scope, $operation->operationRef, 'attempt');
            $ref = PaymentIdentity::reference($scope, $operation->operationRef, 'cancellation');
            if ($operation->rootOperationRef !== $operation->operationRef || ! $this->store->cancelAdmitted($ctx, $operation, $attemptRef, $ref)) {
                throw new ProblemException(ProblemCode::OperationNotCancellable, 'Only an unsent root payment may be cancelled locally');
            }

            $evidence = [$attemptRef];
            if ($guard !== null) {
                $closure = $this->cancellation?->closeUnsent($ctx, $guard, $operation, $ref);
                if ($closure === null || $closure === '') {
                    throw new ProblemException(ProblemCode::RecoveryRequired, 'Collection owner did not return a closure receipt');
                }
                $evidence[] = $closure;
            }

            return new ExecutionReceipt($ref, $wire->commandKey, $operation->operationRef, $digest, 'cancelled', false, $evidence);
        });
    }

    private function assertAddress(ExecutionScope $scope, ExecutePayment|AuthorizePayment|CancelOperation $command): void
    {
        if ($command->installationRef !== $scope->installationRef || $command->sellerRef !== $scope->sellerRef || $command->environment !== $scope->environment) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Payment address differs from the authenticated selector');
        }
    }
}
