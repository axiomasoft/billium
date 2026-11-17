<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Dispatch;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\AttemptRecord;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\WorkState;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Billium\Platform\Contracts\Installation\Installation;
use DateTimeImmutable;
use LogicException;
use Packman\Attributes\Internal;

#[Internal]
final readonly class AttemptLeases
{
    public function __construct(private OperationRunner $runner, private CallerContext $callers, private ExecutionStore $store, private Installation $installation) {}

    public function claim(OperationRecord $operation, AttemptRecord $attempt, string $worker, DateTimeImmutable $until): AttemptRecord
    {
        $spec = $this->spec($operation, 'execution.claim_attempt', $attempt->state === AttemptState::Admitted);
        $claimed = null;
        $this->runner->run($spec, function (TransactionContext $ctx) use ($operation, $attempt, $worker, $until, &$claimed): ExecutionReceipt {
            $scope = $operation->binding->scope;
            $this->store->lock($ctx, $this->store->plan($scope, $operation->operationRef, context: $ctx));
            $current = $this->store->attempt($scope, $attempt->attemptRef, $ctx);
            if ($current === null || $current->state !== $attempt->state || $current->operationRef !== $operation->operationRef
                || ! in_array($current->state, [AttemptState::Admitted, AttemptState::Dispatching, AttemptState::Unknown, AttemptState::Pending], true)) {
                throw new ProblemException(ProblemCode::RecoveryRequired, 'Attempt changed before the worker lease');
            }
            $claimed = $this->store->claimAttempt($ctx, $current->attemptRef, $worker, $until);

            return new ExecutionReceipt($claimed->attemptRef, $worker, $operation->operationRef, $operation->binding->semanticDigest, 'leased', false, []);
        })->orThrow();

        return $claimed ?? throw new LogicException('Lease did not return a committed attempt');
    }

    public function finish(OperationRecord $operation, AttemptRecord $claim, string $evidenceRef, AttemptState $state, WorkState $work,
        ?DateTimeImmutable $dueAt, ?string $reason): StoredOutcome
    {
        return $this->runner->run($this->spec($operation, 'execution.finish_attempt', false),
            function (TransactionContext $ctx) use ($operation, $claim, $evidenceRef, $state, $work, $dueAt, $reason): ExecutionReceipt {
                $scope = $operation->binding->scope;
                $this->store->lock($ctx, $this->store->plan($scope, $operation->operationRef, context: $ctx));
                $updated = $this->store->finishAttempt($ctx, $claim->attemptRef, $claim->leaseFence, $state, $evidenceRef);
                if ($updated && ! $this->store->finishDispatchWork($ctx, $claim->attemptRef, $claim->leaseFence, $work, $dueAt, $reason)) {
                    throw new ProblemException(ProblemCode::RecoveryRequired, 'Dispatch work lease changed before completion');
                }

                return new ExecutionReceipt($evidenceRef, $claim->attemptRef, $operation->operationRef, $operation->binding->semanticDigest,
                    $updated ? $state->value : 'retained_stale', false, [$evidenceRef]);
            });
    }

    private function spec(OperationRecord $operation, string $kind, bool $outgoing): OperationSpec
    {
        $scope = $operation->binding->scope;
        $session = $this->installation->currentSession($scope->installationRef);
        $mode = ! $outgoing && $session?->state === 'recovery' ? FinancialMode::Recovery : FinancialMode::Active;

        return new OperationSpec($kind, $scope->sellerRef, $scope->environment, $mode, ModuleOperationClass::UnknownResolution, ['execution', 'platform'],
            commitKind: $kind, financialScope: PaymentIdentity::select($this->callers->current(), $scope, $operation->binding->executionProfile));
    }
}
