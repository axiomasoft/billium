<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Reducers;

use Billium\Collection\Data\CollectionStepView;
use Billium\Collection\Data\DunningCaseView;
use Billium\Collection\Data\DunningPolicyStepDefinition;
use Billium\Collection\Data\DunningPolicyView;
use Billium\Collection\Enums\CollectionStepStatus;
use Billium\Collection\Enums\DunningCaseStatus;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeImmutable;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DunningStepPlanner
{
    /**
     * Validates case plannability, existing step states, sequence, and retry budget.
     *
     * @param  list<CollectionStepView>  $existingSteps
     */
    public function validateSequence(
        DunningCaseView $case,
        DunningPolicyView $policy,
        array $existingSteps,
        int $stepNo,
    ): ?int {
        if ($case->status === DunningCaseStatus::ParkedUnknown) {
            throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.case_parked_unknown');
        }
        if (in_array($case->status, [
            DunningCaseStatus::Exhausted,
            DunningCaseStatus::Settled,
            DunningCaseStatus::Cancelled,
            DunningCaseStatus::Paused,
        ], true)) {
            throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.case_not_plannable');
        }

        $priorStep = null;
        foreach ($existingSteps as $step) {
            if ($step->status === CollectionStepStatus::Unknown) {
                throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.prior_step_unknown_case_parked');
            }
            if (in_array($step->status, [
                CollectionStepStatus::Planned,
                CollectionStepStatus::Authorized,
                CollectionStepStatus::Dispatched,
            ], true)) {
                throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.prior_step_in_flight');
            }
            if ($step->stepNo === $stepNo - 1) {
                $priorStep = $step;
            }
        }

        if ($stepNo === 1) {
            if ($existingSteps !== []) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.collection.step_sequence_invalid');
            }

            return null;
        }

        if ($priorStep === null) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.collection.prior_step_missing');
        }
        if ($priorStep->status !== CollectionStepStatus::FailedTerminal) {
            throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.prior_step_not_terminal_decline');
        }
        if ($priorStep->declineCategory === null || ! in_array($priorStep->declineCategory, $policy->retryableDeclineCategories, true)) {
            throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.decline_not_retryable');
        }
        if ($case->businessDeclineAttemptsUsed >= $case->maxBusinessDeclineAttempts) {
            throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.business_decline_budget_exhausted');
        }

        return $priorStep->id;
    }

    public function resolveStepDefinition(DunningPolicyView $policy, int $stepNo): DunningPolicyStepDefinition
    {
        foreach ($policy->orderedSteps as $definition) {
            if ($definition->stepNo === $stepNo) {
                return $definition;
            }
        }

        throw new ProblemException(ProblemCode::PolicyUnknown, 'billium.collection.policy_step_not_defined');
    }

    public function decide(
        DunningCaseView $case,
        DunningPolicyView $policy,
        DunningPolicyStepDefinition $stepDefinition,
        ?int $priorStepId,
        int $amountMinor,
        DateTimeImmutable $scheduledAt,
        bool $enforceOpenMinorCap,
    ): DunningStepPlanDecision {
        if ($enforceOpenMinorCap && $amountMinor > $case->currentOpenMinor) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.collection.amount_exceeds_open');
        }
        if ($policy->minOutstandingMinor > 0 && $amountMinor < $policy->minOutstandingMinor) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.collection.below_outstanding_threshold');
        }
        if ($scheduledAt > $case->recoveryDeadlineAt) {
            throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.recovery_horizon_exceeded');
        }

        return new DunningStepPlanDecision(
            priorStepId: $priorStepId,
            stepDefinition: $stepDefinition,
            amountMinor: $amountMinor,
        );
    }
}
