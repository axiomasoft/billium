<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Admission;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Modules\ModuleRegistry;
use Billium\Core\Contracts\Modules\ModuleStateReader;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Enums\Modules\ModuleLifecycleState;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Execution\Contracts\CollectionExecutionGuard;
use Billium\Execution\Data\CollectionGuardPlan;
use Billium\Execution\Data\DispatchDecision;
use Billium\Execution\Data\ExecutionLockPlan;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Enums\ExecutionPhase;
use Packman\Attributes\Internal;

#[Internal]
final readonly class CollectionChecks
{
    public function __construct(private ?CollectionExecutionGuard $guard, private ModuleRegistry $modules, private ModuleStateReader $states) {}

    /** @return list<string> */
    public function modules(?CollectionGuardPlan $plan): array
    {
        $modules = array_values(array_unique(['execution', 'platform', ...($plan->participatingModuleIds ?? [])]));
        for ($i = 0; $i < count($modules); $i++) {
            $descriptor = $this->modules->descriptor($modules[$i]) ?? throw new ProblemException(ProblemCode::RecoveryRequired, 'Unknown planned module');
            foreach ($descriptor->requiredModuleIds() as $dependency) {
                if (! in_array($dependency, $modules, true)) {
                    $modules[] = $dependency;
                }
            }
        }

        return $modules;
    }

    public function plan(?string $ref, ExecutionPhase $phase, ExecutionScope $scope): ?CollectionGuardPlan
    {
        if ($ref === null) {
            return null;
        }
        $plan = $this->guard()->plan($ref, $phase, $scope);
        if ($plan->authorizationRef !== $ref || get_object_vars($plan->scope) !== get_object_vars($scope) || $plan->issuanceGeneration < 1
            || ! in_array('collection', $plan->participatingModuleIds, true)
            || ! in_array($plan->operationClass, [ModuleOperationClass::NewIntent, ModuleOperationClass::FrozenIntentCompletion], true)) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Collection owner returned an incomplete or foreign plan');
        }
        $case = false;
        foreach ($plan->locks as $lock) {
            if ($lock->rank === LockRank::DunningCase) {
                $case = true;
            }
            if ($lock->rank->value <= LockRank::ModuleAdmission->value || $lock->rank->value >= LockRank::RootCharge->value
                || ($lock->rank === LockRank::SettlementIntent && ! in_array('ledger', $plan->participatingModuleIds, true))) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Collection lock plan is outside its declared module closure');
            }
        }
        if (! $case) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Collection authorization has no current consent/cancellation row');
        }

        return $plan;
    }

    public function prelock(TransactionContext $ctx, ?CollectionGuardPlan $guardPlan, ExecutionLockPlan $execution): void
    {
        if ($guardPlan === null) {
            return;
        }
        foreach ($guardPlan->participatingModuleIds as $id) {
            $ctx->assertParticipant($id);
        }
        $current = $this->states->read($ctx->caller()->installationRef, 'collection');
        if ($current === null || $current->generation !== $ctx->moduleGeneration('collection') || $guardPlan->issuanceGeneration > $current->generation
            || ($current->state === ModuleLifecycleState::Draining && ($guardPlan->operationClass !== ModuleOperationClass::FrozenIntentCompletion
                || $guardPlan->issuanceGeneration >= $current->generation))) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Collection step is outside its admitted issuance generation');
        }
        // Acquire both modules' lower-ranked rows as one sorted set before the owner checks current consent.
        $locks = $guardPlan->locks;
        foreach ($execution->locks as $lock) {
            if ($lock->rank === LockRank::FeedPartition) {
                $locks[] = $lock;
            }
        }
        $ctx->lock(...$locks);
        foreach ($locks as $lock) {
            if (count($ctx->lock($lock)) !== count($lock->keys)) {
                throw new LockPlanChanged('Collection participant row set changed');
            }
        }
    }

    public function validate(TransactionContext $ctx, ?CollectionGuardPlan $guardPlan, ExecutionLockPlan $execution, string $operation, string $digest): void
    {
        if ($guardPlan === null) {
            return;
        }
        $this->prelock($ctx, $guardPlan, $execution);
        $receipt = $this->guard()->validate($ctx, $guardPlan, $operation, $digest);
        if ($receipt->authorizationRef !== $guardPlan->authorizationRef || $receipt->operationRef !== $operation
            || $receipt->businessDigest !== $digest || $receipt->guardRevision < 1 || $receipt->cancellationGeneration < 0) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Collection owner returned a mismatched authorization receipt');
        }
        if (! $receipt->permitted) {
            throw new ProblemException(ProblemCode::OperationFinancialHold, 'Collection owner refused current consent or cancellation generation');
        }
    }

    public function permit(TransactionContext $ctx, ?CollectionGuardPlan $plan, DispatchDecision $decision): DispatchDecision
    {
        if ($plan === null) {
            return $decision;
        }
        $ref = $this->guard()->markDispatch($ctx, $plan, $decision);

        return new DispatchDecision($decision->operationRef, $decision->attemptRef, $decision->leaseFence,
            $decision->sessionEpoch, $decision->ticketRef, $decision->egressFenceRef, $ref, $decision->immutableRequestDigest, $decision->requestDeadlineAt);
    }

    private function guard(): CollectionExecutionGuard
    {
        return $this->guard ?? throw new ProblemException(ProblemCode::RecoveryRequired, 'Collection owner is unavailable');
    }
}
