<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Reducers;

use Billium\Collection\Data\CollectionStepView;
use Billium\Collection\Data\DunningCaseView;
use Billium\Collection\Data\DunningPolicyStepDefinition;
use Billium\Collection\Data\DunningPolicyView;
use Billium\Collection\Data\TransitionCollectionStep;
use Billium\Collection\Enums\DunningCaseStatus;
use Billium\Collection\Internal\Support\RetryJitter;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeZone;
use Packman\Attributes\Internal;

#[Internal]
final readonly class DunningTransitionReducer
{
    /**
     * Pure deterministic transition decision for CollectionStepStatus::FailedTerminal.
     * Matches DatabaseDunningCaseService.php:1302-1384 literally (zero financial behavior change).
     *
     * @throws ProblemException billium.validation.invalid when decline category or evidence digest is missing
     */
    public function reduceTerminalDecline(
        DunningCaseView $case,
        CollectionStepView $step,
        DunningPolicyView $policy,
        TransitionCollectionStep $command,
        ?int $observedOpenMinor,
    ): DunningTransitionDecision {
        if ($command->declineCategory === null || $command->outcomeEvidenceDigest === null) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'billium.collection.terminal_decline_requires_category_and_evidence',
            );
        }

        $usedDeclines = $case->businessDeclineAttemptsUsed + 1;
        $isRetryable = in_array($command->declineCategory, $policy->retryableDeclineCategories, true);
        $budgetRemains = $usedDeclines < $case->maxBusinessDeclineAttempts;
        $candidateStepNo = $step->stepNo + 1;
        $nextDef = self::stepDefinition($policy, $candidateStepNo);

        $candidateEligibleAt = null;
        $withinHorizon = false;
        if ($nextDef !== null) {
            $scaledDelay = intdiv($nextDef->delaySeconds * $nextDef->backoffMultiplierBps, 10_000);
            $baseDelay = min($nextDef->maxBackoffSeconds, max($nextDef->delaySeconds, $scaledDelay));
            $jitter = RetryJitter::seconds($case->caseRef, $step->stepRef, $command->occurredAt, $nextDef->jitterMaxSeconds);
            $effectiveDelay = $baseDelay + min($jitter, max(0, $nextDef->maxBackoffSeconds - $baseDelay));
            $candidateEligibleAt = $command->occurredAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->modify('+'.$effectiveDelay.' seconds');
            $withinHorizon = $candidateEligibleAt <= $case->recoveryDeadlineAt;
        }

        $open = $observedOpenMinor ?? $case->currentOpenMinor;
        $aboveThreshold = $open > 0 && $open >= $policy->minOutstandingMinor;
        $lastCompleted = max($case->lastCompletedStepNo, $step->stepNo);

        if ($isRetryable && $budgetRemains && $nextDef !== null && $withinHorizon && $aboveThreshold) {
            return new DunningTransitionDecision(
                caseStatus: DunningCaseStatus::Open,
                currentOpenMinor: $open,
                businessDeclineAttemptsUsed: $usedDeclines,
                activeStepNo: null,
                lastCompletedStepNo: $lastCompleted,
                parkedReason: null,
                parkedStepRef: null,
                nextAttemptAt: $candidateEligibleAt,
                closedReason: null,
                closedAt: null,
                nextStepEligible: true,
                nextStepNo: $candidateStepNo,
            );
        }

        $closedReason = match (true) {
            ! $isRetryable => 'non_retryable_decline:'.$command->declineCategory->value,
            ! $budgetRemains => 'business_decline_budget_exhausted',
            $nextDef === null => 'policy_steps_exhausted',
            ! $withinHorizon => 'recovery_horizon_exceeded',
            default => 'below_outstanding_threshold',
        };

        return new DunningTransitionDecision(
            caseStatus: DunningCaseStatus::Exhausted,
            currentOpenMinor: $open,
            businessDeclineAttemptsUsed: $usedDeclines,
            activeStepNo: null,
            lastCompletedStepNo: $lastCompleted,
            parkedReason: null,
            parkedStepRef: null,
            nextAttemptAt: null,
            closedReason: $closedReason,
            closedAt: $command->occurredAt->setTimezone(new DateTimeZone('UTC')),
            nextStepEligible: false,
            nextStepNo: null,
        );
    }

    /**
     * Pure deterministic transition decision for CollectionStepStatus::Succeeded.
     *
     * @throws ProblemException billium.validation.invalid when evidence digest is missing or updatedOpenMinor exceeds currentOpenMinor
     */
    public function reduceSucceeded(
        DunningCaseView $case,
        CollectionStepView $step,
        TransitionCollectionStep $command,
        ?int $observedOpenMinor,
    ): DunningTransitionDecision {
        if ($command->outcomeEvidenceDigest === null) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.collection.success_requires_evidence');
        }

        $newOpen = $observedOpenMinor ?? $command->updatedOpenMinor ?? max(0, $case->currentOpenMinor - $step->amountMinor);
        if ($observedOpenMinor === null && $newOpen > $case->currentOpenMinor) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'updatedOpenMinor cannot exceed currentOpenMinor');
        }

        $settled = $newOpen === 0;

        return new DunningTransitionDecision(
            caseStatus: $settled ? DunningCaseStatus::Settled : DunningCaseStatus::Open,
            currentOpenMinor: $newOpen,
            businessDeclineAttemptsUsed: $case->businessDeclineAttemptsUsed,
            activeStepNo: null,
            lastCompletedStepNo: max($case->lastCompletedStepNo, $step->stepNo),
            parkedReason: null,
            parkedStepRef: null,
            nextAttemptAt: null,
            closedReason: $settled ? 'step_succeeded' : null,
            closedAt: $settled ? $command->occurredAt->setTimezone(new DateTimeZone('UTC')) : null,
            nextStepEligible: false,
            nextStepNo: null,
        );
    }

    /**
     * Pure deterministic transition decision for CollectionStepStatus::Unknown.
     */
    public function reduceUnknown(
        DunningCaseView $case,
        CollectionStepView $step,
        TransitionCollectionStep $command,
    ): DunningTransitionDecision {
        $parkReason = $command->declineReason ?? 'prior_step_unknown';

        return new DunningTransitionDecision(
            caseStatus: DunningCaseStatus::ParkedUnknown,
            currentOpenMinor: $case->currentOpenMinor,
            businessDeclineAttemptsUsed: $case->businessDeclineAttemptsUsed,
            activeStepNo: $case->activeStepNo,
            lastCompletedStepNo: $case->lastCompletedStepNo,
            parkedReason: $parkReason,
            parkedStepRef: $step->stepRef,
            nextAttemptAt: null,
            closedReason: $case->closedReason,
            closedAt: $case->closedAt,
            nextStepEligible: false,
            nextStepNo: null,
        );
    }

    /**
     * Pure deterministic transition decision for CollectionStepStatus::Cancelled.
     */
    public function reduceCancelled(
        DunningCaseView $case,
        TransitionCollectionStep $command,
    ): DunningTransitionDecision {
        $cancelReason = $command->declineReason ?? 'step_cancelled';

        return new DunningTransitionDecision(
            caseStatus: DunningCaseStatus::Cancelled,
            currentOpenMinor: $case->currentOpenMinor,
            businessDeclineAttemptsUsed: $case->businessDeclineAttemptsUsed,
            activeStepNo: null,
            lastCompletedStepNo: $case->lastCompletedStepNo,
            parkedReason: null,
            parkedStepRef: null,
            nextAttemptAt: null,
            closedReason: $cancelReason,
            closedAt: $command->occurredAt->setTimezone(new DateTimeZone('UTC')),
            nextStepEligible: false,
            nextStepNo: null,
        );
    }

    private static function stepDefinition(DunningPolicyView $policy, int $stepNo): ?DunningPolicyStepDefinition
    {
        foreach ($policy->orderedSteps as $definition) {
            if ($definition->stepNo === $stepNo) {
                return $definition;
            }
        }

        return null;
    }
}
