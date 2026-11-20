<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Services;

use Billium\Collection\Contracts\CollectionParticipants;
use Billium\Collection\Contracts\ConsentBindingService;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\CollectionStepView;
use Billium\Collection\Data\ConsentChargeEligibilityRequest;
use Billium\Collection\Data\TransitionCollectionStep;
use Billium\Collection\Enums\CollectionStepStatus;
use Billium\Collection\Enums\ConsentGuardStatus;
use Billium\Collection\Enums\ConsentPurpose;
use Billium\Collection\Enums\RecurringOwnerMode;
use Billium\Collection\Enums\RecurringOwnerStatus;
use Billium\Collection\Internal\CollectionReceipt;
use Billium\Collection\Internal\Queries\DatabaseDunningQueries;
use Billium\Collection\Internal\Support\RowCast;
use Billium\Contracts\Data\Envelopes\CancelOperation;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runner\LockScope;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\CollectionGuardPlan;
use Billium\Execution\Data\CollectionGuardReceipt;
use Billium\Execution\Data\DispatchDecision;
use Billium\Execution\Data\ExecutionLockPlan;
use Billium\Execution\Data\ExecutionScope;
use Billium\Execution\Data\OperationRecord;
use Billium\Execution\Data\OriginDecision;
use Billium\Execution\Enums\AttemptState;
use Billium\Execution\Enums\ExecutionPhase;
use Billium\Ledger\Contracts\CollectionObligation;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\SettlementIntentLocks;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\CloseSettlementIntentNoEffect;
use Billium\Ledger\Data\Settlements\NoEffectProof;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class DatabaseCollectionParticipants implements CollectionParticipants
{
    /** @param Closure(): RootPaymentCommands $payments */
    public function __construct(
        private DatabaseManager $databases,
        private BilliumOptions $options,
        private CallerContext $callers,
        private OperationRunner $runner,
        private ConsentBindingService $consents,
        private CollectionObligation $obligations,
        private SettlementIntentLocks $intentLocks,
        private LedgerParticipants $ledger,
        private ExecutionStore $execution,
        private Closure $payments,
    ) {}

    public function findOrigin(ExecutionScope $scope, string $operationRef, string $businessIntentRef, ?TransactionContext $context = null): ?OriginDecision
    {
        if ($context !== null) {
            $this->runner->assertCurrent($context, 'execution');
        }
        $row = $this->authorizations($scope, $context)->where(static fn (Builder $query): Builder => $query
            ->where('operation_ref', $operationRef)->orWhere('business_intent_ref', $businessIntentRef))->first();
        if ($row === null) {
            return null;
        }
        if ($row->operation_ref !== $operationRef || $row->business_intent_ref !== $businessIntentRef || $row->intent_owner_namespace !== $scope->intentOwnerNamespace) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'Collection identity has different original terms');
        }

        return new OriginDecision($scope, $operationRef, $businessIntentRef, self::SOURCE_CONTEXT,
            RowCast::str($row->execution_profile, 'execution_profile'), RowCast::integer($row->routing_epoch, 'routing_epoch'),
            RowCast::str($row->authorization_ref, 'authorization_ref'), RowCast::str($row->basis_digest, 'basis_digest'),
            RowCast::str($row->policy_version, 'policy_version'), null);
    }

    public function plan(string $authorizationRef, ExecutionPhase $phase, ExecutionScope $scope): CollectionGuardPlan
    {
        $row = $this->authorization($scope, $authorizationRef);
        $locks = self::ownerLocks($row);
        $locks[] = $this->intentLocks->plan(self::account($row), RowCast::str($row->business_intent_ref, 'business_intent_ref'));

        return new CollectionGuardPlan($authorizationRef, RowCast::integer($row->issuance_generation, 'issuance_generation'),
            RowCast::utcDateTime($row->issued_at, 'issued_at'), $scope, ['collection', 'ledger'],
            ModuleOperationClass::FrozenIntentCompletion, $locks, RowCast::str($row->basis_digest, 'basis_digest'));
    }

    public function validate(TransactionContext $ctx, CollectionGuardPlan $plan, string $operationRef, string $businessDigest): CollectionGuardReceipt
    {
        [$db, $row, $step] = $this->locked($ctx, $plan);
        $consentRef = RowCast::str($row->consent_ref, 'consent_ref');
        $scope = new CollectionScope($plan->scope->sellerRef, $plan->scope->environment);
        $guard = $this->consents->lockGuard($ctx, $scope, $consentRef);
        $generation = RowCast::integer($row->cancellation_generation, 'cancellation_generation');
        $amount = RowCast::integer($row->amount_minor, 'amount_minor');
        $currency = RowCast::str($row->currency, 'currency');
        $reason = $generation === $guard->cancellationGeneration ? 'collection_reservation_unavailable' : 'cancellation_generation_changed';
        if ($step->status === CollectionStepStatus::Authorized && $row->operation_ref === $operationRef && $row->business_digest === $businessDigest
            && $row->financial_epoch === $ctx->sessionEpoch() && $generation === $guard->cancellationGeneration
            && ! $db->table('billium_collection_dispatch_permit')->where('step_id', $step->id)->exists()) {
            $verdict = $this->consents->evaluateChargeEligibility(new ConsentChargeEligibilityRequest(
                $scope, RowCast::str($row->billing_account_ref, 'billing_account_ref'), $consentRef, RowCast::integer($row->consent_version, 'consent_version'),
                RowCast::str($row->merchant_account_ref, 'merchant_account_ref'), RowCast::str($row->merchant_revision_ref, 'merchant_revision_ref'),
                $step->stepNo === 1 ? ConsentPurpose::RecurringCharge : ConsentPurpose::DunningRetry,
                $currency, $amount, new DateTimeImmutable, expectedCancellationGeneration: $generation, authorizationRef: $plan->authorizationRef,
                businessPaymentIntentRef: $step->businessPaymentIntentRef,
            ), $ctx);
            $reason = $verdict->permitted ? null : ($verdict->denialCode ?? 'consent_refused');
            if ($reason === null) {
                $binding = $db->table('billium_collection_consent_binding')->where('id', $row->consent_binding_id)->first();
                $case = $db->table('billium_collection_dunning_case')->where('id', $row->case_id)->first();
                $componentRef = $db->table('billium_collection_recurring_owner')->where('id', $row->recurring_owner_id)
                    ->where('seller_ref', $scope->sellerRef)->where('environment', $scope->environment)
                    ->where('status', RecurringOwnerStatus::Active->value)->where('owner_mode', RecurringOwnerMode::BilliumScheduler->value)->value('component_ref');
                if ($binding === null || $case === null || $case->status !== 'active' || $case->active_step_no !== $step->stepNo
                    || $case->cancellation_generation !== $generation || new DateTimeImmutable > RowCast::utcDateTime($case->recovery_deadline_at, 'recovery_deadline_at')) {
                    $reason = 'collection_case_changed';
                } elseif (! is_string($componentRef)) {
                    $reason = 'collection_owner_changed';
                } else {
                    $dueOnly = $db->table('billium_collection_work')->where('step_id', $step->id)->value('automatic_due') === true;
                    if ($this->obligations->lockCollectibleMinor($ctx, self::account($row), RowCast::str($row->obligation_ref, 'obligation_ref'),
                        $step->businessPaymentIntentRef, $currency, RowCast::str($binding->customer_ref, 'customer_ref'),
                        $componentRef, $dueOnly ? new DateTimeImmutable : null) < $amount) {
                        $reason = 'collection_balance_changed';
                    }
                }
            }
        }

        return new CollectionGuardReceipt($plan->authorizationRef, $guard->guardRevision, (string) RowCast::integer($row->consent_version, 'consent_version'),
            $guard->cancellationGeneration, $businessDigest, $operationRef, $reason === null, $reason);
    }

    public function markDispatch(TransactionContext $ctx, CollectionGuardPlan $plan, DispatchDecision $decision): string
    {
        [$db, $row, $step] = $this->locked($ctx, $plan);
        if ($ctx->spec()->commandKind !== 'execution.start_dispatch' || $step->status !== CollectionStepStatus::Authorized
            || $decision->operationRef !== $step->operationRef || $decision->sessionEpoch !== $row->financial_epoch) {
            throw new ProblemException(ProblemCode::OperationFinancialHold, 'Collection dispatch authority changed');
        }
        $ref = 'collection-permit:'.hash('sha256', $plan->planDigest);
        $db->table('billium_collection_dispatch_permit')->insert([
            'step_id' => $step->id, 'permit_ref' => $ref, 'attempt_ref' => $decision->attemptRef,
            'lease_fence' => $decision->leaseFence, 'request_digest' => $decision->immutableRequestDigest,
        ]);
        $db->table('billium_collection_step')->where('id', $step->id)->update([
            'status' => CollectionStepStatus::Dispatched->value, 'step_revision' => $step->stepRevision + 1,
            'updated_at' => RowCast::formatUtc(new DateTimeImmutable),
        ]);

        return $ref;
    }

    public function closeUnsent(TransactionContext $ctx, CollectionGuardPlan $plan, OperationRecord $operation, string $executionCancelReceiptRef): string
    {
        [$db, $row, $step] = $this->locked($ctx, $plan);
        $attempt = $this->execution->attemptForOperation($plan->scope, $operation->operationRef, $ctx);
        if ($operation->operationRef !== $step->operationRef || $operation->binding->semanticDigest !== $row->business_digest
            || $attempt?->state !== AttemptState::Cancelled || $attempt->collectionDispatchPermitRef !== null) {
            throw new ProblemException(ProblemCode::OperationNotCancellable, 'Execution has not proved unsent cancellation');
        }

        return $this->close($ctx, $db, $row, $step, 'not_dispatched', $executionCancelReceiptRef);
    }

    public function closeRevoked(ExecutionScope $scope, string $authorizationRef): string
    {
        $plan = $this->plan($authorizationRef, ExecutionPhase::Cancellation, $scope);
        $row = $this->authorization($scope, $authorizationRef);
        $businessDigest = RowCast::str($row->business_digest, 'business_digest');
        $operationRef = RowCast::str($row->operation_ref, 'operation_ref');
        $outcome = $this->runner->run(new OperationSpec(
            'collection.close_revoked.v1', $scope->sellerRef, $scope->environment, FinancialMode::Active,
            ModuleOperationClass::FrozenIntentCompletion, ['platform', 'ledger', 'execution', 'collection'],
            commitKind: 'collection.cancel',
            financialScope: new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace,
                RowCast::str($row->execution_profile, 'execution_profile')),
        ), function (TransactionContext $ctx) use ($plan, $operationRef, $businessDigest): CollectionReceipt {
            [$db, $row, $step] = $this->locked($ctx, $plan);
            $closure = $db->table('billium_collection_no_effect')->where('step_id', $step->id)->value('closure_ref');
            if (is_string($closure)) {
                return new CollectionReceipt('collection.step.cancelled', ['closureRef' => $closure]);
            }
            $this->assertUnsent($db, $step);
            $guard = $this->consents->lockGuard($ctx, new CollectionScope($step->sellerRef, $step->environment), RowCast::str($row->consent_ref, 'consent_ref'));
            $evidence = $guard->status === ConsentGuardStatus::Active ? null : $guard->revocationReceiptRef;
            if ($evidence === null) {
                $verdict = $this->validate($ctx, $plan, $operationRef, $businessDigest);
                if ($verdict->permitted || $verdict->reason === 'collection_reservation_unavailable') {
                    throw new ProblemException(ProblemCode::OperationNotCancellable, 'Collection has no current no-effect refusal');
                }
                $evidence = 'collection-guard:'.hash('sha256', $plan->planDigest.':'.$guard->guardRevision.':'.$verdict->reason);
            }
            if ($this->execution->operation($plan->scope, $operationRef, $ctx) !== null) {
                return new CollectionReceipt('collection.cancel.requires_execution', []);
            }

            $closure = $this->close($ctx, $db, $row, $step, 'not_admitted', $evidence);

            return new CollectionReceipt('collection.step.cancelled', ['closureRef' => $closure]);
        })->orThrow();
        if (is_string($outcome->body['closureRef'] ?? null)) {
            return $outcome->body['closureRef'];
        }

        ($this->payments)()->cancel($scope, CancelOperation::fromRaw((object) [
            'schema' => CancelOperation::SCHEMA, 'commandKey' => CollectionAdmission::commandKey($authorizationRef, 'cancel'),
            'installationRef' => $scope->installationRef, 'sellerRef' => $scope->sellerRef, 'environment' => $scope->environment,
            'operationRef' => $operationRef,
        ]))->orThrow();

        return RowCast::str($this->databases->connection($this->options->connection)->table('billium_collection_no_effect')
            ->where('step_id', $row->step_id)->value('closure_ref'), 'closure_ref');
    }

    public function transitionScope(TransitionCollectionStep $command): ?FinancialScope
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        $row = $this->databases->connection($this->options->connection)->table('billium_collection_authorization as a')
            ->join('billium_collection_step as s', 's.id', '=', 'a.step_id')
            ->where('a.installation_ref', $caller->installationRef)->where('a.seller_ref', $caller->sellerRef)
            ->where('a.environment', $caller->environment)->where('s.case_ref', $command->caseRef)->where('s.step_ref', $command->stepRef)
            ->first(['a.intent_owner_namespace', 'a.execution_profile']);

        return $row === null ? null : new FinancialScope($caller->installationRef, $caller->sellerRef, $caller->environment,
            RowCast::str($row->intent_owner_namespace, 'intent_owner_namespace'), RowCast::str($row->execution_profile, 'execution_profile'));
    }

    public function prelockOutcome(TransactionContext $ctx, ExecutionScope $scope, string $operationRef): ExecutionLockPlan
    {
        $plan = $this->execution->plan($scope, $operationRef, context: $ctx);
        $ctx->lock(...array_filter($plan->locks, static fn (LockRequest $lock): bool => $lock->rank === LockRank::FeedPartition));

        return $plan;
    }

    public function checkTransition(TransactionContext $ctx, CollectionStepView $step, TransitionCollectionStep $command,
        ?int $executionRevision, ?ExecutionLockPlan $plan): ?int
    {
        $db = $this->databases->connection($ctx->connectionName());
        $row = $db->table('billium_collection_authorization')->where('step_id', $step->id)->first();
        if ($row === null) {
            return null;
        }
        $scope = new ExecutionScope(RowCast::str($row->installation_ref, 'installation_ref'), $step->sellerRef, $step->environment,
            RowCast::str($row->intent_owner_namespace, 'intent_owner_namespace'));
        if ($command->targetStatus === CollectionStepStatus::Unknown) {
            if ($plan !== null) {
                $this->execution->lock($ctx, $plan);
                $operation = $this->execution->operation($scope, $step->operationRef, $ctx);
                if ($operation === null || in_array($operation->state, ['succeeded', 'failed', 'cancelled'], true)) {
                    throw new ProblemException(ProblemCode::RecoveryRequired, 'Collection outcome changed before parking');
                }
            }

            return null;
        }
        $state = match ($command->targetStatus) {
            CollectionStepStatus::Succeeded => 'succeeded',
            CollectionStepStatus::FailedTerminal => 'failed',
            default => throw new ProblemException(ProblemCode::OperationNotCancellable, 'Use the execution guard for an authorized step'),
        };
        $open = null;
        if ($plan !== null) {
            $customer = $db->table('billium_collection_consent_binding')->where('id', $row->consent_binding_id)->value('customer_ref');
            $open = $this->obligations->lockOutstandingMinor($ctx, self::account($row), RowCast::str($row->obligation_ref, 'obligation_ref'),
                $step->businessPaymentIntentRef, $step->currency, RowCast::str($customer, 'customer_ref'));
            $this->execution->lock($ctx, $plan);
        }
        $operation = $this->execution->operation($scope, $step->operationRef, $ctx);
        if ($operation === null || $operation->state !== $state || $operation->binding->semanticDigest !== $row->business_digest
            || ($executionRevision !== null && $operation->revision !== $executionRevision)
            || ! $this->obligations->outcomeApplied($ctx, self::account($row), $step->businessPaymentIntentRef, $step->operationRef,
                RowCast::str($row->execution_profile, 'execution_profile'), $operation->revision, $state)) {
            throw new ProblemException($executionRevision === null ? ProblemCode::OperationFinancialHold : ProblemCode::RecoveryRequired,
                'Collection outcome is unresolved or awaiting ledger application');
        }

        return $open;
    }

    /** @return array{Connection, stdClass, CollectionStepView} */
    private function locked(TransactionContext $ctx, CollectionGuardPlan $plan): array
    {
        $this->runner->assertCurrent($ctx, 'collection');
        $db = $this->databases->connection($ctx->connectionName());
        $row = $this->authorization($plan->scope, $plan->authorizationRef, $ctx);
        if ($row->basis_digest !== $plan->planDigest) {
            throw new LockPlanChanged('Collection authorization identity changed');
        }
        $locks = [...self::ownerLocks($row), ...$plan->locks];
        $ctx->lock(...$locks);
        foreach ($locks as $lock) {
            if ($ctx->lock($lock) !== $lock->keys) {
                throw new LockPlanChanged('Collection guard lock set changed');
            }
        }
        $step = $db->table('billium_collection_step')->where('id', $row->step_id)->first()
            ?? throw new LockPlanChanged('Authorized collection step disappeared');

        return [$db, $row, DatabaseDunningQueries::hydrateStep($step)];
    }

    private function close(TransactionContext $ctx, Connection $db, stdClass $row, CollectionStepView $step, string $kind, string $evidence): string
    {
        $this->assertUnsent($db, $step);
        $authorization = RowCast::str($row->authorization_ref, 'authorization_ref');
        $ref = 'collection-close:'.hash('sha256', RowCast::str($row->basis_digest, 'basis_digest'));
        $receipt = $this->ledger->closeSettlementIntentNoEffect($ctx, new CloseSettlementIntentNoEffect(
            self::account($row), $step->businessPaymentIntentRef, 1, $authorization, $ref, $step->currency, 'collection_cancelled',
            new NoEffectProof($ctx->rootRef(), $step->businessPaymentIntentRef, 1, $authorization, $kind, $evidence),
        ));
        $db->table('billium_collection_no_effect')->insert([
            'step_id' => $step->id, 'closure_ref' => $ref, 'ledger_receipt_ref' => RowCast::str($receipt->body()['receipt_ref'] ?? null, 'receipt_ref'),
            'evidence_ref' => $evidence, 'proof_kind' => $kind,
        ]);
        $now = RowCast::formatUtc(new DateTimeImmutable);
        $db->table('billium_collection_step')->where('id', $step->id)->update([
            'status' => 'cancelled', 'step_revision' => $step->stepRevision + 1, 'terminal_at' => $now, 'updated_at' => $now,
        ]);
        $db->table('billium_collection_dunning_case')->where('id', $step->dunningCaseId)->update([
            'status' => 'cancelled', 'case_revision' => $db->raw('case_revision + 1'), 'active_step_no' => null,
            'next_attempt_at' => null, 'closed_reason' => 'collection_cancelled', 'closed_at' => $now, 'updated_at' => $now,
        ]);

        return $ref;
    }

    private function assertUnsent(Connection $db, CollectionStepView $step): void
    {
        if ($step->status !== CollectionStepStatus::Authorized || $db->table('billium_collection_dispatch_permit')->where('step_id', $step->id)->exists()) {
            throw new ProblemException(ProblemCode::OperationNotCancellable, 'Collection might have an external effect');
        }
    }

    private function authorization(ExecutionScope $scope, string $ref, ?TransactionContext $context = null): stdClass
    {
        return $this->authorizations($scope, $context)->where('intent_owner_namespace', $scope->intentOwnerNamespace)->where('authorization_ref', $ref)->first()
            ?? throw new ProblemException(ProblemCode::ReferenceUnknown, 'Unknown collection authorization');
    }

    private function authorizations(ExecutionScope $scope, ?TransactionContext $ctx = null): Builder
    {
        $caller = $ctx?->caller() ?? $this->callers->current();
        if ($scope->installationRef !== $caller->installationRef || $scope->sellerRef !== $caller->sellerRef
            || $scope->environment !== $caller->environment || ! in_array($scope->intentOwnerNamespace, $caller->intentOwnerNamespaces, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'Collection scope is not authenticated');
        }

        return $this->databases->connection($ctx?->connectionName() ?? $this->options->connection)->table('billium_collection_authorization')
            ->where('seller_ref', $scope->sellerRef)->where('environment', $scope->environment)->where('installation_ref', $scope->installationRef);
    }

    /** @return list<LockRequest> */
    private static function ownerLocks(stdClass $row): array
    {
        $locks = [
            new LockRequest(LockRank::DunningCase, 'billium_collection_consent_guard', [RowCast::integer($row->consent_guard_id, 'consent_guard_id')],
                scope: LockScope::Seller, segment: DatabaseConsentBindingService::CONSENT_GUARD_LOCK_SEGMENT),
            new LockRequest(LockRank::DunningCase, 'billium_collection_dunning_case', [RowCast::integer($row->case_id, 'case_id')],
                scope: LockScope::Seller, segment: DatabaseDunningCaseService::DUNNING_CASE_LOCK_SEGMENT),
        ];
        if ($row->recurring_owner_id !== null) {
            $locks[] = new LockRequest(LockRank::DunningCase, 'billium_collection_recurring_owner', [RowCast::integer($row->recurring_owner_id, 'recurring_owner_id')],
                scope: LockScope::Seller, segment: DatabaseDunningPolicyService::RECURRING_OWNER_LOCK_SEGMENT);
        }

        return $locks;
    }

    private static function account(stdClass $row): BillingAccountScope
    {
        return new BillingAccountScope(RowCast::str($row->seller_ref, 'seller_ref'), RowCast::str($row->environment, 'environment'),
            RowCast::str($row->billing_account_ref, 'billing_account_ref'));
    }
}
