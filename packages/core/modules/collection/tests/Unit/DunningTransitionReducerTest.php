<?php

declare(strict_types=1);

namespace Billium\Collection\Tests\Unit;

use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\CollectionStepView;
use Billium\Collection\Data\DunningCaseView;
use Billium\Collection\Data\DunningPolicyStepDefinition;
use Billium\Collection\Data\DunningPolicyView;
use Billium\Collection\Data\TransitionCollectionStep;
use Billium\Collection\Enums\CollectionChannel;
use Billium\Collection\Enums\CollectionStepStatus;
use Billium\Collection\Enums\DeclineCategory;
use Billium\Collection\Enums\DunningCaseStatus;
use Billium\Collection\Internal\Reducers\DunningTransitionReducer;
use Billium\Collection\Internal\Support\RetryJitter;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class DunningTransitionReducerTest extends TestCase
{
    public function test_terminal_decline_requires_category_and_evidence_digest(): void
    {
        $reducer = new DunningTransitionReducer;
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));

        try {
            $reducer->reduceTerminalDecline(
                $this->makeCase($t0),
                $this->makeStep($t0),
                $this->makePolicy($t0),
                $this->makeCommand(CollectionStepStatus::FailedTerminal, $t0, declineCategory: null, evidenceDigest: 'sha256:abc'),
                null,
            );
            self::fail('Expected ProblemException when declineCategory is null');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            self::assertSame('billium.collection.terminal_decline_requires_category_and_evidence', $e->detail);
        }

        try {
            $reducer->reduceTerminalDecline(
                $this->makeCase($t0),
                $this->makeStep($t0),
                $this->makePolicy($t0),
                $this->makeCommand(CollectionStepStatus::FailedTerminal, $t0, declineCategory: DeclineCategory::InsufficientFunds, evidenceDigest: null),
                null,
            );
            self::fail('Expected ProblemException when outcomeEvidenceDigest is null');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            self::assertSame('billium.collection.terminal_decline_requires_category_and_evidence', $e->detail);
        }
    }

    public function test_terminal_decline_retryable_computes_backoff_jitter_utc_and_keeps_case_open(): void
    {
        $reducer = new DunningTransitionReducer;
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $occurredAt = new DateTimeImmutable('2026-11-20T03:00:00+03:00');
        $step2 = new DunningPolicyStepDefinition(
            stepNo: 2,
            channel: CollectionChannel::SavedMethod,
            delaySeconds: 100,
            backoffMultiplierBps: 20_000,
            maxBackoffSeconds: 250,
            jitterMaxSeconds: 40,
        );
        $case = $this->makeCase($t0, businessDeclineAttemptsUsed: 1, maxBusinessDeclineAttempts: 3, lastCompletedStepNo: 2);
        $step = $this->makeStep($t0, stepNo: 1);
        $policy = $this->makePolicy($t0, orderedSteps: [
            new DunningPolicyStepDefinition(1, CollectionChannel::SavedMethod, 60, 10_000, 60, 0),
            $step2,
        ], minOutstandingMinor: 500);

        $decision = $reducer->reduceTerminalDecline(
            $case,
            $step,
            $policy,
            $this->makeCommand(CollectionStepStatus::FailedTerminal, $occurredAt, DeclineCategory::InsufficientFunds, 'sha256:ev'),
            observedOpenMinor: 500,
        );

        $expectedJitter = RetryJitter::seconds($case->caseRef, $step->stepRef, $occurredAt, 40);
        // scaledDelay = intdiv(100 * 20000, 10000) = 200; baseDelay = min(250, max(100, 200)) = 200
        // effectiveDelay = 200 + min($expectedJitter, 50)
        $expectedDelay = 200 + min($expectedJitter, 50);
        $expectedNext = $occurredAt->setTimezone(new DateTimeZone('UTC'))->modify('+'.$expectedDelay.' seconds');

        self::assertSame(DunningCaseStatus::Open, $decision->caseStatus);
        self::assertSame(500, $decision->currentOpenMinor);
        self::assertSame(2, $decision->businessDeclineAttemptsUsed);
        self::assertNull($decision->activeStepNo);
        self::assertSame(2, $decision->lastCompletedStepNo);
        self::assertNull($decision->parkedReason);
        self::assertNull($decision->parkedStepRef);
        self::assertEquals($expectedNext, $decision->nextAttemptAt);
        self::assertSame('UTC', $decision->nextAttemptAt?->getTimezone()->getName());
        self::assertNull($decision->closedReason);
        self::assertNull($decision->closedAt);
        self::assertTrue($decision->nextStepEligible);
        self::assertSame(2, $decision->nextStepNo);
    }

    public function test_terminal_decline_clamps_delay_when_backoff_multiplier_is_below_one_and_deadline_is_exact_boundary(): void
    {
        $reducer = new DunningTransitionReducer;
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $occurredAt = $t0->modify('+10 seconds');
        // scaledDelay = intdiv(100 * 5000, 10000) = 50 -> max(100, 50) = 100 -> min(80, 100) = 80
        // jitterMaxSeconds = 30 -> max(0, 80 - 80) = 0 -> effectiveDelay = 80
        $step2 = new DunningPolicyStepDefinition(2, CollectionChannel::SavedMethod, 100, 5_000, 80, 30);
        $deadline = $occurredAt->modify('+80 seconds');
        $case = $this->makeCase($t0, recoveryDeadlineAt: $deadline);
        $policy = $this->makePolicy($t0, orderedSteps: [
            new DunningPolicyStepDefinition(1, CollectionChannel::SavedMethod, 60, 10_000, 60, 0),
            $step2,
        ]);

        $decision = $reducer->reduceTerminalDecline(
            $case,
            $this->makeStep($t0, stepNo: 1),
            $policy,
            $this->makeCommand(CollectionStepStatus::FailedTerminal, $occurredAt, DeclineCategory::InsufficientFunds, 'sha256:ev'),
            observedOpenMinor: null,
        );

        self::assertSame(DunningCaseStatus::Open, $decision->caseStatus);
        self::assertTrue($decision->nextStepEligible);
        self::assertEquals($deadline, $decision->nextAttemptAt);
    }

    public function test_terminal_decline_exhaustion_reasons_and_precedence(): void
    {
        $reducer = new DunningTransitionReducer;
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $occurredAt = new DateTimeImmutable('2026-11-20T03:05:00+03:00');
        $expectedClosedAt = $occurredAt->setTimezone(new DateTimeZone('UTC'));

        // 1. Non-retryable decline takes precedence over budget/steps/horizon/threshold.
        $nonRetry = $reducer->reduceTerminalDecline(
            $this->makeCase($t0, currentOpenMinor: 0, businessDeclineAttemptsUsed: 2, maxBusinessDeclineAttempts: 3),
            $this->makeStep($t0, stepNo: 2),
            $this->makePolicy($t0, orderedSteps: []),
            $this->makeCommand(CollectionStepStatus::FailedTerminal, $occurredAt, DeclineCategory::FraudSuspected, 'sha256:ev'),
            null,
        );
        self::assertSame(DunningCaseStatus::Exhausted, $nonRetry->caseStatus);
        self::assertSame('non_retryable_decline:fraud_suspected', $nonRetry->closedReason);
        self::assertEquals($expectedClosedAt, $nonRetry->closedAt);
        self::assertFalse($nonRetry->nextStepEligible);
        self::assertNull($nonRetry->nextStepNo);

        // 2. Budget exhausted (usedDeclines === maxBusinessDeclineAttempts) takes precedence over steps/horizon/threshold.
        $budget = $reducer->reduceTerminalDecline(
            $this->makeCase($t0, currentOpenMinor: 0, businessDeclineAttemptsUsed: 2, maxBusinessDeclineAttempts: 3),
            $this->makeStep($t0, stepNo: 1),
            $this->makePolicy($t0),
            $this->makeCommand(CollectionStepStatus::FailedTerminal, $occurredAt, DeclineCategory::InsufficientFunds, 'sha256:ev'),
            null,
        );
        self::assertSame(DunningCaseStatus::Exhausted, $budget->caseStatus);
        self::assertSame('business_decline_budget_exhausted', $budget->closedReason);
        self::assertSame(3, $budget->businessDeclineAttemptsUsed);

        // 3. Policy steps exhausted when next step definition is missing.
        $noStep = $reducer->reduceTerminalDecline(
            $this->makeCase($t0, currentOpenMinor: 0),
            $this->makeStep($t0, stepNo: 2),
            $this->makePolicy($t0),
            $this->makeCommand(CollectionStepStatus::FailedTerminal, $occurredAt, DeclineCategory::InsufficientFunds, 'sha256:ev'),
            null,
        );
        self::assertSame(DunningCaseStatus::Exhausted, $noStep->caseStatus);
        self::assertSame('policy_steps_exhausted', $noStep->closedReason);

        // 4. Recovery horizon exceeded when candidateEligibleAt > recoveryDeadlineAt.
        $horizon = $reducer->reduceTerminalDecline(
            $this->makeCase($t0, currentOpenMinor: 0, recoveryDeadlineAt: $expectedClosedAt->modify('+299 seconds')),
            $this->makeStep($t0, stepNo: 1),
            $this->makePolicy($t0),
            $this->makeCommand(CollectionStepStatus::FailedTerminal, $occurredAt, DeclineCategory::InsufficientFunds, 'sha256:ev'),
            null,
        );
        self::assertSame(DunningCaseStatus::Exhausted, $horizon->caseStatus);
        self::assertSame('recovery_horizon_exceeded', $horizon->closedReason);

        // 5. Below outstanding threshold (open === 0 -> Exhausted, NOT Settled; and 0 < open < minOutstandingMinor).
        $zeroOpen = $reducer->reduceTerminalDecline(
            $this->makeCase($t0, currentOpenMinor: 10_000),
            $this->makeStep($t0, stepNo: 1),
            $this->makePolicy($t0, minOutstandingMinor: 0),
            $this->makeCommand(CollectionStepStatus::FailedTerminal, $occurredAt, DeclineCategory::InsufficientFunds, 'sha256:ev'),
            observedOpenMinor: 0,
        );
        self::assertSame(DunningCaseStatus::Exhausted, $zeroOpen->caseStatus);
        self::assertSame(0, $zeroOpen->currentOpenMinor);
        self::assertSame('below_outstanding_threshold', $zeroOpen->closedReason);

        $belowMin = $reducer->reduceTerminalDecline(
            $this->makeCase($t0, currentOpenMinor: 10_000),
            $this->makeStep($t0, stepNo: 1),
            $this->makePolicy($t0, minOutstandingMinor: 500),
            $this->makeCommand(CollectionStepStatus::FailedTerminal, $occurredAt, DeclineCategory::InsufficientFunds, 'sha256:ev'),
            observedOpenMinor: 499,
        );
        self::assertSame(DunningCaseStatus::Exhausted, $belowMin->caseStatus);
        self::assertSame(499, $belowMin->currentOpenMinor);
        self::assertSame('below_outstanding_threshold', $belowMin->closedReason);
    }

    public function test_succeeded_transitions_cover_evidence_validation_open_calculation_and_settlement(): void
    {
        $reducer = new DunningTransitionReducer;
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $occurredAt = new DateTimeImmutable('2026-11-20T03:10:00+03:00');

        try {
            $reducer->reduceSucceeded(
                $this->makeCase($t0),
                $this->makeStep($t0),
                $this->makeCommand(CollectionStepStatus::Succeeded, $occurredAt, evidenceDigest: null),
                null,
            );
            self::fail('Expected ProblemException when outcomeEvidenceDigest is null');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            self::assertSame('billium.collection.success_requires_evidence', $e->detail);
        }

        try {
            $reducer->reduceSucceeded(
                $this->makeCase($t0, currentOpenMinor: 5_000),
                $this->makeStep($t0),
                $this->makeCommand(CollectionStepStatus::Succeeded, $occurredAt, evidenceDigest: 'sha256:ev', updatedOpenMinor: 5_001),
                null,
            );
            self::fail('Expected ProblemException when updatedOpenMinor exceeds currentOpenMinor');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            self::assertSame('updatedOpenMinor cannot exceed currentOpenMinor', $e->detail);
        }

        // Observed open minor can exceed currentOpenMinor when observedOpenMinor is provided.
        $observedHigher = $reducer->reduceSucceeded(
            $this->makeCase($t0, currentOpenMinor: 5_000, lastCompletedStepNo: 2),
            $this->makeStep($t0, stepNo: 1),
            $this->makeCommand(CollectionStepStatus::Succeeded, $occurredAt, evidenceDigest: 'sha256:ev'),
            observedOpenMinor: 6_000,
        );
        self::assertSame(DunningCaseStatus::Open, $observedHigher->caseStatus);
        self::assertSame(6_000, $observedHigher->currentOpenMinor);
        self::assertSame(2, $observedHigher->lastCompletedStepNo);
        self::assertNull($observedHigher->closedReason);
        self::assertNull($observedHigher->closedAt);

        // Fallback to max(0, currentOpenMinor - step.amountMinor) -> clamped to 0 -> Settled.
        $settled = $reducer->reduceSucceeded(
            $this->makeCase($t0, currentOpenMinor: 3_000, lastCompletedStepNo: 0),
            $this->makeStep($t0, stepNo: 1, amountMinor: 4_000),
            $this->makeCommand(CollectionStepStatus::Succeeded, $occurredAt, evidenceDigest: 'sha256:ev'),
            observedOpenMinor: null,
        );
        self::assertSame(DunningCaseStatus::Settled, $settled->caseStatus);
        self::assertSame(0, $settled->currentOpenMinor);
        self::assertSame(1, $settled->lastCompletedStepNo);
        self::assertSame('step_succeeded', $settled->closedReason);
        self::assertEquals($occurredAt->setTimezone(new DateTimeZone('UTC')), $settled->closedAt);
        self::assertFalse($settled->nextStepEligible);
        self::assertNull($settled->nextStepNo);
    }

    public function test_unknown_and_cancelled_transitions(): void
    {
        $reducer = new DunningTransitionReducer;
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $occurredAt = new DateTimeImmutable('2026-11-20T03:15:00+03:00');
        $case = $this->makeCase($t0, activeStepNo: 1, lastCompletedStepNo: 0);
        $step = $this->makeStep($t0, stepNo: 1);

        $unknownDefault = $reducer->reduceUnknown(
            $case,
            $step,
            $this->makeCommand(CollectionStepStatus::Unknown, $occurredAt),
        );
        self::assertSame(DunningCaseStatus::ParkedUnknown, $unknownDefault->caseStatus);
        self::assertSame('prior_step_unknown', $unknownDefault->parkedReason);
        self::assertSame($step->stepRef, $unknownDefault->parkedStepRef);
        self::assertSame(1, $unknownDefault->activeStepNo);
        self::assertNull($unknownDefault->nextAttemptAt);
        self::assertFalse($unknownDefault->nextStepEligible);

        $unknownCustom = $reducer->reduceUnknown(
            $case,
            $step,
            $this->makeCommand(CollectionStepStatus::Unknown, $occurredAt, declineReason: 'gateway_timeout'),
        );
        self::assertSame('gateway_timeout', $unknownCustom->parkedReason);

        $cancelledDefault = $reducer->reduceCancelled(
            $case,
            $this->makeCommand(CollectionStepStatus::Cancelled, $occurredAt),
        );
        self::assertSame(DunningCaseStatus::Cancelled, $cancelledDefault->caseStatus);
        self::assertNull($cancelledDefault->activeStepNo);
        self::assertNull($cancelledDefault->parkedReason);
        self::assertNull($cancelledDefault->parkedStepRef);
        self::assertSame('step_cancelled', $cancelledDefault->closedReason);
        self::assertEquals($occurredAt->setTimezone(new DateTimeZone('UTC')), $cancelledDefault->closedAt);

        $cancelledCustom = $reducer->reduceCancelled(
            $case,
            $this->makeCommand(CollectionStepStatus::Cancelled, $occurredAt, declineReason: 'customer_cancelled'),
        );
        self::assertSame('customer_cancelled', $cancelledCustom->closedReason);
    }

    private function makeCase(
        DateTimeImmutable $t0,
        int $currentOpenMinor = 10_000,
        int $businessDeclineAttemptsUsed = 0,
        int $maxBusinessDeclineAttempts = 3,
        ?int $activeStepNo = 1,
        int $lastCompletedStepNo = 0,
        ?DateTimeImmutable $recoveryDeadlineAt = null,
    ): DunningCaseView {
        return new DunningCaseView(
            id: 1,
            sellerRef: 'seller-a',
            environment: 'test',
            caseRef: 'case-1',
            billingAccountRef: 'acct-1',
            obligationRef: 'obl-1',
            componentRef: null,
            collectionGeneration: 1,
            policyId: 10,
            policyRef: 'pol-1',
            policyVersion: 1,
            currency: 'RUB',
            initialAmountMinor: 10_000,
            currentOpenMinor: $currentOpenMinor,
            status: DunningCaseStatus::Open,
            caseRevision: 1,
            cancellationGeneration: 0,
            businessDeclineAttemptsUsed: $businessDeclineAttemptsUsed,
            maxBusinessDeclineAttempts: $maxBusinessDeclineAttempts,
            activeStepNo: $activeStepNo,
            lastCompletedStepNo: $lastCompletedStepNo,
            nextAttemptAt: null,
            recoveryDeadlineAt: $recoveryDeadlineAt ?? $t0->modify('+1 day'),
            parkedReason: null,
            parkedStepRef: null,
            closedReason: null,
            closedAt: null,
            openedAt: $t0,
            updatedAt: $t0,
            caseDigest: 'sha256:case',
        );
    }

    private function makeStep(DateTimeImmutable $t0, int $stepNo = 1, int $amountMinor = 2_000): CollectionStepView
    {
        return new CollectionStepView(
            id: 100 + $stepNo,
            sellerRef: 'seller-a',
            environment: 'test',
            dunningCaseId: 1,
            caseRef: 'case-1',
            stepRef: 'step-'.$stepNo,
            stepNo: $stepNo,
            policyId: 10,
            policyRef: 'pol-1',
            policyVersion: 1,
            channel: CollectionChannel::SavedMethod,
            businessPaymentIntentRef: 'bpi-'.$stepNo,
            operationRef: 'op-'.$stepNo,
            stepAuthorizationRef: null,
            currency: 'RUB',
            amountMinor: $amountMinor,
            status: CollectionStepStatus::Dispatched,
            stepRevision: 2,
            deliveryAttemptsUsed: 0,
            maxDeliveryRetries: 2,
            priorStepId: null,
            declineCategory: null,
            declineReason: null,
            outcomeEvidenceDigest: null,
            scheduledAt: $t0->modify('+60 seconds'),
            lastDeliveryAttemptAt: $t0->modify('+60 seconds'),
            terminalAt: null,
            createdAt: $t0,
            updatedAt: $t0,
            stepDigest: 'sha256:step',
        );
    }

    /** @param list<DunningPolicyStepDefinition>|null $orderedSteps */
    private function makePolicy(
        DateTimeImmutable $t0,
        ?array $orderedSteps = null,
        int $minOutstandingMinor = 0,
    ): DunningPolicyView {
        return new DunningPolicyView(
            id: 10,
            sellerRef: 'seller-a',
            environment: 'test',
            policyRef: 'pol-1',
            policyVersion: 1,
            allowedChannels: [CollectionChannel::SavedMethod],
            orderedSteps: $orderedSteps ?? [
                new DunningPolicyStepDefinition(1, CollectionChannel::SavedMethod, 60, 10_000, 60, 0),
                new DunningPolicyStepDefinition(2, CollectionChannel::SavedMethod, 300, 10_000, 300, 0),
            ],
            maxBusinessDeclineAttempts: 3,
            maxDeliveryRetries: 2,
            retryableDeclineCategories: [DeclineCategory::InsufficientFunds, DeclineCategory::TemporaryNetwork],
            recoveryHorizonSeconds: 86_400,
            graceSignalAfterSeconds: null,
            readOnlySignalAfterSeconds: null,
            minOutstandingMinor: $minOutstandingMinor,
            thresholdCurrency: $minOutstandingMinor > 0 ? 'RUB' : null,
            supersedesPolicyId: null,
            policyDigest: 'sha256:pol',
            createdAt: $t0,
        );
    }

    private function makeCommand(
        CollectionStepStatus $targetStatus,
        DateTimeImmutable $occurredAt,
        ?DeclineCategory $declineCategory = null,
        ?string $evidenceDigest = null,
        ?string $declineReason = null,
        ?int $updatedOpenMinor = null,
    ): TransitionCollectionStep {
        return new TransitionCollectionStep(
            commandKey: 'cmd-1',
            scope: new CollectionScope('seller-a', 'test'),
            caseRef: 'case-1',
            stepRef: 'step-1',
            targetStatus: $targetStatus,
            expectedStepRevision: 2,
            occurredAt: $occurredAt,
            declineCategory: $declineCategory,
            declineReason: $declineReason,
            outcomeEvidenceDigest: $evidenceDigest,
            updatedOpenMinor: $updatedOpenMinor,
        );
    }
}
