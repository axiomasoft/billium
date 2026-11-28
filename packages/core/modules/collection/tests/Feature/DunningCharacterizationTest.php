<?php

declare(strict_types=1);

namespace Billium\Collection\Tests\Feature;

use Billium\Collection\Data\DunningPolicyStepDefinition;
use Billium\Collection\Data\OpenDunningCase;
use Billium\Collection\Data\PlanCollectionStep;
use Billium\Collection\Data\RegisterDunningPolicyVersion;
use Billium\Collection\Data\TransitionCollectionStep;
use Billium\Collection\Enums\CollectionChannel;
use Billium\Collection\Enums\CollectionStepStatus;
use Billium\Collection\Enums\DeclineCategory;
use Billium\Collection\Enums\DunningCaseStatus;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Tests\P7\Support\CollectionTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterization tests locking in the exact literal behavior of DatabaseDunningCaseService
 * before Functional Core / Imperative Shell decomposition (Phase F4-B.1).
 */
#[Group('pg')]
final class DunningCharacterizationTest extends CollectionTestCase
{
    public function test_failed_terminal_with_zero_open_minor_exhausts_case_as_below_outstanding_threshold(): void
    {
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $this->registerPolicy('pol-zero-open');
        $this->openCase('case-zero-open', 'pol-zero-open', 10_000, $t0);
        $this->planAndDispatch('case-zero-open', 'step-1', 1, 10_000, $t0->modify('+60 seconds'));

        // Simulate external settlement reducing current_open_minor to 0 before terminal decline arrives.
        $this->application()->make(DatabaseManager::class)
            ->connection($this->application()->make(BilliumOptions::class)->connection)
            ->table('billium_collection_dunning_case')
            ->where('case_ref', 'case-zero-open')
            ->increment('case_revision', 1, ['current_open_minor' => 0]);

        $occurredAt = $t0->modify('+120 seconds');
        $result = $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'term-zero-open',
            scope: $this->collectionScope(),
            caseRef: 'case-zero-open',
            stepRef: 'step-1',
            targetStatus: CollectionStepStatus::FailedTerminal,
            expectedStepRevision: 2,
            occurredAt: $occurredAt,
            declineCategory: DeclineCategory::InsufficientFunds,
            declineReason: 'insufficient_funds',
            outcomeEvidenceDigest: self::digest('b'),
        ));

        self::assertSame(DunningCaseStatus::Exhausted, $result->case->status);
        self::assertSame('below_outstanding_threshold', $result->case->closedReason);
        self::assertNotNull($result->case->closedAt);
        self::assertSame(0, $result->case->currentOpenMinor);
        self::assertSame(1, $result->case->businessDeclineAttemptsUsed);
        self::assertNull($result->case->nextAttemptAt);
        self::assertFalse($result->nextStepEligible);
        self::assertNull($result->nextStepNo);
        self::assertNull($result->nextEligibleAt);
    }

    public function test_failed_terminal_with_non_retryable_decline_exhausts_case(): void
    {
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $this->registerPolicy('pol-non-retry');
        $this->openCase('case-non-retry', 'pol-non-retry', 10_000, $t0);
        $this->planAndDispatch('case-non-retry', 'step-1', 1, 10_000, $t0->modify('+60 seconds'));

        $result = $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'term-non-retry',
            scope: $this->collectionScope(),
            caseRef: 'case-non-retry',
            stepRef: 'step-1',
            targetStatus: CollectionStepStatus::FailedTerminal,
            expectedStepRevision: 2,
            occurredAt: $t0->modify('+120 seconds'),
            declineCategory: DeclineCategory::FraudSuspected,
            declineReason: 'fraud',
            outcomeEvidenceDigest: self::digest('c'),
        ));

        self::assertSame(DunningCaseStatus::Exhausted, $result->case->status);
        self::assertSame('non_retryable_decline:fraud_suspected', $result->case->closedReason);
        self::assertNotNull($result->case->closedAt);
        self::assertNull($result->case->nextAttemptAt);
        self::assertFalse($result->nextStepEligible);
        self::assertNull($result->nextStepNo);
        self::assertNull($result->nextEligibleAt);
    }

    public function test_failed_terminal_with_business_decline_budget_exhausted_exhausts_case(): void
    {
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $this->registerPolicy('pol-budget', maxBusinessDeclineAttempts: 1);
        $this->openCase('case-budget', 'pol-budget', 10_000, $t0);
        $this->planAndDispatch('case-budget', 'step-1', 1, 10_000, $t0->modify('+60 seconds'));

        $result = $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'term-budget',
            scope: $this->collectionScope(),
            caseRef: 'case-budget',
            stepRef: 'step-1',
            targetStatus: CollectionStepStatus::FailedTerminal,
            expectedStepRevision: 2,
            occurredAt: $t0->modify('+120 seconds'),
            declineCategory: DeclineCategory::InsufficientFunds,
            declineReason: 'insufficient_funds',
            outcomeEvidenceDigest: self::digest('d'),
        ));

        self::assertSame(DunningCaseStatus::Exhausted, $result->case->status);
        self::assertSame('business_decline_budget_exhausted', $result->case->closedReason);
        self::assertSame(1, $result->case->businessDeclineAttemptsUsed);
        self::assertFalse($result->nextStepEligible);
        self::assertNull($result->nextStepNo);
        self::assertNull($result->nextEligibleAt);
    }

    public function test_failed_terminal_with_policy_steps_exhausted_exhausts_case(): void
    {
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $this->registerPolicy('pol-one-step', orderedSteps: [
            new DunningPolicyStepDefinition(1, CollectionChannel::SavedMethod, 60, 10000, 60, 0),
        ]);
        $this->openCase('case-one-step', 'pol-one-step', 10_000, $t0);
        $this->planAndDispatch('case-one-step', 'step-1', 1, 10_000, $t0->modify('+60 seconds'));

        $result = $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'term-one-step',
            scope: $this->collectionScope(),
            caseRef: 'case-one-step',
            stepRef: 'step-1',
            targetStatus: CollectionStepStatus::FailedTerminal,
            expectedStepRevision: 2,
            occurredAt: $t0->modify('+120 seconds'),
            declineCategory: DeclineCategory::InsufficientFunds,
            declineReason: 'insufficient_funds',
            outcomeEvidenceDigest: self::digest('e'),
        ));

        self::assertSame(DunningCaseStatus::Exhausted, $result->case->status);
        self::assertSame('policy_steps_exhausted', $result->case->closedReason);
        self::assertFalse($result->nextStepEligible);
        self::assertNull($result->nextStepNo);
        self::assertNull($result->nextEligibleAt);
    }

    public function test_failed_terminal_with_recovery_horizon_exceeded_exhausts_case(): void
    {
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $this->registerPolicy('pol-horizon', recoveryHorizonSeconds: 300, orderedSteps: [
            new DunningPolicyStepDefinition(1, CollectionChannel::SavedMethod, 60, 10000, 60, 0),
            new DunningPolicyStepDefinition(2, CollectionChannel::SavedMethod, 600, 10000, 600, 0),
        ]);
        $this->openCase('case-horizon', 'pol-horizon', 10_000, $t0);
        $this->planAndDispatch('case-horizon', 'step-1', 1, 10_000, $t0->modify('+60 seconds'));

        $result = $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'term-horizon',
            scope: $this->collectionScope(),
            caseRef: 'case-horizon',
            stepRef: 'step-1',
            targetStatus: CollectionStepStatus::FailedTerminal,
            expectedStepRevision: 2,
            occurredAt: $t0->modify('+120 seconds'),
            declineCategory: DeclineCategory::InsufficientFunds,
            declineReason: 'insufficient_funds',
            outcomeEvidenceDigest: self::digest('f'),
        ));

        self::assertSame(DunningCaseStatus::Exhausted, $result->case->status);
        self::assertSame('recovery_horizon_exceeded', $result->case->closedReason);
        self::assertFalse($result->nextStepEligible);
        self::assertNull($result->nextStepNo);
        self::assertNull($result->nextEligibleAt);
    }

    public function test_failed_terminal_retryable_within_budget_horizon_and_threshold_keeps_case_open(): void
    {
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $this->registerPolicy('pol-retryable');
        $this->openCase('case-retryable', 'pol-retryable', 10_000, $t0);
        $this->planAndDispatch('case-retryable', 'step-1', 1, 10_000, $t0->modify('+60 seconds'));

        $occurredAt = $t0->modify('+120 seconds');
        $result = $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'term-retryable',
            scope: $this->collectionScope(),
            caseRef: 'case-retryable',
            stepRef: 'step-1',
            targetStatus: CollectionStepStatus::FailedTerminal,
            expectedStepRevision: 2,
            occurredAt: $occurredAt,
            declineCategory: DeclineCategory::InsufficientFunds,
            declineReason: 'insufficient_funds',
            outcomeEvidenceDigest: self::digest('1'),
        ));

        self::assertSame(DunningCaseStatus::Open, $result->case->status);
        self::assertNull($result->case->closedReason);
        self::assertNull($result->case->closedAt);
        self::assertSame(1, $result->case->businessDeclineAttemptsUsed);
        self::assertSame(1, $result->case->lastCompletedStepNo);
        self::assertNull($result->case->activeStepNo);
        self::assertNotNull($result->case->nextAttemptAt);
        self::assertTrue($result->nextStepEligible);
        self::assertSame(2, $result->nextStepNo);
        self::assertNotNull($result->nextEligibleAt);
        self::assertEquals($result->case->nextAttemptAt, $result->nextEligibleAt);
    }

    public function test_succeeded_handles_partial_and_full_settlement(): void
    {
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $this->registerPolicy('pol-succeed');
        $this->openCase('case-partial', 'pol-succeed', 10_000, $t0);
        $this->planAndDispatch('case-partial', 'step-1', 1, 4_000, $t0->modify('+60 seconds'));

        $partial = $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'succ-partial',
            scope: $this->collectionScope(),
            caseRef: 'case-partial',
            stepRef: 'step-1',
            targetStatus: CollectionStepStatus::Succeeded,
            expectedStepRevision: 2,
            occurredAt: $t0->modify('+120 seconds'),
            outcomeEvidenceDigest: self::digest('2'),
            updatedOpenMinor: 6_000,
        ));

        self::assertSame(DunningCaseStatus::Open, $partial->case->status);
        self::assertSame(6_000, $partial->case->currentOpenMinor);
        self::assertNull($partial->case->closedReason);
        self::assertNull($partial->case->closedAt);
        self::assertFalse($partial->nextStepEligible);

        $this->openCase('case-full', 'pol-succeed', 10_000, $t0);
        $this->planAndDispatch('case-full', 'step-full-1', 1, 10_000, $t0->modify('+60 seconds'));

        $full = $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'succ-full',
            scope: $this->collectionScope(),
            caseRef: 'case-full',
            stepRef: 'step-full-1',
            targetStatus: CollectionStepStatus::Succeeded,
            expectedStepRevision: 2,
            occurredAt: $t0->modify('+120 seconds'),
            outcomeEvidenceDigest: self::digest('3'),
            updatedOpenMinor: 0,
        ));

        self::assertSame(DunningCaseStatus::Settled, $full->case->status);
        self::assertSame(0, $full->case->currentOpenMinor);
        self::assertSame('step_succeeded', $full->case->closedReason);
        self::assertNotNull($full->case->closedAt);
        self::assertFalse($full->nextStepEligible);
    }

    public function test_step_planning_validation_sequence_prior_status_threshold_and_horizon(): void
    {
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $this->registerPolicy('pol-plan-val', minOutstandingMinor: 500, recoveryHorizonSeconds: 3_600);
        $this->openCase('case-plan-val', 'pol-plan-val', 10_000, $t0);

        // Step 2 without step 1 -> prior_step_missing.
        $this->assertRefused(ProblemCode::ValidationInvalid, 'billium.collection.prior_step_missing', fn () => $this->dunningCaseService()->planStep(
            new PlanCollectionStep(
                commandKey: 'plan-missing-prior',
                scope: $this->collectionScope(),
                caseRef: 'case-plan-val',
                stepRef: 'step-2-missing',
                stepNo: 2,
                businessPaymentIntentRef: 'bpi-missing',
                operationRef: 'op-missing',
                amountMinor: 1_000,
                scheduledAt: $t0->modify('+60 seconds'),
            ),
        ));

        // Amount below minOutstandingMinor -> below_outstanding_threshold.
        $this->assertRefused(ProblemCode::ValidationInvalid, 'billium.collection.below_outstanding_threshold', fn () => $this->dunningCaseService()->planStep(
            new PlanCollectionStep(
                commandKey: 'plan-below-min',
                scope: $this->collectionScope(),
                caseRef: 'case-plan-val',
                stepRef: 'step-1-below',
                stepNo: 1,
                businessPaymentIntentRef: 'bpi-below',
                operationRef: 'op-below',
                amountMinor: 499,
                scheduledAt: $t0->modify('+60 seconds'),
            ),
        ));

        // Scheduled after recoveryDeadlineAt -> recovery_horizon_exceeded.
        $this->assertRefused(ProblemCode::OperationFinancialHold, 'billium.collection.recovery_horizon_exceeded', fn () => $this->dunningCaseService()->planStep(
            new PlanCollectionStep(
                commandKey: 'plan-after-horizon',
                scope: $this->collectionScope(),
                caseRef: 'case-plan-val',
                stepRef: 'step-1-horizon',
                stepNo: 1,
                businessPaymentIntentRef: 'bpi-horizon',
                operationRef: 'op-horizon',
                amountMinor: 1_000,
                scheduledAt: $t0->modify('+3601 seconds'),
            ),
        ));

        // Plan step 1 validly.
        $this->dunningCaseService()->planStep(new PlanCollectionStep(
            commandKey: 'plan-1-ok',
            scope: $this->collectionScope(),
            caseRef: 'case-plan-val',
            stepRef: 'step-1',
            stepNo: 1,
            businessPaymentIntentRef: 'bpi-1',
            operationRef: 'op-1',
            amountMinor: 1_000,
            scheduledAt: $t0->modify('+60 seconds'),
        ));

        // Planning step 2 while step 1 is still Planned -> prior_step_in_flight.
        $this->assertRefused(ProblemCode::OperationFinancialHold, 'billium.collection.prior_step_in_flight', fn () => $this->dunningCaseService()->planStep(
            new PlanCollectionStep(
                commandKey: 'plan-2-inflight',
                scope: $this->collectionScope(),
                caseRef: 'case-plan-val',
                stepRef: 'step-2-inflight',
                stepNo: 2,
                businessPaymentIntentRef: 'bpi-2-inflight',
                operationRef: 'op-2-inflight',
                amountMinor: 1_000,
                scheduledAt: $t0->modify('+120 seconds'),
            ),
        ));

        // Dispatch and succeed partially so step 1 is Succeeded (not FailedTerminal).
        $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'disp-1',
            scope: $this->collectionScope(),
            caseRef: 'case-plan-val',
            stepRef: 'step-1',
            targetStatus: CollectionStepStatus::Dispatched,
            expectedStepRevision: 1,
            occurredAt: $t0->modify('+60 seconds'),
        ));
        $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'succ-1',
            scope: $this->collectionScope(),
            caseRef: 'case-plan-val',
            stepRef: 'step-1',
            targetStatus: CollectionStepStatus::Succeeded,
            expectedStepRevision: 2,
            occurredAt: $t0->modify('+90 seconds'),
            outcomeEvidenceDigest: self::digest('4'),
            updatedOpenMinor: 9_000,
        ));

        // Step 1 again with different stepRef when step 1 already exists -> step_conflict.
        $this->assertRefused(ProblemCode::IdempotencyConflict, 'billium.collection.step_conflict', fn () => $this->dunningCaseService()->planStep(
            new PlanCollectionStep(
                commandKey: 'plan-1-again',
                scope: $this->collectionScope(),
                caseRef: 'case-plan-val',
                stepRef: 'step-1-again',
                stepNo: 1,
                businessPaymentIntentRef: 'bpi-1-again',
                operationRef: 'op-1-again',
                amountMinor: 1_000,
                scheduledAt: $t0->modify('+120 seconds'),
            ),
        ));

        // Step 2 when prior step is Succeeded (not FailedTerminal) -> prior_step_not_terminal_decline.
        $this->assertRefused(ProblemCode::OperationFinancialHold, 'billium.collection.prior_step_not_terminal_decline', fn () => $this->dunningCaseService()->planStep(
            new PlanCollectionStep(
                commandKey: 'plan-2-after-succ',
                scope: $this->collectionScope(),
                caseRef: 'case-plan-val',
                stepRef: 'step-2',
                stepNo: 2,
                businessPaymentIntentRef: 'bpi-2',
                operationRef: 'op-2',
                amountMinor: 1_000,
                scheduledAt: $t0->modify('+120 seconds'),
            ),
        ));
    }

    /** @param list<DunningPolicyStepDefinition>|null $orderedSteps */
    private function registerPolicy(
        string $policyRef,
        int $maxBusinessDeclineAttempts = 3,
        int $recoveryHorizonSeconds = 86_400,
        int $minOutstandingMinor = 0,
        ?array $orderedSteps = null,
    ): void {
        $this->dunningPolicyService()->registerPolicyVersion(new RegisterDunningPolicyVersion(
            commandKey: 'reg-'.$policyRef,
            scope: $this->collectionScope(),
            policyRef: $policyRef,
            policyVersion: 1,
            allowedChannels: [CollectionChannel::SavedMethod],
            orderedSteps: $orderedSteps ?? [
                new DunningPolicyStepDefinition(1, CollectionChannel::SavedMethod, 60, 10000, 60, 0),
                new DunningPolicyStepDefinition(2, CollectionChannel::SavedMethod, 300, 10000, 300, 0),
            ],
            maxBusinessDeclineAttempts: $maxBusinessDeclineAttempts,
            maxDeliveryRetries: 2,
            retryableDeclineCategories: [DeclineCategory::InsufficientFunds, DeclineCategory::TemporaryNetwork],
            recoveryHorizonSeconds: $recoveryHorizonSeconds,
            minOutstandingMinor: $minOutstandingMinor,
            thresholdCurrency: $minOutstandingMinor > 0 ? 'RUB' : null,
        ));
    }

    private function openCase(string $caseRef, string $policyRef, int $openAmountMinor, DateTimeImmutable $openedAt): void
    {
        $this->dunningCaseService()->openCase(new OpenDunningCase(
            commandKey: 'open-'.$caseRef,
            scope: $this->collectionScope(),
            caseRef: $caseRef,
            billingAccountRef: 'account-a',
            obligationRef: 'obl-'.$caseRef,
            collectionGeneration: 1,
            policyRef: $policyRef,
            policyVersion: 1,
            currency: 'RUB',
            openAmountMinor: $openAmountMinor,
            openedAt: $openedAt,
        ));
    }

    private function planAndDispatch(
        string $caseRef,
        string $stepRef,
        int $stepNo,
        int $amountMinor,
        DateTimeImmutable $scheduledAt,
    ): void {
        $this->dunningCaseService()->planStep(new PlanCollectionStep(
            commandKey: 'plan-'.$caseRef.'-'.$stepRef,
            scope: $this->collectionScope(),
            caseRef: $caseRef,
            stepRef: $stepRef,
            stepNo: $stepNo,
            businessPaymentIntentRef: 'bpi-'.$caseRef.'-'.$stepRef,
            operationRef: 'op-'.$caseRef.'-'.$stepRef,
            amountMinor: $amountMinor,
            scheduledAt: $scheduledAt,
        ));
        $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'disp-'.$caseRef.'-'.$stepRef,
            scope: $this->collectionScope(),
            caseRef: $caseRef,
            stepRef: $stepRef,
            targetStatus: CollectionStepStatus::Dispatched,
            expectedStepRevision: 1,
            occurredAt: $scheduledAt,
        ));
    }

    /** @param callable(): mixed $fn */
    private function assertRefused(ProblemCode $expectedCode, string $expectedDetail, callable $fn): void
    {
        try {
            $fn();
            self::fail("Expected ProblemException({$expectedCode->value}, {$expectedDetail})");
        } catch (ProblemException $e) {
            self::assertSame($expectedCode, $e->problem);
            self::assertSame($expectedDetail, $e->detail);
        }
    }
}
