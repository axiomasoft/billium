<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Queries;

use Billium\Collection\Contracts\DunningQueries;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\CollectionStepView;
use Billium\Collection\Data\DunningCasePolicyMigrationView;
use Billium\Collection\Data\DunningCaseView;
use Billium\Collection\Data\DunningPolicyStepDefinition;
use Billium\Collection\Data\DunningPolicyView;
use Billium\Collection\Data\RecurringOwnerView;
use Billium\Collection\Enums\CollectionChannel;
use Billium\Collection\Enums\CollectionStepStatus;
use Billium\Collection\Enums\DeclineCategory;
use Billium\Collection\Enums\DunningCaseStatus;
use Billium\Collection\Enums\RecurringOwnerMode;
use Billium\Collection\Enums\RecurringOwnerStatus;
use Billium\Collection\Internal\Support\RowCast;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runtime\CallerContext;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use stdClass;

#[Internal]
final readonly class DatabaseDunningQueries implements DunningQueries
{
    public function __construct(
        private DatabaseManager $databases,
        private CallerContext $callers,
        private BilliumOptions $options,
    ) {}

    #[Override]
    public function findPolicyVersion(CollectionScope $scope, string $policyRef, int $policyVersion): ?DunningPolicyView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($policyRef, 'policyRef');

        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_dunning_policy')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('policy_ref', $policyRef)
            ->where('policy_version', $policyVersion)
            ->first();

        return $row === null ? null : self::hydratePolicy($row);
    }

    #[Override]
    public function findLatestPolicyVersion(CollectionScope $scope, string $policyRef): ?DunningPolicyView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($policyRef, 'policyRef');

        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_dunning_policy')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('policy_ref', $policyRef)
            ->orderByDesc('policy_version')
            ->first();

        return $row === null ? null : self::hydratePolicy($row);
    }

    /** @return list<DunningPolicyView> */
    #[Override]
    public function listPolicyVersions(CollectionScope $scope, string $policyRef): array
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($policyRef, 'policyRef');

        $rows = $this->databases->connection($this->options->connection)
            ->table('billium_collection_dunning_policy')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('policy_ref', $policyRef)
            ->orderBy('policy_version')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::hydratePolicy($row);
        }

        return $out;
    }

    #[Override]
    public function findActiveRecurringOwner(CollectionScope $scope, string $componentRef): ?RecurringOwnerView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($componentRef, 'componentRef');

        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_recurring_owner')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('component_ref', $componentRef)
            ->where('status', RecurringOwnerStatus::Active->value)
            ->first();

        return $row === null ? null : self::hydrateRecurringOwner($row);
    }

    #[Override]
    public function findCase(CollectionScope $scope, string $caseRef): ?DunningCaseView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($caseRef, 'caseRef');

        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_dunning_case')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('case_ref', $caseRef)
            ->first();

        return $row === null ? null : self::hydrateCase($row);
    }

    #[Override]
    public function findCaseByObligation(CollectionScope $scope, string $obligationRef, int $collectionGeneration): ?DunningCaseView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($obligationRef, 'obligationRef');

        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_dunning_case')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('obligation_ref', $obligationRef)
            ->where('collection_generation', $collectionGeneration)
            ->first();

        return $row === null ? null : self::hydrateCase($row);
    }

    /** @return list<CollectionStepView> */
    #[Override]
    public function listCaseSteps(CollectionScope $scope, string $caseRef): array
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($caseRef, 'caseRef');

        $rows = $this->databases->connection($this->options->connection)
            ->table('billium_collection_step')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('dunning_case_id', CaseRefLookup::caseId($caller->sellerRef, $caller->environment, $caseRef))
            ->where('case_ref', $caseRef)
            ->orderBy('step_no')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::hydrateStep($row);
        }

        return $out;
    }

    #[Override]
    public function findStep(CollectionScope $scope, string $stepRef): ?CollectionStepView
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($stepRef, 'stepRef');

        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_step')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('step_ref', $stepRef)
            ->first();

        return $row === null ? null : self::hydrateStep($row);
    }

    /** @return list<DunningCasePolicyMigrationView> */
    #[Override]
    public function listCasePolicyMigrations(CollectionScope $scope, string $caseRef): array
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($caseRef, 'caseRef');

        $rows = $this->databases->connection($this->options->connection)
            ->table('billium_collection_case_policy_migration')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('dunning_case_id', CaseRefLookup::caseId($caller->sellerRef, $caller->environment, $caseRef))
            ->where('case_ref', $caseRef)
            ->orderBy('to_case_revision')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = self::hydratePolicyMigration($row);
        }

        return $out;
    }

    public static function hydratePolicy(stdClass $row): DunningPolicyView
    {
        $channels = [];
        foreach (RowCast::jsonList($row->allowed_channels, 'allowed_channels') as $item) {
            $channels[] = CollectionChannel::from(RowCast::str($item, 'allowed_channels[]'));
        }

        $steps = [];
        foreach (RowCast::jsonList($row->ordered_steps, 'ordered_steps') as $item) {
            if (! is_array($item)) {
                throw new LogicException('Expected object item in ordered_steps');
            }
            $sendNotification = $item['sendNotification'] ?? false;
            if (! is_bool($sendNotification)) {
                throw new LogicException('Expected boolean for sendNotification');
            }
            $steps[] = new DunningPolicyStepDefinition(
                stepNo: RowCast::integer($item['stepNo'] ?? null, 'ordered_steps[].stepNo'),
                channel: CollectionChannel::from(RowCast::str($item['channel'] ?? null, 'ordered_steps[].channel')),
                delaySeconds: RowCast::integer($item['delaySeconds'] ?? null, 'ordered_steps[].delaySeconds'),
                backoffMultiplierBps: RowCast::integer($item['backoffMultiplierBps'] ?? null, 'ordered_steps[].backoffMultiplierBps'),
                maxBackoffSeconds: RowCast::integer($item['maxBackoffSeconds'] ?? null, 'ordered_steps[].maxBackoffSeconds'),
                jitterMaxSeconds: RowCast::integer($item['jitterMaxSeconds'] ?? null, 'ordered_steps[].jitterMaxSeconds'),
                sendNotification: $sendNotification,
                notificationTemplateRef: RowCast::optStr($item['notificationTemplateRef'] ?? null, 'ordered_steps[].notificationTemplateRef'),
            );
        }

        $retryable = [];
        foreach (RowCast::jsonList($row->retryable_decline_categories, 'retryable_decline_categories') as $item) {
            $retryable[] = DeclineCategory::from(RowCast::str($item, 'retryable_decline_categories[]'));
        }

        return new DunningPolicyView(
            id: RowCast::integer($row->id, 'id'),
            sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
            environment: RowCast::str($row->environment, 'environment'),
            policyRef: RowCast::str($row->policy_ref, 'policy_ref'),
            policyVersion: RowCast::integer($row->policy_version, 'policy_version'),
            allowedChannels: $channels,
            orderedSteps: $steps,
            maxBusinessDeclineAttempts: RowCast::integer($row->max_business_decline_attempts, 'max_business_decline_attempts'),
            maxDeliveryRetries: RowCast::integer($row->max_delivery_retries, 'max_delivery_retries'),
            retryableDeclineCategories: $retryable,
            recoveryHorizonSeconds: RowCast::integer($row->recovery_horizon_seconds, 'recovery_horizon_seconds'),
            graceSignalAfterSeconds: RowCast::optInt($row->grace_signal_after_seconds, 'grace_signal_after_seconds'),
            readOnlySignalAfterSeconds: RowCast::optInt($row->read_only_signal_after_seconds, 'read_only_signal_after_seconds'),
            minOutstandingMinor: RowCast::integer($row->min_outstanding_minor, 'min_outstanding_minor'),
            thresholdCurrency: RowCast::optStr($row->threshold_currency, 'threshold_currency'),
            supersedesPolicyId: RowCast::optInt($row->supersedes_policy_id, 'supersedes_policy_id'),
            policyDigest: RowCast::str($row->policy_digest, 'policy_digest'),
            createdAt: RowCast::utcDateTime($row->created_at, 'created_at'),
        );
    }

    public static function hydrateRecurringOwner(stdClass $row): RecurringOwnerView
    {
        return new RecurringOwnerView(
            id: RowCast::integer($row->id, 'id'),
            sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
            environment: RowCast::str($row->environment, 'environment'),
            agreementRef: RowCast::str($row->agreement_ref, 'agreement_ref'),
            componentRef: RowCast::str($row->component_ref, 'component_ref'),
            ownerMode: RecurringOwnerMode::from(RowCast::str($row->owner_mode, 'owner_mode')),
            ownerRef: RowCast::str($row->owner_ref, 'owner_ref'),
            ownerRevision: RowCast::integer($row->owner_revision, 'owner_revision'),
            status: RecurringOwnerStatus::from(RowCast::str($row->status, 'status')),
            retiredReason: RowCast::optStr($row->retired_reason, 'retired_reason'),
            configDigest: RowCast::str($row->config_digest, 'config_digest'),
            recordedAt: RowCast::utcDateTime($row->recorded_at, 'recorded_at'),
            updatedAt: RowCast::utcDateTime($row->updated_at, 'updated_at'),
        );
    }

    public static function hydrateCase(stdClass $row): DunningCaseView
    {
        return new DunningCaseView(
            id: RowCast::integer($row->id, 'id'),
            sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
            environment: RowCast::str($row->environment, 'environment'),
            caseRef: RowCast::str($row->case_ref, 'case_ref'),
            billingAccountRef: RowCast::str($row->billing_account_ref, 'billing_account_ref'),
            obligationRef: RowCast::str($row->obligation_ref, 'obligation_ref'),
            componentRef: RowCast::optStr($row->component_ref, 'component_ref'),
            collectionGeneration: RowCast::integer($row->collection_generation, 'collection_generation'),
            policyId: RowCast::integer($row->policy_id, 'policy_id'),
            policyRef: RowCast::str($row->policy_ref, 'policy_ref'),
            policyVersion: RowCast::integer($row->policy_version, 'policy_version'),
            currency: RowCast::str($row->currency, 'currency'),
            initialAmountMinor: RowCast::integer($row->initial_amount_minor, 'initial_amount_minor'),
            currentOpenMinor: RowCast::integer($row->current_open_minor, 'current_open_minor'),
            status: DunningCaseStatus::from(RowCast::str($row->status, 'status')),
            caseRevision: RowCast::integer($row->case_revision, 'case_revision'),
            cancellationGeneration: RowCast::integer($row->cancellation_generation, 'cancellation_generation'),
            businessDeclineAttemptsUsed: RowCast::integer($row->business_decline_attempts_used, 'business_decline_attempts_used'),
            maxBusinessDeclineAttempts: RowCast::integer($row->max_business_decline_attempts, 'max_business_decline_attempts'),
            activeStepNo: RowCast::optInt($row->active_step_no, 'active_step_no'),
            lastCompletedStepNo: RowCast::integer($row->last_completed_step_no, 'last_completed_step_no'),
            nextAttemptAt: RowCast::optUtcDateTime($row->next_attempt_at, 'next_attempt_at'),
            recoveryDeadlineAt: RowCast::utcDateTime($row->recovery_deadline_at, 'recovery_deadline_at'),
            parkedReason: RowCast::optStr($row->parked_reason, 'parked_reason'),
            parkedStepRef: RowCast::optStr($row->parked_step_ref, 'parked_step_ref'),
            closedReason: RowCast::optStr($row->closed_reason, 'closed_reason'),
            closedAt: RowCast::optUtcDateTime($row->closed_at, 'closed_at'),
            openedAt: RowCast::utcDateTime($row->opened_at, 'opened_at'),
            updatedAt: RowCast::utcDateTime($row->updated_at, 'updated_at'),
            caseDigest: RowCast::str($row->case_digest, 'case_digest'),
        );
    }

    public static function hydratePolicyMigration(stdClass $row): DunningCasePolicyMigrationView
    {
        return new DunningCasePolicyMigrationView(
            id: RowCast::integer($row->id, 'id'),
            sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
            environment: RowCast::str($row->environment, 'environment'),
            migrationRef: RowCast::str($row->migration_ref, 'migration_ref'),
            dunningCaseId: RowCast::integer($row->dunning_case_id, 'dunning_case_id'),
            caseRef: RowCast::str($row->case_ref, 'case_ref'),
            fromPolicyRef: RowCast::str($row->from_policy_ref, 'from_policy_ref'),
            fromPolicyVersion: RowCast::integer($row->from_policy_version, 'from_policy_version'),
            toPolicyRef: RowCast::str($row->to_policy_ref, 'to_policy_ref'),
            toPolicyVersion: RowCast::integer($row->to_policy_version, 'to_policy_version'),
            fromCaseRevision: RowCast::integer($row->from_case_revision, 'from_case_revision'),
            toCaseRevision: RowCast::integer($row->to_case_revision, 'to_case_revision'),
            reason: RowCast::str($row->reason, 'reason'),
            actorRef: RowCast::str($row->actor_ref, 'actor_ref'),
            migrationDigest: RowCast::str($row->migration_digest, 'migration_digest'),
            migratedAt: RowCast::utcDateTime($row->migrated_at, 'migrated_at'),
        );
    }

    public static function hydrateStep(stdClass $row): CollectionStepView
    {
        $declineCategoryRaw = RowCast::optStr($row->decline_category, 'decline_category');

        return new CollectionStepView(
            id: RowCast::integer($row->id, 'id'),
            sellerRef: RowCast::str($row->seller_ref, 'seller_ref'),
            environment: RowCast::str($row->environment, 'environment'),
            dunningCaseId: RowCast::integer($row->dunning_case_id, 'dunning_case_id'),
            caseRef: RowCast::str($row->case_ref, 'case_ref'),
            stepRef: RowCast::str($row->step_ref, 'step_ref'),
            stepNo: RowCast::integer($row->step_no, 'step_no'),
            policyId: RowCast::integer($row->policy_id, 'policy_id'),
            policyRef: RowCast::str($row->policy_ref, 'policy_ref'),
            policyVersion: RowCast::integer($row->policy_version, 'policy_version'),
            channel: CollectionChannel::from(RowCast::str($row->channel, 'channel')),
            businessPaymentIntentRef: RowCast::str($row->business_payment_intent_ref, 'business_payment_intent_ref'),
            operationRef: RowCast::str($row->operation_ref, 'operation_ref'),
            stepAuthorizationRef: RowCast::optStr($row->step_authorization_ref, 'step_authorization_ref'),
            currency: RowCast::str($row->currency, 'currency'),
            amountMinor: RowCast::integer($row->amount_minor, 'amount_minor'),
            status: CollectionStepStatus::from(RowCast::str($row->status, 'status')),
            stepRevision: RowCast::integer($row->step_revision, 'step_revision'),
            deliveryAttemptsUsed: RowCast::integer($row->delivery_attempts_used, 'delivery_attempts_used'),
            maxDeliveryRetries: RowCast::integer($row->max_delivery_retries, 'max_delivery_retries'),
            priorStepId: RowCast::optInt($row->prior_step_id, 'prior_step_id'),
            declineCategory: $declineCategoryRaw === null ? null : DeclineCategory::from($declineCategoryRaw),
            declineReason: RowCast::optStr($row->decline_reason, 'decline_reason'),
            outcomeEvidenceDigest: RowCast::optStr($row->outcome_evidence_digest, 'outcome_evidence_digest'),
            scheduledAt: RowCast::utcDateTime($row->scheduled_at, 'scheduled_at'),
            lastDeliveryAttemptAt: RowCast::optUtcDateTime($row->last_delivery_attempt_at, 'last_delivery_attempt_at'),
            terminalAt: RowCast::optUtcDateTime($row->terminal_at, 'terminal_at'),
            createdAt: RowCast::utcDateTime($row->created_at, 'created_at'),
            updatedAt: RowCast::utcDateTime($row->updated_at, 'updated_at'),
            stepDigest: RowCast::str($row->step_digest, 'step_digest'),
        );
    }
}
