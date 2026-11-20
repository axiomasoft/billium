<?php

declare(strict_types=1);

namespace Billium\Collection\Contracts;

use Billium\Collection\Data\AuthorizeCollection;
use Billium\Collection\Data\CollectionStepView;
use Billium\Collection\Data\DunningCasePolicyMigrationView;
use Billium\Collection\Data\DunningCaseTransitionResult;
use Billium\Collection\Data\DunningCaseView;
use Billium\Collection\Data\MigrateDunningCasePolicy;
use Billium\Collection\Data\OpenDunningCase;
use Billium\Collection\Data\PlanCollectionStep;
use Billium\Collection\Data\RecordStepDeliveryRetry;
use Billium\Collection\Data\TransitionCollectionStep;
use Packman\Attributes\Api;

/**
 * Manages dunning_case lifecycle, explicit policy migrations, and collection_step transitions with distinct budgets.
 */
#[Api(since: '1.0')]
interface DunningCaseService
{
    /**
     * Opens a dunning case pinned to an immutable DunningPolicy version, checking single recurring ownership.
     */
    public function openCase(OpenDunningCase $command): DunningCaseView;

    /**
     * Explicitly migrates a non-terminal, non-parked dunning case to another immutable DunningPolicy version.
     */
    public function migrateCasePolicy(MigrateDunningCasePolicy $command): DunningCasePolicyMigrationView;

    /**
     * Plans a collection step with an explicit new businessPaymentIntentRef and operationRef.
     * Refuses when a prior step is unknown (case parked) or in-flight, or when decline budget/policy does not permit it.
     */
    public function planStep(PlanCollectionStep $command): CollectionStepView;

    /** D63/F04: commits the executable step, incoming reservation, settlement intent and command together. */
    public function authorizeCollection(AuthorizeCollection $command): CollectionStepView;

    /**
     * Records a transport/command delivery retry against the step's distinct delivery retry budget,
     * preserving the same businessPaymentIntentRef and operationRef without consuming business decline budget.
     */
    public function recordDeliveryRetry(RecordStepDeliveryRetry $command): CollectionStepView;

    /**
     * Transitions a collection step (authorized/dispatched/unknown/succeeded/failed_terminal/cancelled).
     * Unknown parks the case; terminal decline consumes business decline budget and computes next step eligibility.
     */
    public function transitionStep(TransitionCollectionStep $command): DunningCaseTransitionResult;
}
