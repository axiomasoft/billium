<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Admission;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Execution\Contracts\Collection\CollectionOriginLookup;
use Billium\Execution\Contracts\Collection\RootIdentityStore;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantActionGate;
use Billium\Execution\Data\AdmissionPlan;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Execution\Internal\Storage\ExecutionCodec;
use Packman\Attributes\Internal;

#[Internal]
final readonly class AdmitRoot
{
    public function __construct(
        private ExecutionStore $store,
        private RootIdentityStore $rootIdentities,
        private CollectionChecks $collection,
        private MerchantActionGate $merchants,
        private FinancialSessionAdmission $sessions,
    ) {}

    public function apply(TransactionContext $ctx, AdmissionPlan $plan, bool $replayOnly, ?CollectionOriginLookup $collectionOrigins): ExecutionReceipt
    {
        $namespace = $replayOnly ? $this->store->namespace($plan->scope, $ctx) : $this->store->lockNamespace($ctx, $plan->scope);
        if (! $replayOnly && ($namespace->state !== NamespaceState::Open || $namespace->completenessReceiptRef === null
            || $namespace->canonicalizationVersion !== $plan->canonicalizationVersion)) {
            throw new ProblemException($namespace->state === NamespaceState::Closed ? ProblemCode::KeyExpired : ProblemCode::RecoveryRequired,
                'Business history is closed or lacks current completeness evidence');
        }
        if (! $replayOnly) {
            $this->merchants->lock($ctx, $plan->scope, $plan->binding->merchantRevisionRef, 'new');
            $this->rootIdentities->lockRootIdentity($ctx, $plan->scope, $plan->operationRef, $plan->binding->businessIntentRef);
            // A late collection reservation would require a different guard plan and runner module closure.
            $origin = $collectionOrigins?->findOrigin($plan->scope, $plan->operationRef, $plan->binding->businessIntentRef, $ctx);
            if ($origin !== null && ExecutionCodec::encode($origin) !== ExecutionCodec::encode($plan->binding->origin)) {
                throw new ProblemException(ProblemCode::AuthDenied, 'Payment source differs from the reserved collection origin');
            }
            if ($origin === null && $plan->binding->sourceContext === CollectionOriginLookup::SOURCE_CONTEXT) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Collection command has no registered original authorization');
            }
            $token = $this->sessions->currentToken($plan->scope->installationRef)
                ?? throw new ProblemException(ProblemCode::RecoveryRequired, 'Initial partition requires the cached admitted owner token');
            $this->store->prepareObservationPartition($ctx, $plan->binding, AuthorityClaims::sourceLineage($token, $ctx->sessionEpoch()));
        }
        // Repeat discovery inside each runner attempt. A changed row set never adds a late lower-rank lock.
        $execution = $this->store->plan($plan->scope, $plan->operationRef, context: $ctx);
        $this->collection->validate($ctx, $plan->collectionGuard, $execution, $plan->operationRef, $plan->semanticDigest);
        $this->store->lock($ctx, $execution);
        $existing = $replayOnly ? ($this->store->operation($plan->scope, $plan->operationRef, $ctx)
            ?? throw new ProblemException(ProblemCode::RecoveryRequired, 'Previously admitted operation is unavailable')) : $this->store->insertBinding($ctx, $plan->binding);
        $attemptRef = PaymentIdentity::reference($plan->scope, $plan->operationRef, 'attempt');
        if ($existing === null) {
            $operation = $this->store->insertOperation($ctx, $plan);
            $attempt = $this->store->insertAttempt($ctx, $operation, $attemptRef, PaymentIdentity::reference($plan->scope, $plan->operationRef, 'provider'));
            $this->store->insertDispatchWork($ctx, $attempt, PaymentIdentity::reference($plan->scope, $plan->operationRef, 'dispatch'), 'execution.dispatch.v1');
        } elseif ($existing->kind !== $plan->kind || $existing->requested->toWire() !== $plan->amount->toWire()
            || ExecutionCodec::differs($existing->binding, $plan->binding) || $existing->operationRef !== $plan->operationRef) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'Payment intent already has different execution terms');
        }

        return new ExecutionReceipt(PaymentIdentity::reference($plan->scope, $plan->operationRef, 'admission'), $plan->commandKey,
            $plan->operationRef, $plan->semanticDigest, 'admitted', false, [$attemptRef], revision: 0, outcomeQueryRef: $plan->operationRef);
    }
}
