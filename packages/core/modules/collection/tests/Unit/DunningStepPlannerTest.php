<?php

declare(strict_types=1);

namespace Billium\Collection\Tests\Unit;

use Billium\Collection\Data\CollectionStepView;
use Billium\Collection\Data\DunningCaseView;
use Billium\Collection\Data\DunningPolicyStepDefinition;
use Billium\Collection\Data\DunningPolicyView;
use Billium\Collection\Enums\CollectionChannel;
use Billium\Collection\Enums\CollectionStepStatus;
use Billium\Collection\Enums\DeclineCategory;
use Billium\Collection\Enums\DunningCaseStatus;
use Billium\Collection\Internal\Reducers\DunningStepPlanner;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DunningStepPlannerTest extends TestCase
{
    /** @return iterable<string, array{DunningCaseStatus, string}> */
    public static function nonPlannableCaseStatuses(): iterable
    {
        yield 'parked unknown' => [DunningCaseStatus::ParkedUnknown, 'billium.collection.case_parked_unknown'];
        yield 'exhausted' => [DunningCaseStatus::Exhausted, 'billium.collection.case_not_plannable'];
        yield 'settled' => [DunningCaseStatus::Settled, 'billium.collection.case_not_plannable'];
        yield 'cancelled' => [DunningCaseStatus::Cancelled, 'billium.collection.case_not_plannable'];
        yield 'paused' => [DunningCaseStatus::Paused, 'billium.collection.case_not_plannable'];
    }

    /** @return iterable<string, array{CollectionStepStatus, string}> */
    public static function blockingStepStatuses(): iterable
    {
        yield 'unknown' => [CollectionStepStatus::Unknown, 'billium.collection.prior_step_unknown_case_parked'];
        yield 'planned' => [CollectionStepStatus::Planned, 'billium.collection.prior_step_in_flight'];
        yield 'authorized' => [CollectionStepStatus::Authorized, 'billium.collection.prior_step_in_flight'];
        yield 'dispatched' => [CollectionStepStatus::Dispatched, 'billium.collection.prior_step_in_flight'];
    }

    #[DataProvider('nonPlannableCaseStatuses')]
    public function test_validate_sequence_rejects_non_plannable_case_status(DunningCaseStatus $status, string $detail): void
    {
        $planner = new DunningStepPlanner;
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));

        $this->assertProblem(
            ProblemCode::OperationFinancialHold,
            $detail,
            fn () => $planner->validateSequence($this->makeCase($t0, status: $status), $this->makePolicy($t0), [], 1),
        );
    }

    #[DataProvider('blockingStepStatuses')]
    public function test_validate_sequence_rejects_unknown_or_in_flight_existing_steps(CollectionStepStatus $status, string $detail): void
    {
        $planner = new DunningStepPlanner;
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));

        $this->assertProblem(
            ProblemCode::OperationFinancialHold,
            $detail,
            fn () => $planner->validateSequence(
                $this->makeCase($t0),
                $this->makePolicy($t0),
                [$this->makeStep($t0, stepNo: 1, status: $status)],
                2,
            ),
        );
    }

    public function test_validate_sequence_checks_step_one_and_prior_step_rules(): void
    {
        $planner = new DunningStepPlanner;
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $policy = $this->makePolicy($t0);

        self::assertNull($planner->validateSequence($this->makeCase($t0), $policy, [], 1));

        $this->assertProblem(
            ProblemCode::ValidationInvalid,
            'billium.collection.step_sequence_invalid',
            fn () => $planner->validateSequence(
                $this->makeCase($t0),
                $policy,
                [$this->makeStep($t0, stepNo: 1, status: CollectionStepStatus::Succeeded)],
                1,
            ),
        );

        $this->assertProblem(
            ProblemCode::ValidationInvalid,
            'billium.collection.prior_step_missing',
            fn () => $planner->validateSequence(
                $this->makeCase($t0),
                $policy,
                [$this->makeStep($t0, stepNo: 1, status: CollectionStepStatus::FailedTerminal, declineCategory: DeclineCategory::InsufficientFunds)],
                3,
            ),
        );

        $this->assertProblem(
            ProblemCode::OperationFinancialHold,
            'billium.collection.prior_step_not_terminal_decline',
            fn () => $planner->validateSequence(
                $this->makeCase($t0),
                $policy,
                [$this->makeStep($t0, stepNo: 1, status: CollectionStepStatus::Cancelled)],
                2,
            ),
        );

        $this->assertProblem(
            ProblemCode::OperationFinancialHold,
            'billium.collection.decline_not_retryable',
            fn () => $planner->validateSequence(
                $this->makeCase($t0),
                $policy,
                [$this->makeStep($t0, stepNo: 1, status: CollectionStepStatus::FailedTerminal, declineCategory: null)],
                2,
            ),
        );

        $this->assertProblem(
            ProblemCode::OperationFinancialHold,
            'billium.collection.decline_not_retryable',
            fn () => $planner->validateSequence(
                $this->makeCase($t0),
                $policy,
                [$this->makeStep($t0, stepNo: 1, status: CollectionStepStatus::FailedTerminal, declineCategory: DeclineCategory::HardDecline)],
                2,
            ),
        );

        $this->assertProblem(
            ProblemCode::OperationFinancialHold,
            'billium.collection.business_decline_budget_exhausted',
            fn () => $planner->validateSequence(
                $this->makeCase($t0, businessDeclineAttemptsUsed: 3, maxBusinessDeclineAttempts: 3),
                $policy,
                [$this->makeStep($t0, stepNo: 1, status: CollectionStepStatus::FailedTerminal, declineCategory: DeclineCategory::InsufficientFunds)],
                2,
            ),
        );

        $priorId = $planner->validateSequence(
            $this->makeCase($t0, businessDeclineAttemptsUsed: 2, maxBusinessDeclineAttempts: 3),
            $policy,
            [$this->makeStep($t0, stepNo: 1, status: CollectionStepStatus::FailedTerminal, declineCategory: DeclineCategory::InsufficientFunds)],
            2,
        );
        self::assertSame(101, $priorId);
    }

    public function test_resolve_step_definition_and_decide_boundaries(): void
    {
        $planner = new DunningStepPlanner;
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $policy = $this->makePolicy($t0, minOutstandingMinor: 500);

        $stepDef = $planner->resolveStepDefinition($policy, 2);
        self::assertSame(2, $stepDef->stepNo);

        $this->assertProblem(
            ProblemCode::PolicyUnknown,
            'billium.collection.policy_step_not_defined',
            fn () => $planner->resolveStepDefinition($policy, 99),
        );

        $case = $this->makeCase($t0, currentOpenMinor: 1_000, recoveryDeadlineAt: $t0->modify('+300 seconds'));

        // Exact boundaries: amount == currentOpenMinor, amount == minOutstandingMinor, scheduledAt == recoveryDeadlineAt.
        $decision = $planner->decide($case, $policy, $stepDef, 101, 1_000, $t0->modify('+300 seconds'), true);
        self::assertSame(101, $decision->priorStepId);
        self::assertSame($stepDef, $decision->stepDefinition);
        self::assertSame(1_000, $decision->amountMinor);

        // When enforceOpenMinorCap is false (authorized path), amount > currentOpenMinor is allowed.
        $authorizedDecision = $planner->decide($case, $policy, $stepDef, null, 1_500, $t0->modify('+300 seconds'), false);
        self::assertSame(1_500, $authorizedDecision->amountMinor);

        $this->assertProblem(
            ProblemCode::ValidationInvalid,
            'billium.collection.amount_exceeds_open',
            fn () => $planner->decide($case, $policy, $stepDef, null, 1_001, $t0->modify('+60 seconds'), true),
        );

        $this->assertProblem(
            ProblemCode::ValidationInvalid,
            'billium.collection.below_outstanding_threshold',
            fn () => $planner->decide($case, $policy, $stepDef, null, 499, $t0->modify('+60 seconds'), true),
        );

        $this->assertProblem(
            ProblemCode::OperationFinancialHold,
            'billium.collection.recovery_horizon_exceeded',
            fn () => $planner->decide($case, $policy, $stepDef, null, 500, $t0->modify('+301 seconds'), true),
        );

        // minOutstandingMinor === 0 disables threshold check even for 0 amount.
        $zeroMinPolicy = $this->makePolicy($t0, minOutstandingMinor: 0);
        $zeroAmountDecision = $planner->decide($case, $zeroMinPolicy, $stepDef, null, 0, $t0->modify('+60 seconds'), true);
        self::assertSame(0, $zeroAmountDecision->amountMinor);
    }

    /** @param callable(): mixed $fn */
    private function assertProblem(ProblemCode $code, string $detail, callable $fn): void
    {
        try {
            $fn();
            self::fail("Expected ProblemException({$code->value}, {$detail})");
        } catch (ProblemException $e) {
            self::assertSame($code, $e->problem);
            self::assertSame($detail, $e->detail);
        }
    }

    private function makeCase(
        DateTimeImmutable $t0,
        DunningCaseStatus $status = DunningCaseStatus::Open,
        int $currentOpenMinor = 10_000,
        int $businessDeclineAttemptsUsed = 0,
        int $maxBusinessDeclineAttempts = 3,
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
            status: $status,
            caseRevision: 1,
            cancellationGeneration: 0,
            businessDeclineAttemptsUsed: $businessDeclineAttemptsUsed,
            maxBusinessDeclineAttempts: $maxBusinessDeclineAttempts,
            activeStepNo: null,
            lastCompletedStepNo: 0,
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

    private function makeStep(
        DateTimeImmutable $t0,
        int $stepNo,
        CollectionStepStatus $status,
        ?DeclineCategory $declineCategory = null,
    ): CollectionStepView {
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
            amountMinor: 2_000,
            status: $status,
            stepRevision: 1,
            deliveryAttemptsUsed: 0,
            maxDeliveryRetries: 2,
            priorStepId: null,
            declineCategory: $declineCategory,
            declineReason: null,
            outcomeEvidenceDigest: null,
            scheduledAt: $t0->modify('+60 seconds'),
            lastDeliveryAttemptAt: null,
            terminalAt: null,
            createdAt: $t0,
            updatedAt: $t0,
            stepDigest: 'sha256:step',
        );
    }

    private function makePolicy(DateTimeImmutable $t0, int $minOutstandingMinor = 0): DunningPolicyView
    {
        return new DunningPolicyView(
            id: 10,
            sellerRef: 'seller-a',
            environment: 'test',
            policyRef: 'pol-1',
            policyVersion: 1,
            allowedChannels: [CollectionChannel::SavedMethod],
            orderedSteps: [
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
}
