<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P7;

use Billium\Collection\Contracts\DunningCaseService;
use Billium\Collection\Contracts\DunningPolicyService;
use Billium\Collection\Contracts\DunningQueries;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\DunningPolicyStepDefinition;
use Billium\Collection\Data\MigrateDunningCasePolicy;
use Billium\Collection\Data\OpenDunningCase;
use Billium\Collection\Data\PlanCollectionStep;
use Billium\Collection\Data\RecordStepDeliveryRetry;
use Billium\Collection\Data\RegisterDunningPolicyVersion;
use Billium\Collection\Data\RegisterRecurringOwner;
use Billium\Collection\Data\RetireRecurringOwner;
use Billium\Collection\Data\TransitionCollectionStep;
use Billium\Collection\Enums\CollectionChannel;
use Billium\Collection\Enums\CollectionStepStatus;
use Billium\Collection\Enums\DeclineCategory;
use Billium\Collection\Enums\DunningCaseStatus;
use Billium\Collection\Enums\RecurringOwnerMode;
use Billium\Collection\Enums\RecurringOwnerStatus;
use Billium\Collection\Internal\Support\RetryJitter;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\P7\Support\CollectionTestCase;
use Billium\Core\Tests\P7\Support\DunningCaseStepRaceScenario;
use Billium\Core\Tests\P7\Support\RecurringOwnerRaceScenario;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\TestEnvironment;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use LogicException;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class DunningPolicyTest extends CollectionTestCase
{
    public function test_immutable_dunning_policy_versions_never_alter_existing_case_without_explicit_migration(): void
    {
        $v1 = $this->dunningPolicyService()->registerPolicyVersion($this->policyCommand());
        self::assertSame('pol-standard', $v1->policyRef);
        self::assertSame(1, $v1->policyVersion);
        self::assertSame(3, $v1->maxBusinessDeclineAttempts);
        self::assertSame(2, $v1->maxDeliveryRetries);
        self::assertCount(3, $v1->orderedSteps);
        self::assertNull($v1->supersedesPolicyId);

        // Idempotent replay with same and different commandKey returns identical row.
        $sameKey = $this->dunningPolicyService()->registerPolicyVersion($this->policyCommand());
        self::assertSame($v1->id, $sameKey->id);
        self::assertSame($v1->policyDigest, $sameKey->policyDigest);

        $otherKey = $this->dunningPolicyService()->registerPolicyVersion($this->policyCommand(commandKey: 'reg-pol-1-retry'));
        self::assertSame($v1->id, $otherKey->id);
        self::assertSame(1, $this->rows('billium_collection_dunning_policy'));

        // Conflicting payload for same (policyRef, policyVersion) rejects deterministically.
        try {
            $this->dunningPolicyService()->registerPolicyVersion($this->policyCommand(
                commandKey: 'reg-pol-1-conflict',
                maxDeliveryRetries: 5,
            ));
            self::fail('Conflicting DunningPolicy v1 must be rejected');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::IdempotencyConflict, $error->problem);
            self::assertSame('billium.collection.policy_version_conflict', $error->detail);
        }

        // Open dunning case pinned to policy v1.
        $openedAt = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $case = $this->dunningCaseService()->openCase(new OpenDunningCase(
            commandKey: 'open-case-1',
            scope: $this->collectionScope(),
            caseRef: 'case-1',
            billingAccountRef: 'account-a',
            obligationRef: 'obl-1',
            collectionGeneration: 1,
            policyRef: 'pol-standard',
            policyVersion: 1,
            currency: 'RUB',
            openAmountMinor: 10000,
            componentRef: 'comp-a',
            openedAt: $openedAt,
        ));
        self::assertSame($v1->id, $case->policyId);
        self::assertSame(1, $case->policyVersion);
        self::assertSame(3, $case->maxBusinessDeclineAttempts);
        self::assertSame(1, $case->caseRevision);
        self::assertSame(DunningCaseStatus::Open, $case->status);

        // Register policy v2 superseding v1 with different retry budgets and horizon.
        $v2 = $this->dunningPolicyService()->registerPolicyVersion($this->policyCommand(
            commandKey: 'reg-pol-2',
            policyVersion: 2,
            maxBusinessDeclineAttempts: 5,
            maxDeliveryRetries: 4,
            recoveryHorizonSeconds: 86400 * 14,
            supersedesPolicyVersion: 1,
        ));
        self::assertSame(2, $v2->policyVersion);
        self::assertSame($v1->id, $v2->supersedesPolicyId);
        self::assertSame(2, $this->rows('billium_collection_dunning_policy'));

        // Policy v1 remains completely untouched and existing dunning_case STILL pins v1.
        $reloadedV1 = $this->dunningQueries()->findPolicyVersion($this->collectionScope(), 'pol-standard', 1);
        self::assertNotNull($reloadedV1);
        self::assertSame($v1->policyDigest, $reloadedV1->policyDigest);
        self::assertSame(3, $reloadedV1->maxBusinessDeclineAttempts);

        $latest = $this->dunningQueries()->findLatestPolicyVersion($this->collectionScope(), 'pol-standard');
        self::assertNotNull($latest);
        self::assertSame(2, $latest->policyVersion);
        self::assertCount(2, $this->dunningQueries()->listPolicyVersions($this->collectionScope(), 'pol-standard'));

        $caseAfterPolicyUpdate = $this->dunningQueries()->findCase($this->collectionScope(), 'case-1');
        self::assertNotNull($caseAfterPolicyUpdate);
        self::assertSame($v1->id, $caseAfterPolicyUpdate->policyId);
        self::assertSame(1, $caseAfterPolicyUpdate->policyVersion);
        self::assertSame(3, $caseAfterPolicyUpdate->maxBusinessDeclineAttempts);
        self::assertSame(1, $caseAfterPolicyUpdate->caseRevision);
        self::assertSame(0, $this->rows('billium_collection_case_policy_migration'));

        // Explicit migration command with stale revision rejects without altering case.
        try {
            $this->dunningCaseService()->migrateCasePolicy(new MigrateDunningCasePolicy(
                commandKey: 'mig-stale',
                scope: $this->collectionScope(),
                migrationRef: 'mig-stale-1',
                caseRef: 'case-1',
                targetPolicyRef: 'pol-standard',
                targetPolicyVersion: 2,
                expectedCaseRevision: 99,
                reason: 'stale revision test',
            ));
            self::fail('Stale revision migration must reject');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::IdempotencyConflict, $error->problem);
            self::assertSame('billium.collection.case_revision_conflict', $error->detail);
        }

        // Explicit migration command to same version rejects.
        try {
            $this->dunningCaseService()->migrateCasePolicy(new MigrateDunningCasePolicy(
                commandKey: 'mig-same',
                scope: $this->collectionScope(),
                migrationRef: 'mig-same-1',
                caseRef: 'case-1',
                targetPolicyRef: 'pol-standard',
                targetPolicyVersion: 1,
                expectedCaseRevision: 1,
                reason: 'same version test',
            ));
            self::fail('Same-version migration must reject');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            self::assertSame('billium.collection.policy_migration_same_version', $error->detail);
        }

        // Valid explicit migration command moves case to v2 and records immutable audit row.
        $migration = $this->dunningCaseService()->migrateCasePolicy(new MigrateDunningCasePolicy(
            commandKey: 'mig-v2-1',
            scope: $this->collectionScope(),
            migrationRef: 'mig-v2-ref-1',
            caseRef: 'case-1',
            targetPolicyRef: 'pol-standard',
            targetPolicyVersion: 2,
            expectedCaseRevision: 1,
            reason: 'approved_customer_extension_to_v2',
        ));
        self::assertSame(1, $migration->fromPolicyVersion);
        self::assertSame(2, $migration->toPolicyVersion);
        self::assertSame(1, $migration->fromCaseRevision);
        self::assertSame(2, $migration->toCaseRevision);
        self::assertSame('approved_customer_extension_to_v2', $migration->reason);

        // Idempotent replay of migration returns identical record without second revision jump.
        $replayedMigration = $this->dunningCaseService()->migrateCasePolicy(new MigrateDunningCasePolicy(
            commandKey: 'mig-v2-1-retry',
            scope: $this->collectionScope(),
            migrationRef: 'mig-v2-ref-1',
            caseRef: 'case-1',
            targetPolicyRef: 'pol-standard',
            targetPolicyVersion: 2,
            expectedCaseRevision: 1,
            reason: 'approved_customer_extension_to_v2',
        ));
        self::assertSame($migration->id, $replayedMigration->id);

        $migratedCase = $this->dunningQueries()->findCase($this->collectionScope(), 'case-1');
        self::assertNotNull($migratedCase);
        self::assertSame($v2->id, $migratedCase->policyId);
        self::assertSame(2, $migratedCase->policyVersion);
        self::assertSame(5, $migratedCase->maxBusinessDeclineAttempts);
        self::assertSame(2, $migratedCase->caseRevision);
        self::assertCount(1, $this->dunningQueries()->listCasePolicyMigrations($this->collectionScope(), 'case-1'));
    }

    public function test_distinct_budgets_for_delivery_retries_vs_business_decline_attempts(): void
    {
        $this->dunningPolicyService()->registerPolicyVersion($this->policyCommand(
            commandKey: 'reg-budget-pol',
            policyRef: 'pol-budgets',
            maxBusinessDeclineAttempts: 2,
            maxDeliveryRetries: 2,
        ));

        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));
        $this->dunningCaseService()->openCase(new OpenDunningCase(
            commandKey: 'open-budget-case',
            scope: $this->collectionScope(),
            caseRef: 'case-budgets',
            billingAccountRef: 'account-a',
            obligationRef: 'obl-budgets',
            collectionGeneration: 1,
            policyRef: 'pol-budgets',
            policyVersion: 1,
            currency: 'RUB',
            openAmountMinor: 8000,
            openedAt: $t0,
        ));

        $step1 = $this->dunningCaseService()->planStep(new PlanCollectionStep(
            commandKey: 'plan-b-1',
            scope: $this->collectionScope(),
            caseRef: 'case-budgets',
            stepRef: 'step-b-1',
            stepNo: 1,
            businessPaymentIntentRef: 'bpi-budget-1',
            operationRef: 'op-budget-1',
            amountMinor: 8000,
            scheduledAt: $t0->modify('+60 seconds'),
            expectedCaseRevision: 1,
        ));
        self::assertSame(0, $step1->deliveryAttemptsUsed);
        self::assertSame(2, $step1->maxDeliveryRetries);

        // Delivery retries 1 and 2 preserve the exact same businessPaymentIntentRef and operationRef,
        // and NEVER consume businessDeclineAttemptsUsed.
        $retry1 = $this->dunningCaseService()->recordDeliveryRetry(new RecordStepDeliveryRetry(
            commandKey: 'del-retry-1',
            scope: $this->collectionScope(),
            caseRef: 'case-budgets',
            stepRef: 'step-b-1',
            expectedStepRevision: 1,
            attemptedAt: $t0->modify('+65 seconds'),
            failureReason: 'gateway_503_transport',
        ));
        self::assertSame(1, $retry1->deliveryAttemptsUsed);
        self::assertSame(2, $retry1->stepRevision);
        self::assertSame('bpi-budget-1', $retry1->businessPaymentIntentRef);
        self::assertSame('op-budget-1', $retry1->operationRef);

        $retry2 = $this->dunningCaseService()->recordDeliveryRetry(new RecordStepDeliveryRetry(
            commandKey: 'del-retry-2',
            scope: $this->collectionScope(),
            caseRef: 'case-budgets',
            stepRef: 'step-b-1',
            expectedStepRevision: 2,
            attemptedAt: $t0->modify('+70 seconds'),
            failureReason: 'socket_reset_before_ack',
        ));
        self::assertSame(2, $retry2->deliveryAttemptsUsed);
        self::assertSame(3, $retry2->stepRevision);

        // Exhausting delivery retry budget rejects deterministically and leaves businessDeclineAttemptsUsed == 0.
        try {
            $this->dunningCaseService()->recordDeliveryRetry(new RecordStepDeliveryRetry(
                commandKey: 'del-retry-3',
                scope: $this->collectionScope(),
                caseRef: 'case-budgets',
                stepRef: 'step-b-1',
                expectedStepRevision: 3,
                attemptedAt: $t0->modify('+75 seconds'),
                failureReason: 'third_transport_error',
            ));
            self::fail('Exceeding maxDeliveryRetries must reject');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::OperationFinancialHold, $error->problem);
            self::assertSame('billium.collection.delivery_retry_budget_exhausted', $error->detail);
        }

        $caseBeforeDecline = $this->dunningQueries()->findCase($this->collectionScope(), 'case-budgets');
        self::assertNotNull($caseBeforeDecline);
        self::assertSame(0, $caseBeforeDecline->businessDeclineAttemptsUsed);

        // Dispatch and record terminal retryable decline -> consumes businessDeclineAttemptsUsed (1 of 2).
        $dispatched1 = $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'disp-b-1',
            scope: $this->collectionScope(),
            caseRef: 'case-budgets',
            stepRef: 'step-b-1',
            targetStatus: CollectionStepStatus::Dispatched,
            expectedStepRevision: 3,
            occurredAt: $t0->modify('+80 seconds'),
        ));
        self::assertSame(CollectionStepStatus::Dispatched, $dispatched1->step->status);

        $declined1 = $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'decl-b-1',
            scope: $this->collectionScope(),
            caseRef: 'case-budgets',
            stepRef: 'step-b-1',
            targetStatus: CollectionStepStatus::FailedTerminal,
            expectedStepRevision: 4,
            occurredAt: $occurred = $t0->modify('+90 seconds'),
            declineCategory: DeclineCategory::InsufficientFunds,
            declineReason: 'insufficient_funds_attempt_1',
            outcomeEvidenceDigest: self::digest('1'),
        ));
        self::assertTrue($declined1->nextStepEligible);
        self::assertSame(2, $declined1->nextStepNo);
        self::assertSame(1, $declined1->case->businessDeclineAttemptsUsed);
        self::assertSame(DunningCaseStatus::Open, $declined1->case->status);
        // Step 2 delaySeconds=3600, backoffMultiplierBps=15000 (1.5x), maxBackoffSeconds=7200 => +5400s plus configured jitter.
        $jitter = RetryJitter::seconds('case-budgets', 'step-b-1', $occurred, 60);
        self::assertEquals($occurred->modify('+'.(5400 + $jitter).' seconds'), $declined1->nextEligibleAt);

        // Step 2 starts with a fresh independent delivery retry budget (0/2).
        $step2 = $this->dunningCaseService()->planStep(new PlanCollectionStep(
            commandKey: 'plan-b-2',
            scope: $this->collectionScope(),
            caseRef: 'case-budgets',
            stepRef: 'step-b-2',
            stepNo: 2,
            businessPaymentIntentRef: 'bpi-budget-2',
            operationRef: 'op-budget-2',
            amountMinor: 8000,
            scheduledAt: $t0->modify('+5500 seconds'),
        ));
        self::assertSame(0, $step2->deliveryAttemptsUsed);
        self::assertSame(2, $step2->maxDeliveryRetries);
        self::assertSame($step1->id, $step2->priorStepId);

        $active = $this->dunningQueries()->findCase($this->collectionScope(), 'case-budgets');
        self::assertNotNull($active);
        $this->dunningPolicyService()->registerPolicyVersion($this->policyCommand(
            'reg-budget-insufficient', 'pol-budgets', 2, maxBusinessDeclineAttempts: 1, supersedesPolicyVersion: 1,
        ));
        $this->dunningPolicyService()->registerPolicyVersion($this->policyCommand(
            'reg-budget-exact', 'pol-budgets', 3, maxBusinessDeclineAttempts: 2, maxDeliveryRetries: 1, supersedesPolicyVersion: 2,
        ));
        try {
            $this->dunningCaseService()->migrateCasePolicy(new MigrateDunningCasePolicy(
                'migrate-budget-insufficient', $this->collectionScope(), 'migration-insufficient', 'case-budgets',
                'pol-budgets', 2, $active->caseRevision, 'reduce decline budget',
            ));
            self::fail('Migration must reserve one decline for the planned step');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            self::assertSame('billium.collection.policy_budget_below_in_flight', $error->detail);
        }
        self::assertSame(0, $this->rows('billium_collection_case_policy_migration'));

        $this->dunningCaseService()->migrateCasePolicy(new MigrateDunningCasePolicy(
            'migrate-budget-exact', $this->collectionScope(), 'migration-exact', 'case-budgets',
            'pol-budgets', 3, $active->caseRevision, 'retain one decline for the planned step',
        ));

        // Step 2 terminal decline exhausts maxBusinessDeclineAttempts (2/2) even though delivery retries are 0/2 and policy has 3 steps!
        $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'disp-b-2',
            scope: $this->collectionScope(),
            caseRef: 'case-budgets',
            stepRef: 'step-b-2',
            targetStatus: CollectionStepStatus::Dispatched,
            expectedStepRevision: 1,
            occurredAt: $t0->modify('+5510 seconds'),
        ));
        $declined2 = $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'decl-b-2',
            scope: $this->collectionScope(),
            caseRef: 'case-budgets',
            stepRef: 'step-b-2',
            targetStatus: CollectionStepStatus::FailedTerminal,
            expectedStepRevision: 2,
            occurredAt: $t0->modify('+5520 seconds'),
            declineCategory: DeclineCategory::InsufficientFunds,
            declineReason: 'insufficient_funds_attempt_2',
            outcomeEvidenceDigest: self::digest('2'),
        ));
        self::assertFalse($declined2->nextStepEligible);
        self::assertNull($declined2->nextStepNo);
        self::assertSame(DunningCaseStatus::Exhausted, $declined2->case->status);
        self::assertSame('business_decline_budget_exhausted', $declined2->case->closedReason);
        self::assertSame(2, $declined2->case->businessDeclineAttemptsUsed);
        self::assertSame(0, $declined2->step->deliveryAttemptsUsed);
        self::assertSame(2, $declined2->step->maxDeliveryRetries);

        // Planning step 3 is refused because the case is exhausted.
        try {
            $this->dunningCaseService()->planStep(new PlanCollectionStep(
                commandKey: 'plan-b-3',
                scope: $this->collectionScope(),
                caseRef: 'case-budgets',
                stepRef: 'step-b-3',
                stepNo: 3,
                businessPaymentIntentRef: 'bpi-budget-3',
                operationRef: 'op-budget-3',
                amountMinor: 8000,
                scheduledAt: $t0->modify('+10000 seconds'),
            ));
            self::fail('Planning step 3 after business decline exhaustion must reject');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::OperationFinancialHold, $error->problem);
            self::assertSame('billium.collection.case_not_plannable', $error->detail);
        }
    }

    public function test_single_recurring_owner_rejects_dual_enablement_and_handles_concurrent_race(): void
    {
        $this->dunningPolicyService()->registerPolicyVersion($this->policyCommand(commandKey: 'reg-pol-owner'));

        // Case 1: Billium scheduler registered first -> provider autonomous subscription rejected deterministically.
        $billiumOwner = $this->dunningPolicyService()->registerRecurringOwner(new RegisterRecurringOwner(
            commandKey: 'reg-owner-1',
            scope: $this->collectionScope(),
            agreementRef: 'agr-1',
            componentRef: 'comp-billium',
            ownerMode: RecurringOwnerMode::BilliumScheduler,
            ownerRef: 'sched-1',
        ));
        self::assertSame(RecurringOwnerMode::BilliumScheduler, $billiumOwner->ownerMode);
        self::assertSame(RecurringOwnerStatus::Active, $billiumOwner->status);
        self::assertSame(1, $billiumOwner->ownerRevision);

        // Idempotent replay succeeds.
        $replay = $this->dunningPolicyService()->registerRecurringOwner(new RegisterRecurringOwner(
            commandKey: 'reg-owner-1-replay',
            scope: $this->collectionScope(),
            agreementRef: 'agr-1',
            componentRef: 'comp-billium',
            ownerMode: RecurringOwnerMode::BilliumScheduler,
            ownerRef: 'sched-1',
        ));
        self::assertSame($billiumOwner->id, $replay->id);

        // Enabling provider autonomous subscription for the same component rejects deterministically.
        try {
            $this->dunningPolicyService()->registerRecurringOwner(new RegisterRecurringOwner(
                commandKey: 'reg-owner-dual-1',
                scope: $this->collectionScope(),
                agreementRef: 'agr-1',
                componentRef: 'comp-billium',
                ownerMode: RecurringOwnerMode::ProviderAutonomousSubscription,
                ownerRef: 'prov-sub-1',
            ));
            self::fail('Dual recurring owner must reject');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::OperationFinancialHold, $error->problem);
            self::assertSame('billium.collection.recurring_owner_conflict', $error->detail);
        }

        // Billium scheduler check passes for Billium-owned component.
        $this->dunningPolicyService()->assertBilliumSchedulerAllowed($this->collectionScope(), 'comp-billium');

        // Case 2: Provider autonomous subscription registered first -> Billium scheduler & dunning_case rejected.
        $providerOwner = $this->dunningPolicyService()->registerRecurringOwner(new RegisterRecurringOwner(
            commandKey: 'reg-owner-2',
            scope: $this->collectionScope(),
            agreementRef: 'agr-2',
            componentRef: 'comp-provider',
            ownerMode: RecurringOwnerMode::ProviderAutonomousSubscription,
            ownerRef: 'prov-sub-2',
        ));
        self::assertSame(RecurringOwnerMode::ProviderAutonomousSubscription, $providerOwner->ownerMode);

        try {
            $this->dunningPolicyService()->registerRecurringOwner(new RegisterRecurringOwner(
                commandKey: 'reg-owner-dual-2',
                scope: $this->collectionScope(),
                agreementRef: 'agr-2',
                componentRef: 'comp-provider',
                ownerMode: RecurringOwnerMode::BilliumScheduler,
                ownerRef: 'sched-2',
            ));
            self::fail('Billium scheduler alongside provider autonomous subscription must reject');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::OperationFinancialHold, $error->problem);
            self::assertSame('billium.collection.recurring_owner_conflict', $error->detail);
        }

        try {
            $this->dunningPolicyService()->assertBilliumSchedulerAllowed($this->collectionScope(), 'comp-provider');
            self::fail('assertBilliumSchedulerAllowed must reject provider autonomous subscription');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::OperationFinancialHold, $error->problem);
            self::assertSame('billium.collection.provider_autonomous_subscription_forbidden', $error->detail);
        }

        try {
            $this->dunningCaseService()->openCase(new OpenDunningCase(
                commandKey: 'open-case-provider-comp',
                scope: $this->collectionScope(),
                caseRef: 'case-provider-forbidden',
                billingAccountRef: 'account-a',
                obligationRef: 'obl-provider-forbidden',
                collectionGeneration: 1,
                policyRef: 'pol-standard',
                policyVersion: 1,
                currency: 'RUB',
                openAmountMinor: 5000,
                componentRef: 'comp-provider',
            ));
            self::fail('Opening dunning_case on provider-autonomous-subscription component must reject');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::OperationFinancialHold, $error->problem);
            self::assertSame('billium.collection.provider_autonomous_subscription_forbidden', $error->detail);
        }
        self::assertSame(0, $this->rows('billium_collection_dunning_case'));

        // Explicit retirement allows clean handover to Billium scheduler.
        $retired = $this->dunningPolicyService()->retireRecurringOwner(new RetireRecurringOwner(
            commandKey: 'retire-prov-2',
            scope: $this->collectionScope(),
            componentRef: 'comp-provider',
            expectedOwnerRevision: 1,
            reason: 'migrated_off_provider_autonomous_subscription',
        ));
        self::assertSame(RecurringOwnerStatus::Retired, $retired->status);
        self::assertSame(2, $retired->ownerRevision);
        self::assertNull($this->dunningQueries()->findActiveRecurringOwner($this->collectionScope(), 'comp-provider'));

        $handedOver = $this->dunningPolicyService()->registerRecurringOwner(new RegisterRecurringOwner(
            commandKey: 'reg-owner-handover',
            scope: $this->collectionScope(),
            agreementRef: 'agr-2',
            componentRef: 'comp-provider',
            ownerMode: RecurringOwnerMode::BilliumScheduler,
            ownerRef: 'sched-handover-2',
        ));
        self::assertSame(RecurringOwnerMode::BilliumScheduler, $handedOver->ownerMode);
        self::assertSame(3, $handedOver->ownerRevision);

        $caseAfterHandover = $this->dunningCaseService()->openCase(new OpenDunningCase(
            commandKey: 'open-case-after-handover',
            scope: $this->collectionScope(),
            caseRef: 'case-after-handover',
            billingAccountRef: 'account-a',
            obligationRef: 'obl-after-handover',
            collectionGeneration: 1,
            policyRef: 'pol-standard',
            policyVersion: 1,
            currency: 'RUB',
            openAmountMinor: 5000,
            componentRef: 'comp-provider',
        ));
        self::assertSame(DunningCaseStatus::Open, $caseAfterHandover->status);

        // Multi-process concurrency race: 6 workers racing billium_scheduler vs provider_autonomous_subscription on the same component.
        $results = ConcurrencyStand::run(
            RecurringOwnerRaceScenario::class,
            6,
            ['agreementRef' => 'agr-race', 'componentRef' => 'comp-race-1'],
        );
        $accepted = 0;
        $rejected = 0;
        foreach ($results as $worker) {
            $body = self::ok($worker);
            if ($body['accepted'] === true) {
                $accepted++;
            } else {
                $rejected++;
                self::assertSame(ProblemCode::OperationFinancialHold->value, $body['problem']);
                self::assertSame('billium.collection.recurring_owner_conflict', $body['detail']);
            }
        }
        self::assertSame(1, $accepted);
        self::assertSame(5, $rejected);
        $activeCount = (int) $this->app['db']->connection('pgsql')
            ->table('billium_collection_recurring_owner')
            ->where('seller_ref', 'seller-a')
            ->where('environment', 'test')
            ->where('component_ref', 'comp-race-1')
            ->where('status', 'active')
            ->count();
        self::assertSame(1, $activeCount);
    }

    public function test_unknown_prior_step_parks_case_and_terminal_decline_creates_next_step_with_new_explicit_intent(): void
    {
        $this->dunningPolicyService()->registerPolicyVersion($this->policyCommand(commandKey: 'reg-pol-unk'));
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));

        $this->dunningCaseService()->openCase(new OpenDunningCase(
            commandKey: 'open-unk-case',
            scope: $this->collectionScope(),
            caseRef: 'case-unk',
            billingAccountRef: 'account-a',
            obligationRef: 'obl-unk',
            collectionGeneration: 1,
            policyRef: 'pol-standard',
            policyVersion: 1,
            currency: 'RUB',
            openAmountMinor: 12000,
            openedAt: $t0,
        ));

        $step1 = $this->dunningCaseService()->planStep(new PlanCollectionStep(
            commandKey: 'plan-u-1',
            scope: $this->collectionScope(),
            caseRef: 'case-unk',
            stepRef: 'step-u-1',
            stepNo: 1,
            businessPaymentIntentRef: 'bpi-u-1',
            operationRef: 'op-u-1',
            amountMinor: 12000,
            scheduledAt: $t0->modify('+60 seconds'),
        ));

        $auth = $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'auth-u-1',
            scope: $this->collectionScope(),
            caseRef: 'case-unk',
            stepRef: 'step-u-1',
            targetStatus: CollectionStepStatus::Authorized,
            expectedStepRevision: 1,
            occurredAt: $t0->modify('+61 seconds'),
            stepAuthorizationRef: 'step-auth-u-1',
        ));
        self::assertSame('step-auth-u-1', $auth->step->stepAuthorizationRef);

        $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'disp-u-1',
            scope: $this->collectionScope(),
            caseRef: 'case-unk',
            stepRef: 'step-u-1',
            targetStatus: CollectionStepStatus::Dispatched,
            expectedStepRevision: 2,
            occurredAt: $t0->modify('+62 seconds'),
        ));

        // Transitioning step 1 to Unknown atomically parks the dunning_case.
        $parked = $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'unk-u-1',
            scope: $this->collectionScope(),
            caseRef: 'case-unk',
            stepRef: 'step-u-1',
            targetStatus: CollectionStepStatus::Unknown,
            expectedStepRevision: 3,
            occurredAt: $t0->modify('+65 seconds'),
            declineReason: 'psp_timeout_after_send',
        ));
        self::assertSame(CollectionStepStatus::Unknown, $parked->step->status);
        self::assertSame(DunningCaseStatus::ParkedUnknown, $parked->case->status);
        self::assertSame('step-u-1', $parked->case->parkedStepRef);
        self::assertSame('psp_timeout_after_send', $parked->case->parkedReason);
        self::assertNull($parked->case->nextAttemptAt);
        self::assertFalse($parked->nextStepEligible);

        // Planning step 2 while step 1 is unknown is deterministically refused.
        try {
            $this->dunningCaseService()->planStep(new PlanCollectionStep(
                commandKey: 'plan-u-2-blocked',
                scope: $this->collectionScope(),
                caseRef: 'case-unk',
                stepRef: 'step-u-2',
                stepNo: 2,
                businessPaymentIntentRef: 'bpi-u-2',
                operationRef: 'op-u-2',
                amountMinor: 12000,
                scheduledAt: $t0->modify('+4000 seconds'),
            ));
            self::fail('Planning next step while case is parked_unknown must reject');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::OperationFinancialHold, $error->problem);
            self::assertSame('billium.collection.case_parked_unknown', $error->detail);
        }

        // Delivery retry on unknown step is forbidden (INV-04).
        try {
            $this->dunningCaseService()->recordDeliveryRetry(new RecordStepDeliveryRetry(
                commandKey: 'retry-u-1-blocked',
                scope: $this->collectionScope(),
                caseRef: 'case-unk',
                stepRef: 'step-u-1',
                expectedStepRevision: 4,
                attemptedAt: $t0->modify('+70 seconds'),
            ));
            self::fail('Delivery retry on unknown step must reject');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::OperationFinancialHold, $error->problem);
            self::assertSame('billium.collection.unknown_step_forbids_retry', $error->detail);
        }

        // Cancelling an unknown step directly is forbidden (must reconcile with evidence).
        try {
            $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
                commandKey: 'cancel-u-1-blocked',
                scope: $this->collectionScope(),
                caseRef: 'case-unk',
                stepRef: 'step-u-1',
                targetStatus: CollectionStepStatus::Cancelled,
                expectedStepRevision: 4,
                occurredAt: $t0->modify('+75 seconds'),
            ));
            self::fail('Unknown -> Cancelled transition must reject');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            self::assertSame('billium.collection.step_transition_invalid', $error->detail);
        }

        // Migrating policy while case is parked_unknown is forbidden.
        $this->dunningPolicyService()->registerPolicyVersion($this->policyCommand(
            commandKey: 'reg-pol-unk-v2',
            policyVersion: 2,
            maxBusinessDeclineAttempts: 4,
            supersedesPolicyVersion: 1,
        ));
        try {
            $this->dunningCaseService()->migrateCasePolicy(new MigrateDunningCasePolicy(
                commandKey: 'mig-parked-blocked',
                scope: $this->collectionScope(),
                migrationRef: 'mig-parked-1',
                caseRef: 'case-unk',
                targetPolicyRef: 'pol-standard',
                targetPolicyVersion: 2,
                expectedCaseRevision: $parked->case->caseRevision,
                reason: 'cannot migrate parked case',
            ));
            self::fail('Policy migration while parked_unknown must reject');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::OperationFinancialHold, $error->problem);
            self::assertSame('billium.collection.case_parked_unknown', $error->detail);
        }

        // Reconciling step 1 from Unknown -> FailedTerminal with evidence unparks the case and allows step 2.
        $reconciled = $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'reconcile-u-1-decline',
            scope: $this->collectionScope(),
            caseRef: 'case-unk',
            stepRef: 'step-u-1',
            targetStatus: CollectionStepStatus::FailedTerminal,
            expectedStepRevision: 4,
            occurredAt: $t0->modify('+120 seconds'),
            declineCategory: DeclineCategory::IssuerUnavailable,
            declineReason: 'reconciled_terminal_issuer_decline',
            outcomeEvidenceDigest: self::digest('d'),
        ));
        self::assertSame(CollectionStepStatus::FailedTerminal, $reconciled->step->status);
        self::assertSame(DunningCaseStatus::Open, $reconciled->case->status);
        self::assertNull($reconciled->case->parkedStepRef);
        self::assertNull($reconciled->case->parkedReason);
        self::assertTrue($reconciled->nextStepEligible);
        self::assertSame(2, $reconciled->nextStepNo);

        // Reusing prior step's businessPaymentIntentRef or operationRef for step 2 is refused!
        try {
            $this->dunningCaseService()->planStep(new PlanCollectionStep(
                commandKey: 'plan-u-2-reuse-bpi',
                scope: $this->collectionScope(),
                caseRef: 'case-unk',
                stepRef: 'step-u-2',
                stepNo: 2,
                businessPaymentIntentRef: 'bpi-u-1',
                operationRef: 'op-u-2',
                amountMinor: 12000,
                scheduledAt: $t0->modify('+6000 seconds'),
            ));
            self::fail('Reusing prior businessPaymentIntentRef must reject');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::IdempotencyConflict, $error->problem);
            self::assertSame('billium.collection.step_requires_new_explicit_intent', $error->detail);
        }

        try {
            $this->dunningCaseService()->planStep(new PlanCollectionStep(
                commandKey: 'plan-u-2-reuse-op',
                scope: $this->collectionScope(),
                caseRef: 'case-unk',
                stepRef: 'step-u-2',
                stepNo: 2,
                businessPaymentIntentRef: 'bpi-u-2',
                operationRef: 'op-u-1',
                amountMinor: 12000,
                scheduledAt: $t0->modify('+6000 seconds'),
            ));
            self::fail('Reusing prior operationRef must reject');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::IdempotencyConflict, $error->problem);
            self::assertSame('billium.collection.step_requires_new_explicit_intent', $error->detail);
        }

        // Planning step 2 with a NEW explicit businessPaymentIntentRef + operationRef succeeds.
        $step2 = $this->dunningCaseService()->planStep(new PlanCollectionStep(
            commandKey: 'plan-u-2-fresh',
            scope: $this->collectionScope(),
            caseRef: 'case-unk',
            stepRef: 'step-u-2',
            stepNo: 2,
            businessPaymentIntentRef: 'bpi-u-2',
            operationRef: 'op-u-2',
            amountMinor: 12000,
            scheduledAt: $t0->modify('+6000 seconds'),
        ));
        self::assertSame(2, $step2->stepNo);
        self::assertSame($step1->id, $step2->priorStepId);
        self::assertSame('bpi-u-2', $step2->businessPaymentIntentRef);
        self::assertSame('op-u-2', $step2->operationRef);
        self::assertSame(CollectionChannel::Mandate, $step2->channel);

        // Transition step 2 to Dispatched -> Succeeded -> case becomes Settled.
        $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'disp-u-2',
            scope: $this->collectionScope(),
            caseRef: 'case-unk',
            stepRef: 'step-u-2',
            targetStatus: CollectionStepStatus::Dispatched,
            expectedStepRevision: 1,
            occurredAt: $t0->modify('+6005 seconds'),
        ));
        $settled = $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'succ-u-2',
            scope: $this->collectionScope(),
            caseRef: 'case-unk',
            stepRef: 'step-u-2',
            targetStatus: CollectionStepStatus::Succeeded,
            expectedStepRevision: 2,
            occurredAt: $t0->modify('+6010 seconds'),
            outcomeEvidenceDigest: self::digest('f'),
        ));
        self::assertSame(CollectionStepStatus::Succeeded, $settled->step->status);
        self::assertSame(DunningCaseStatus::Settled, $settled->case->status);
        self::assertSame(0, $settled->case->currentOpenMinor);
        self::assertCount(2, $this->dunningQueries()->listCaseSteps($this->collectionScope(), 'case-unk'));
    }

    public function test_non_retryable_decline_exhausts_immediately_and_concurrent_step_planning_serializes_cleanly(): void
    {
        $this->dunningPolicyService()->registerPolicyVersion($this->policyCommand(commandKey: 'reg-pol-nr'));
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));

        $this->dunningCaseService()->openCase(new OpenDunningCase(
            commandKey: 'open-nr',
            scope: $this->collectionScope(),
            caseRef: 'case-nr',
            billingAccountRef: 'account-a',
            obligationRef: 'obl-nr',
            collectionGeneration: 1,
            policyRef: 'pol-standard',
            policyVersion: 1,
            currency: 'RUB',
            openAmountMinor: 5000,
            openedAt: $t0,
        ));

        $this->dunningCaseService()->planStep(new PlanCollectionStep(
            commandKey: 'plan-nr-1',
            scope: $this->collectionScope(),
            caseRef: 'case-nr',
            stepRef: 'step-nr-1',
            stepNo: 1,
            businessPaymentIntentRef: 'bpi-nr-1',
            operationRef: 'op-nr-1',
            amountMinor: 5000,
            scheduledAt: $t0->modify('+60 seconds'),
        ));

        $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'disp-nr-1',
            scope: $this->collectionScope(),
            caseRef: 'case-nr',
            stepRef: 'step-nr-1',
            targetStatus: CollectionStepStatus::Dispatched,
            expectedStepRevision: 1,
            occurredAt: $t0->modify('+65 seconds'),
        ));

        $exhausted = $this->dunningCaseService()->transitionStep(new TransitionCollectionStep(
            commandKey: 'decl-nr-1',
            scope: $this->collectionScope(),
            caseRef: 'case-nr',
            stepRef: 'step-nr-1',
            targetStatus: CollectionStepStatus::FailedTerminal,
            expectedStepRevision: 2,
            occurredAt: $t0->modify('+70 seconds'),
            declineCategory: DeclineCategory::FraudSuspected,
            declineReason: 'fraud_block',
            outcomeEvidenceDigest: self::digest('9'),
        ));
        self::assertFalse($exhausted->nextStepEligible);
        self::assertSame(DunningCaseStatus::Exhausted, $exhausted->case->status);
        self::assertSame('non_retryable_decline:fraud_suspected', $exhausted->case->closedReason);

        // Multi-process concurrency race on step 1 planning for a fresh case.
        $this->dunningCaseService()->openCase(new OpenDunningCase(
            commandKey: 'open-race-case',
            scope: $this->collectionScope(),
            caseRef: 'case-race-1',
            billingAccountRef: 'account-a',
            obligationRef: 'obl-race-1',
            collectionGeneration: 1,
            policyRef: 'pol-standard',
            policyVersion: 1,
            currency: 'RUB',
            openAmountMinor: 5000,
            openedAt: $t0,
        ));

        $results = ConcurrencyStand::run(
            DunningCaseStepRaceScenario::class,
            5,
            ['caseRef' => 'case-race-1', 'expectedCaseRevision' => 1],
        );
        $accepted = 0;
        $rejected = 0;
        foreach ($results as $worker) {
            $body = self::ok($worker);
            if ($body['accepted'] === true) {
                $accepted++;
            } else {
                $rejected++;
                self::assertSame(ProblemCode::IdempotencyConflict->value, $body['problem']);
            }
        }
        self::assertSame(1, $accepted);
        self::assertSame(4, $rejected);
        self::assertCount(1, $this->dunningQueries()->listCaseSteps($this->collectionScope(), 'case-race-1'));
    }

    public function test_refusal_rollback_seller_isolation_out_of_tx_guard_billium_connection_and_db_backstops(): void
    {
        $policy = $this->dunningPolicyService()->registerPolicyVersion($this->policyCommand(
            commandKey: 'reg-pol-guard',
            minOutstandingMinor: 500,
            thresholdCurrency: 'RUB',
        ));
        $t0 = new DateTimeImmutable('2026-11-20T00:00:00Z', new DateTimeZone('UTC'));

        // Refusal: below outstanding threshold rolls back cleanly with 0 case rows.
        try {
            $this->dunningCaseService()->openCase(new OpenDunningCase(
                commandKey: 'open-below-threshold',
                scope: $this->collectionScope(),
                caseRef: 'case-small',
                billingAccountRef: 'account-a',
                obligationRef: 'obl-small',
                collectionGeneration: 1,
                policyRef: $policy->policyRef,
                policyVersion: 1,
                currency: 'RUB',
                openAmountMinor: 499,
                openedAt: $t0,
            ));
            self::fail('Amount below policy minOutstandingMinor must reject');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            self::assertSame('billium.collection.below_outstanding_threshold', $error->detail);
        }
        self::assertSame(0, $this->rows('billium_collection_dunning_case'));

        // Open a valid case under seller-a.
        $case = $this->dunningCaseService()->openCase(new OpenDunningCase(
            commandKey: 'open-valid-guard',
            scope: $this->collectionScope(),
            caseRef: 'case-guard-1',
            billingAccountRef: 'account-a',
            obligationRef: 'obl-guard-1',
            collectionGeneration: 1,
            policyRef: $policy->policyRef,
            policyVersion: 1,
            currency: 'RUB',
            openAmountMinor: 5000,
            openedAt: $t0,
        ));
        $step = $this->dunningCaseService()->planStep(new PlanCollectionStep(
            commandKey: 'plan-guard-1',
            scope: $this->collectionScope(),
            caseRef: 'case-guard-1',
            stepRef: 'step-guard-1',
            stepNo: 1,
            businessPaymentIntentRef: 'bpi-guard-1',
            operationRef: 'op-guard-1',
            amountMinor: 5000,
            scheduledAt: $t0->modify('+60 seconds'),
        ));

        // Cross-seller isolation: seller-b cannot see or mutate seller-a's policy, case, or step.
        $sellerA = $this->callers->current();
        $sellerB = new Caller('seller-b', 'test', $sellerA->installationRef, 'actor-b', ['remote-billium'], ['source-b']);
        $this->application()->instance(CallerContext::class, new FixedCallerContext($sellerB));
        try {
            $queriesB = $this->application()->make(DunningQueries::class);
            self::assertNull($queriesB->findPolicyVersion(new CollectionScope('seller-b', 'test'), $policy->policyRef, 1));
            self::assertNull($queriesB->findCase(new CollectionScope('seller-b', 'test'), 'case-guard-1'));
            self::assertNull($queriesB->findStep(new CollectionScope('seller-b', 'test'), 'step-guard-1'));

            try {
                $queriesB->findCase(new CollectionScope('seller-a', 'test'), 'case-guard-1');
                self::fail('Cross-seller scope mismatch must throw AuthDenied');
            } catch (ProblemException $error) {
                self::assertSame(ProblemCode::AuthDenied, $error->problem);
            }
        } finally {
            $this->application()->instance(CallerContext::class, new FixedCallerContext($sellerA));
        }

        // Out-of-transaction guard using getRawPdo()/getRawReadPdo() rejects when inside an ambient DB transaction.
        $pgsql = $this->app['db']->connection('pgsql');
        $pgsql->beginTransaction();
        try {
            $this->dunningPolicyService()->registerPolicyVersion($this->policyCommand(
                commandKey: 'reg-pol-in-tx',
                policyRef: 'pol-in-tx',
            ));
            self::fail('Ambient DB transaction must be rejected by out-of-Tx guard');
        } catch (LogicException $error) {
            self::assertStringContainsString('outside every database transaction', $error->getMessage());
        } finally {
            $pgsql->rollBack();
        }

        // PostgreSQL runtime privilege and trigger backstops.
        try {
            $pgsql->table('billium_collection_dunning_policy')
                ->where('id', $policy->id)
                ->update(['max_delivery_retries' => 99]);
            self::fail('Runtime UPDATE on billium_collection_dunning_policy must be denied');
        } catch (QueryException $error) {
            self::assertContains($error->getCode(), ['42501', '23514']);
        }

        try {
            $pgsql->table('billium_collection_dunning_case')
                ->where('id', $case->id)
                ->delete();
            self::fail('Runtime DELETE on billium_collection_dunning_case must be denied');
        } catch (QueryException $error) {
            self::assertContains($error->getCode(), ['42501', '23514']);
        }

        try {
            // Skipping revision increment (+2 instead of +1) is rejected by DB trigger.
            $pgsql->table('billium_collection_step')
                ->where('id', $step->id)
                ->update(['step_revision' => 3]);
            self::fail('Non-monotonic step_revision update must be rejected by DB trigger');
        } catch (QueryException $error) {
            self::assertSame('23514', $error->getCode());
        }

        // Non-default billium.connection proof: point default to a broken connection while billium.connection stays valid.
        $config = $this->application()->make(Repository::class);
        $config->set('database.connections.billium_dedicated', TestEnvironment::connection());
        $config->set('database.connections.broken_default', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'nonexistent_db',
            'username' => 'nobody',
            'password' => 'none',
        ]);
        $config->set('database.default', 'broken_default');
        $config->set('billium.connection', 'billium_dedicated');
        $this->application()->forgetInstance(BilliumOptions::class);

        try {
            $dedicatedQueries = $this->application()->make(DunningQueries::class);
            $found = $dedicatedQueries->findCase($this->collectionScope(), 'case-guard-1');
            self::assertNotNull($found);
            self::assertSame($case->id, $found->id);

            $dedicatedPolicySvc = $this->application()->make(DunningPolicyService::class);
            $dedicatedPolicy = $dedicatedPolicySvc->registerPolicyVersion($this->policyCommand(
                commandKey: 'reg-pol-dedicated-conn',
                policyRef: 'pol-dedicated',
            ));
            self::assertSame('pol-dedicated', $dedicatedPolicy->policyRef);

            $dedicatedCaseSvc = $this->application()->make(DunningCaseService::class);
            $dedicatedCase = $dedicatedCaseSvc->openCase(new OpenDunningCase(
                commandKey: 'open-case-dedicated-conn',
                scope: $this->collectionScope(),
                caseRef: 'case-dedicated-1',
                billingAccountRef: 'account-a',
                obligationRef: 'obl-dedicated-1',
                collectionGeneration: 1,
                policyRef: 'pol-dedicated',
                policyVersion: 1,
                currency: 'RUB',
                openAmountMinor: 5000,
                openedAt: $t0,
            ));
            self::assertSame('case-dedicated-1', $dedicatedCase->caseRef);
        } finally {
            $config->set('database.default', 'pgsql');
            $config->set('billium.connection', 'pgsql');
            $this->application()->forgetInstance(BilliumOptions::class);
            $this->application()->make(DatabaseManager::class)->purge('billium_dedicated');
            $this->application()->make(DatabaseManager::class)->purge('broken_default');
        }
    }

    private function policyCommand(
        string $commandKey = 'reg-pol-1',
        string $policyRef = 'pol-standard',
        int $policyVersion = 1,
        int $maxBusinessDeclineAttempts = 3,
        int $maxDeliveryRetries = 2,
        int $recoveryHorizonSeconds = 86400 * 7,
        int $minOutstandingMinor = 0,
        ?string $thresholdCurrency = null,
        ?int $supersedesPolicyVersion = null,
    ): RegisterDunningPolicyVersion {
        return new RegisterDunningPolicyVersion(
            commandKey: $commandKey,
            scope: $this->collectionScope(),
            policyRef: $policyRef,
            policyVersion: $policyVersion,
            allowedChannels: [CollectionChannel::SavedMethod, CollectionChannel::Mandate],
            orderedSteps: [
                new DunningPolicyStepDefinition(
                    stepNo: 1,
                    channel: CollectionChannel::SavedMethod,
                    delaySeconds: 60,
                    backoffMultiplierBps: 10000,
                    maxBackoffSeconds: 60,
                    jitterMaxSeconds: 10,
                    sendNotification: true,
                    notificationTemplateRef: 'tpl-dunning-step-1',
                ),
                new DunningPolicyStepDefinition(
                    stepNo: 2,
                    channel: CollectionChannel::Mandate,
                    delaySeconds: 3600,
                    backoffMultiplierBps: 15000,
                    maxBackoffSeconds: 7200,
                    jitterMaxSeconds: 60,
                    sendNotification: true,
                    notificationTemplateRef: 'tpl-dunning-step-2',
                ),
                new DunningPolicyStepDefinition(
                    stepNo: 3,
                    channel: CollectionChannel::SavedMethod,
                    delaySeconds: 86400,
                    backoffMultiplierBps: 20000,
                    maxBackoffSeconds: 172800,
                    jitterMaxSeconds: 300,
                    sendNotification: false,
                ),
            ],
            maxBusinessDeclineAttempts: $maxBusinessDeclineAttempts,
            maxDeliveryRetries: $maxDeliveryRetries,
            retryableDeclineCategories: [
                DeclineCategory::InsufficientFunds,
                DeclineCategory::TemporaryNetwork,
                DeclineCategory::IssuerUnavailable,
                DeclineCategory::SoftDecline,
            ],
            recoveryHorizonSeconds: $recoveryHorizonSeconds,
            graceSignalAfterSeconds: 86400,
            readOnlySignalAfterSeconds: 86400 * 3,
            minOutstandingMinor: $minOutstandingMinor,
            thresholdCurrency: $thresholdCurrency,
            supersedesPolicyVersion: $supersedesPolicyVersion,
        );
    }
}
