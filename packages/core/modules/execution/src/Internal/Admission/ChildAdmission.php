<?php

declare(strict_types=1);

namespace Billium\Execution\Internal\Admission;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Execution\Contracts\CapacityParticipants;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Data\AdmissionConflict;
use Billium\Execution\Data\AdmissionPlan;
use Billium\Execution\Data\ExecutionReceipt;
use Billium\Execution\Data\ResolvedCredentials;
use Billium\Execution\Enums\NamespaceState;
use Billium\Execution\Internal\Identity\PaymentIdentity;
use Closure;
use Packman\Attributes\Internal;
use RuntimeException;

/**
 * Steps shared by every admission of a child operation (capture, provider void, refund) and the
 * conflict audit shared with root admission, so their lock order and idempotency rules stay identical.
 */
#[Internal]
final readonly class ChildAdmission
{
    public function __construct(private OperationRunner $runner, private ExecutionStore $store, private CollectionChecks $collection, private CapacityParticipants $capacity) {}

    /**
     * A child acts on the original account, so missing or revoked historical credentials or permission
     * call for recovery rather than reading as a caller input error or an unclassified failure.
     *
     * @param  Closure(): ResolvedCredentials  $resolve
     */
    public static function historicalCredentials(string $action, Closure $resolve): ResolvedCredentials
    {
        try {
            return $resolve();
        } catch (ProblemException $error) {
            self::historicalAccessFailure($error, $action);
        } catch (RuntimeException) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Historical '.$action.' access could not be verified for the original account');
        }
    }

    public static function historicalAccessFailure(ProblemException $error, string $action): never
    {
        if (in_array($error->problem, [ProblemCode::ValidationInvalid, ProblemCode::LookupUnavailable], true)) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Historical '.$action.' credentials or permission are unavailable for the original account');
        }
        throw $error;
    }

    /** Durably audits an idempotency conflict under raw custody; the caller rethrows the conflict afterwards. */
    public function recordConflict(FinancialScope $selected, AdmissionConflict $conflict, string $commandKey, string $operationRef): void
    {
        $scope = $conflict->scope;
        $this->runner->run(new OperationSpec('execution.admission_conflict', $scope->sellerRef, $scope->environment, FinancialMode::RawCustody,
            ModuleOperationClass::CallbackCustody, ['execution', 'platform'], financialScope: $selected),
            fn (TransactionContext $ctx): ExecutionReceipt => new ExecutionReceipt($this->store->recordAdmissionConflict($ctx, $conflict), $commandKey,
                $operationRef, $conflict->semanticDigest, 'conflict_recorded', false, []))->orThrow();
    }

    /** A new child identity may only be claimed while the business namespace has complete, open history. */
    public function assertNamespaceOpen(TransactionContext $ctx, AdmissionPlan $plan, string $refusal): void
    {
        $namespace = $this->store->lockNamespace($ctx, $plan->scope);
        if ($namespace->state !== NamespaceState::Open || $namespace->completenessReceiptRef === null || $namespace->canonicalizationVersion !== $plan->canonicalizationVersion) {
            throw new ProblemException($namespace->state === NamespaceState::Closed ? ProblemCode::KeyExpired : ProblemCode::RecoveryRequired, $refusal);
        }
    }

    /**
     * Every monetary writer locks the original payment's observation partitions before its root and children.
     * That common prefix is established before discovering siblings added by other waiting admissions.
     */
    public function lockOriginal(TransactionContext $ctx, AdmissionPlan $plan, string $subject): void
    {
        $scope = $plan->scope;
        $original = $plan->binding->rootOperationRef;
        $locks = $this->store->plan($scope, $original, true, $ctx);
        $partition = array_find($locks->locks, static fn (LockRequest $lock): bool => $lock->rank === LockRank::FeedPartition);
        if ($partition === null) {
            throw new ProblemException(ProblemCode::RecoveryRequired, 'Original '.$subject.' has no retained observation partition');
        }
        if ($ctx->lock($partition) !== $partition->keys) {
            throw new LockPlanChanged('Original '.$subject.' observation partition changed during admission');
        }
        $locks = $this->store->plan($scope, $original, true, $ctx);
        $this->collection->validate($ctx, $plan->collectionGuard, $locks, $plan->operationRef, $plan->semanticDigest);
        $this->store->lock($ctx, $locks);
    }

    /** Inserts the admitted child with its capacity reservation, first attempt and dispatch work. */
    public function insertChild(TransactionContext $ctx, AdmissionPlan $plan): void
    {
        $scope = $plan->scope;
        $child = $this->store->insertOperation($ctx, $plan);
        $this->capacity->reserve($ctx, $plan->binding->rootOperationRef, $child->operationRef, $plan->kind, $plan->amount, PaymentIdentity::reference($scope, $child->operationRef, 'reservation'));
        $attempt = $this->store->insertAttempt($ctx, $child, PaymentIdentity::reference($scope, $child->operationRef, 'attempt'), PaymentIdentity::reference($scope, $child->operationRef, 'provider'));
        $this->store->insertDispatchWork($ctx, $attempt, PaymentIdentity::reference($scope, $child->operationRef, 'dispatch'), 'execution.dispatch.v1');
    }

    public static function receipt(AdmissionPlan $plan): ExecutionReceipt
    {
        return new ExecutionReceipt(PaymentIdentity::reference($plan->scope, $plan->operationRef, 'admission'), $plan->commandKey, $plan->operationRef,
            $plan->semanticDigest, 'admitted', false, [PaymentIdentity::reference($plan->scope, $plan->operationRef, 'attempt')]);
    }
}
