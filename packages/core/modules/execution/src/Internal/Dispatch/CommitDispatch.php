<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Dispatch;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\MerchantActionGate;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\DispatchDecision;
use Billium\Execution\Data\DispatchRequest;
use Billium\Execution\Data\DispatchTicket;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Enums\EffectKind;
use Billium\Execution\Enums\ExecutionPhase;
use Billium\Execution\Internal\Admission\CollectionChecks;
use Billium\Execution\Internal\Authority\AuthorityClaims;
use Billium\Execution\Internal\Authority\DispatchAuthority;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Platform\Contracts\Installation\Installation;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class CommitDispatch
{
    public function __construct(private OperationRunner $runner, private CallerContext $callers, private ExecutionStore $store, private Installation $installation,
        private DispatchAuthority $authority, private CollectionChecks $collection, private MerchantActionGate $merchants) {}

    /** @return array{DispatchTicket,DispatchDecision} */
    public function start(OperationRecord $operation, AttemptRecord $attempt, ResolvedCredentials $credentials): array
    {
        $binding = $operation->binding;
        $scope = $binding->scope;
        if ($binding->origin === null) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Original commercial basis must be verified before dispatch');
        }
        $session = $this->installation->currentSession($scope->installationRef) ?? throw new ProblemException(ProblemCode::RecoveryRequired, 'No adopted session');
        $request = new DispatchRequest($scope, $operation->operationRef, $attempt->attemptRef, $attempt->providerReference,
            $operation->kind, $operation->requested, AuthorityClaims::bindingDigest($binding), $binding->capabilityDigest,
            $session->ownerSessionRef, $session->epoch, $session->authorityEpoch, $session->deploymentGeneration, $session->attachmentGeneration,
            $attempt->leaseFence, 'billium.psp_dispatch.v1');
        $ticket = $this->authority->prepare($request);
        $plan = $this->collection->plan($binding->collectionAuthorizationRef, ExecutionPhase::Dispatch, $scope);
        $class = match ($operation->kind) {
            EffectKind::Charge, EffectKind::Authorize => $plan->operationClass ?? ModuleOperationClass::NewIntent,
            EffectKind::Capture, EffectKind::VoidAuthorization => ModuleOperationClass::FrozenIntentCompletion,
            EffectKind::Refund => ModuleOperationClass::OriginalOwnerRefund,
        };
        $modules = $this->collection->modules($plan);
        $spec = new OperationSpec('execution.start_dispatch', $scope->sellerRef, $scope->environment, FinancialMode::Active, $class, $modules,
            commitKind: 'execution.dispatch', financialScope: PaymentIdentity::select($this->callers->current(), $scope, $binding->executionProfile));
        $decision = null;
        $this->runner->run($spec, function (TransactionContext $ctx) use ($scope, $operation, $attempt, $ticket, $plan, $credentials, &$decision): ExecutionReceipt {
            $purpose = match ($operation->kind) {
                EffectKind::Authorize, EffectKind::Charge => 'new',
                default => $operation->kind->value,
            };
            $this->merchants->lock($ctx, $scope, $operation->binding->merchantRevisionRef, $purpose, $credentials);
            $execution = $this->store->plan($scope, $operation->operationRef);
            $this->collection->validate($ctx, $plan, $execution, $operation->operationRef, $operation->binding->semanticDigest);
            $this->store->lock($ctx, $execution);
            if ($this->store->capacity($ctx, $operation->rootOperationRef)->financialHold) {
                throw new ProblemException(ProblemCode::OperationFinancialHold, 'Root capacity has an active financial hold');
            }
            $decision = $this->collection->permit($ctx, $plan, $this->authority->validate($ctx, $ticket, $operation, $attempt));
            if (! $this->store->startDispatch($ctx, $decision)) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Dispatch-start compare and set failed');
            }

            return new ExecutionReceipt($ticket->ticketRef, $attempt->attemptRef, $operation->operationRef, $operation->binding->semanticDigest, 'dispatching', false, []);
        })->orThrow();

        return [$ticket, $decision ?? throw new LogicException('Missing committed dispatch decision')];
    }
}
