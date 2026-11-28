<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Services;

use Billium\Collection\Contracts\ConsentBindingService;
use Billium\Collection\Contracts\DunningCaseService;
use Billium\Collection\Contracts\PinnedCollectionInputs;
use Billium\Collection\Data\AuthorizeCollection;
use Billium\Collection\Data\CollectionStepView;
use Billium\Collection\Data\DunningCasePolicyMigrationView;
use Billium\Collection\Data\DunningCaseTransitionResult;
use Billium\Collection\Data\DunningCaseView;
use Billium\Collection\Data\DunningPolicyStepDefinition;
use Billium\Collection\Data\DunningPolicyView;
use Billium\Collection\Data\MigrateDunningCasePolicy;
use Billium\Collection\Data\OpenDunningCase;
use Billium\Collection\Data\PlanCollectionStep;
use Billium\Collection\Data\RecordStepDeliveryRetry;
use Billium\Collection\Data\TransitionCollectionStep;
use Billium\Collection\Enums\CollectionStepStatus;
use Billium\Collection\Enums\DunningCaseStatus;
use Billium\Collection\Enums\RecurringOwnerMode;
use Billium\Collection\Enums\RecurringOwnerStatus;
use Billium\Collection\Internal\Canonical\CanonicalJson;
use Billium\Collection\Internal\CollectionReceipt;
use Billium\Collection\Internal\Queries\DatabaseDunningQueries;
use Billium\Collection\Internal\Reducers\DunningStepPlanner;
use Billium\Collection\Internal\Reducers\DunningTransitionReducer;
use Billium\Collection\Internal\Support\RowCast;
use Billium\Collection\Internal\Validation\ObligationCurrencyGuard;
use Billium\Collection\Internal\Work\CollectionWork;
use Billium\Collection\Internal\Work\CollectionWorkClaim;
use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Envelopes\AuthorizeCollection as WireAuthorization;
use Billium\Contracts\Data\Envelopes\CommandReceipt;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runner\LockScope;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Execution\Data\ExecutionScope;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use Billium\Platform\Data\Idempotency\PinnedCommand;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use PDO;
use stdClass;

#[Internal]
final readonly class DatabaseDunningCaseService implements DunningCaseService
{
    public const int DUNNING_CASE_LOCK_SEGMENT = 2;

    /** @var list<string> */
    private const array COLLECTION_MODULES = ['platform', 'ledger', 'execution', 'collection'];

    private const string DIRECT_LIFECYCLE_PERMISSION = 'billium.collection.direct_step_lifecycle';

    public function __construct(
        private DatabaseManager $databases,
        private OperationRunner $runner,
        private CallerContext $callers,
        private BilliumOptions $options,
        private CollectionAdmission $admission,
        private ConsentBindingService $consents,
        private DatabaseCollectionParticipants $participants,
        private IdempotencyReceiptQueries $receipts,
        private CollectionWork $work,
        private ObligationCurrencyGuard $obligationCurrency,
        private DunningTransitionReducer $transitionReducer = new DunningTransitionReducer,
        private DunningStepPlanner $stepPlanner = new DunningStepPlanner,
    ) {}

    #[Override]
    public function openCase(OpenDunningCase $command): DunningCaseView
    {
        $this->assertOutsideTransaction();
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->caseRef, 'caseRef');
        RowCast::bounded($command->billingAccountRef, 'billingAccountRef');
        RowCast::bounded($command->obligationRef, 'obligationRef');
        RowCast::bounded($command->policyRef, 'policyRef');
        $currency = RowCast::currency($command->currency, 'currency');
        if ($command->componentRef !== null) {
            RowCast::bounded($command->componentRef, 'componentRef');
        }
        if ($command->collectionGeneration < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'collectionGeneration must be >= 1');
        }
        if ($command->policyVersion < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'policyVersion must be >= 1');
        }
        if ($command->openAmountMinor <= 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'openAmountMinor must be positive');
        }
        if ($command->cancellationGeneration < 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'cancellationGeneration must be nonnegative');
        }

        $caseDigest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'caseRef' => $command->caseRef,
            'billingAccountRef' => $command->billingAccountRef,
            'obligationRef' => $command->obligationRef,
            'componentRef' => $command->componentRef,
            'collectionGeneration' => $command->collectionGeneration,
            'policyRef' => $command->policyRef,
            'policyVersion' => $command->policyVersion,
            'currency' => $currency,
            'openAmountMinor' => $command->openAmountMinor,
            'cancellationGeneration' => $command->cancellationGeneration,
            'openedAt' => $command->openedAt === null ? null : RowCast::formatUtc($command->openedAt),
        ]);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'collection.dunning_case.open.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::NewIntent,
                modules: self::COLLECTION_MODULES,
                commandKey: $command->commandKey,
                digest: $caseDigest,
                commitKind: 'collection.dunning_case.open',
            ),
            body: function (TransactionContext $context) use ($caller, $command, $currency, $caseDigest): CollectionReceipt {
                $this->runner->assertCurrent($context, 'collection');
                $db = $this->databases->connection($context->connectionName());
                $this->obligationCurrency->assertMatches($context, $caller, $command->billingAccountRef, $command->obligationRef, $currency);

                if ($command->componentRef !== null) {
                    $activeOwner = $db->table('billium_collection_recurring_owner')
                        ->where('seller_ref', $caller->sellerRef)
                        ->where('environment', $caller->environment)
                        ->where('component_ref', $command->componentRef)
                        ->where('status', RecurringOwnerStatus::Active->value)
                        ->first();

                    if ($activeOwner !== null) {
                        $ownerId = RowCast::integer($activeOwner->id, 'id');
                        $locked = $context->lock(new LockRequest(
                            LockRank::DunningCase,
                            'billium_collection_recurring_owner',
                            [$ownerId],
                            scope: LockScope::Seller,
                            segment: DatabaseDunningPolicyService::RECURRING_OWNER_LOCK_SEGMENT,
                        ));
                        if ($locked !== [$ownerId]) {
                            throw new LockPlanChanged('Recurring owner row vanished before lock');
                        }
                        $activeOwner = $db->table('billium_collection_recurring_owner')
                            ->where('id', $ownerId)
                            ->first() ?? throw new LockPlanChanged('Recurring owner missing after lock');

                        if (
                            RowCast::str($activeOwner->status, 'status') === RecurringOwnerStatus::Active->value
                            && RowCast::str($activeOwner->owner_mode, 'owner_mode') === RecurringOwnerMode::ProviderAutonomousSubscription->value
                        ) {
                            throw new ProblemException(
                                ProblemCode::OperationFinancialHold,
                                'billium.collection.provider_autonomous_subscription_forbidden',
                            );
                        }
                    }
                }

                $existingByObligation = $db->table('billium_collection_dunning_case')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('obligation_ref', $command->obligationRef)
                    ->where('collection_generation', $command->collectionGeneration)
                    ->first();

                if ($existingByObligation !== null) {
                    if (
                        RowCast::str($existingByObligation->case_ref, 'case_ref') === $command->caseRef
                        && RowCast::str($existingByObligation->case_digest, 'case_digest') === $caseDigest
                    ) {
                        return new CollectionReceipt('collection.dunning_case.opened', [
                            'caseId' => RowCast::integer($existingByObligation->id, 'id'),
                            'caseRef' => $command->caseRef,
                        ]);
                    }

                    throw new ProblemException(
                        ProblemCode::IdempotencyConflict,
                        'billium.collection.dunning_case_conflict',
                    );
                }

                $existingByRef = $db->table('billium_collection_dunning_case')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('case_ref', $command->caseRef)
                    ->first();

                if ($existingByRef !== null) {
                    throw new ProblemException(
                        ProblemCode::IdempotencyConflict,
                        'billium.collection.dunning_case_conflict',
                    );
                }

                $policyRow = $db->table('billium_collection_dunning_policy')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('policy_ref', $command->policyRef)
                    ->where('policy_version', $command->policyVersion)
                    ->first();

                if ($policyRow === null) {
                    throw new ProblemException(
                        ProblemCode::PolicyUnknown,
                        'billium.collection.dunning_policy_not_found',
                    );
                }

                $policy = DatabaseDunningQueries::hydratePolicy($policyRow);
                if ($policy->minOutstandingMinor > 0) {
                    if ($policy->thresholdCurrency !== $currency) {
                        throw new ProblemException(
                            ProblemCode::MoneyCurrencyMismatch,
                            'billium.collection.policy_threshold_currency_mismatch',
                        );
                    }
                    if ($command->openAmountMinor < $policy->minOutstandingMinor) {
                        throw new ProblemException(
                            ProblemCode::ValidationInvalid,
                            'billium.collection.below_outstanding_threshold',
                        );
                    }
                }

                $utc = new DateTimeZone('UTC');
                $openedAt = ($command->openedAt ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
                $now = new DateTimeImmutable('now', $utc);
                $recoveryDeadline = $openedAt->modify('+'.$policy->recoveryHorizonSeconds.' seconds');
                $firstStep = self::stepDefinition($policy, 1);
                $nextAttemptAt = $firstStep === null
                    ? null
                    : $openedAt->modify('+'.$firstStep->delaySeconds.' seconds');

                $inserted = $db->selectOne(
                    <<<'SQL'
                    INSERT INTO public.billium_collection_dunning_case (
                        seller_ref, environment, case_ref, billing_account_ref,
                        obligation_ref, component_ref, collection_generation,
                        policy_id, policy_ref, policy_version,
                        currency, initial_amount_minor, current_open_minor,
                        status, case_revision, cancellation_generation,
                        business_decline_attempts_used, max_business_decline_attempts,
                        active_step_no, last_completed_step_no,
                        next_attempt_at, recovery_deadline_at,
                        parked_reason, parked_step_ref, closed_reason, closed_at,
                        opened_at, updated_at, case_digest
                    ) VALUES (
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        'open', 1, ?,
                        0, ?,
                        NULL, 0,
                        ?, ?,
                        NULL, NULL, NULL, NULL,
                        ?, ?, ?
                    )
                    ON CONFLICT DO NOTHING
                    RETURNING id
                    SQL,
                    [
                        $caller->sellerRef,
                        $caller->environment,
                        $command->caseRef,
                        $command->billingAccountRef,
                        $command->obligationRef,
                        $command->componentRef,
                        $command->collectionGeneration,
                        $policy->id,
                        $policy->policyRef,
                        $policy->policyVersion,
                        $currency,
                        $command->openAmountMinor,
                        $command->openAmountMinor,
                        $command->cancellationGeneration,
                        $policy->maxBusinessDeclineAttempts,
                        $nextAttemptAt === null ? null : RowCast::formatUtc($nextAttemptAt),
                        RowCast::formatUtc($recoveryDeadline),
                        RowCast::formatUtc($openedAt),
                        RowCast::formatUtc($now),
                        $caseDigest,
                    ],
                );

                if (! $inserted instanceof stdClass) {
                    $raced = $db->table('billium_collection_dunning_case')
                        ->where('seller_ref', $caller->sellerRef)
                        ->where('environment', $caller->environment)
                        ->where('obligation_ref', $command->obligationRef)
                        ->where('collection_generation', $command->collectionGeneration)
                        ->first();

                    if (
                        $raced !== null
                        && RowCast::str($raced->case_ref, 'case_ref') === $command->caseRef
                        && RowCast::str($raced->case_digest, 'case_digest') === $caseDigest
                    ) {
                        $caseId = RowCast::integer($raced->id, 'id');
                    } else {
                        throw new ProblemException(
                            ProblemCode::IdempotencyConflict,
                            'billium.collection.dunning_case_conflict',
                        );
                    }
                } else {
                    $caseId = RowCast::integer($inserted->id, 'id');
                }

                return new CollectionReceipt('collection.dunning_case.opened', [
                    'caseId' => $caseId,
                    'caseRef' => $command->caseRef,
                ]);
            },
        )->orThrow();

        $caseId = RowCast::integer($outcome->body['caseId'] ?? null, 'caseId');
        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_dunning_case')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('id', $caseId)
            ->first() ?? throw new LogicException('Opened dunning case missing');

        return DatabaseDunningQueries::hydrateCase($row);
    }

    #[Override]
    public function migrateCasePolicy(MigrateDunningCasePolicy $command): DunningCasePolicyMigrationView
    {
        $this->assertOutsideTransaction();
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->migrationRef, 'migrationRef');
        RowCast::bounded($command->caseRef, 'caseRef');
        RowCast::bounded($command->targetPolicyRef, 'targetPolicyRef');
        RowCast::bounded($command->reason, 'reason', 500);
        if ($command->targetPolicyVersion < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'targetPolicyVersion must be >= 1');
        }
        if ($command->expectedCaseRevision < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'expectedCaseRevision must be >= 1');
        }

        $migrationDigest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'migrationRef' => $command->migrationRef,
            'caseRef' => $command->caseRef,
            'targetPolicyRef' => $command->targetPolicyRef,
            'targetPolicyVersion' => $command->targetPolicyVersion,
            'expectedCaseRevision' => $command->expectedCaseRevision,
            'reason' => $command->reason,
        ]);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'collection.dunning_case.migrate_policy.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::FrozenIntentCompletion,
                modules: self::COLLECTION_MODULES,
                commandKey: $command->commandKey,
                digest: $migrationDigest,
                commitKind: 'collection.dunning_case.migrate_policy',
            ),
            body: function (TransactionContext $context) use ($caller, $command, $migrationDigest): CollectionReceipt {
                $this->runner->assertCurrent($context, 'collection');
                $db = $this->databases->connection($context->connectionName());

                $caseRow = $db->table('billium_collection_dunning_case')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('case_ref', $command->caseRef)
                    ->first();

                if ($caseRow === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.collection.dunning_case_not_found');
                }

                $caseId = RowCast::integer($caseRow->id, 'id');
                $locked = $context->lock(new LockRequest(
                    LockRank::DunningCase,
                    'billium_collection_dunning_case',
                    [$caseId],
                    scope: LockScope::Seller,
                    segment: self::DUNNING_CASE_LOCK_SEGMENT,
                ));
                if ($locked !== [$caseId]) {
                    throw new LockPlanChanged('Dunning case vanished before lock');
                }

                $existingMigration = $db->table('billium_collection_case_policy_migration')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('migration_ref', $command->migrationRef)
                    ->first();

                if ($existingMigration !== null) {
                    if (RowCast::str($existingMigration->migration_digest, 'migration_digest') !== $migrationDigest) {
                        throw new ProblemException(ProblemCode::IdempotencyConflict, 'billium.collection.policy_migration_conflict');
                    }

                    return new CollectionReceipt('collection.dunning_case.policy_migrated', [
                        'migrationId' => RowCast::integer($existingMigration->id, 'id'),
                        'migrationRef' => $command->migrationRef,
                    ]);
                }

                $caseRow = $db->table('billium_collection_dunning_case')
                    ->where('id', $caseId)
                    ->first() ?? throw new LockPlanChanged('Dunning case missing after lock');
                $case = DatabaseDunningQueries::hydrateCase($caseRow);

                if (in_array($case->status, [DunningCaseStatus::Exhausted, DunningCaseStatus::Settled, DunningCaseStatus::Cancelled], true)) {
                    throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.case_terminal');
                }
                if ($case->status === DunningCaseStatus::ParkedUnknown) {
                    throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.case_parked_unknown');
                }
                if ($case->caseRevision !== $command->expectedCaseRevision) {
                    throw new ProblemException(ProblemCode::IdempotencyConflict, 'billium.collection.case_revision_conflict');
                }

                $targetPolicyRow = $db->table('billium_collection_dunning_policy')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('policy_ref', $command->targetPolicyRef)
                    ->where('policy_version', $command->targetPolicyVersion)
                    ->first();

                if ($targetPolicyRow === null) {
                    throw new ProblemException(ProblemCode::PolicyUnknown, 'billium.collection.dunning_policy_not_found');
                }

                $targetPolicy = DatabaseDunningQueries::hydratePolicy($targetPolicyRow);
                if ($targetPolicy->id === $case->policyId) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.collection.policy_migration_same_version');
                }
                if ($targetPolicy->minOutstandingMinor > 0 && $targetPolicy->thresholdCurrency !== $case->currency) {
                    throw new ProblemException(ProblemCode::MoneyCurrencyMismatch, 'billium.collection.policy_threshold_currency_mismatch');
                }
                if ($targetPolicy->maxBusinessDeclineAttempts < $case->businessDeclineAttemptsUsed) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.collection.policy_budget_below_consumed');
                }
                $inFlight = $db->table('billium_collection_step')->where('dunning_case_id', $caseId)
                    ->whereIn('status', [
                        CollectionStepStatus::Planned->value,
                        CollectionStepStatus::Authorized->value,
                        CollectionStepStatus::Dispatched->value,
                        CollectionStepStatus::Unknown->value,
                    ])->count();
                if ($targetPolicy->maxBusinessDeclineAttempts - $case->businessDeclineAttemptsUsed < $inFlight) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.collection.policy_budget_below_in_flight');
                }

                $now = RowCast::formatUtc(new DateTimeImmutable('now', new DateTimeZone('UTC')));
                $newDeadline = $case->openedAt->modify('+'.$targetPolicy->recoveryHorizonSeconds.' seconds');
                $newRevision = $case->caseRevision + 1;

                $updated = $db->table('billium_collection_dunning_case')
                    ->where('id', $caseId)
                    ->where('case_revision', $case->caseRevision)
                    ->update([
                        'policy_id' => $targetPolicy->id,
                        'policy_ref' => $targetPolicy->policyRef,
                        'policy_version' => $targetPolicy->policyVersion,
                        'max_business_decline_attempts' => $targetPolicy->maxBusinessDeclineAttempts,
                        'recovery_deadline_at' => RowCast::formatUtc($newDeadline),
                        'case_revision' => $newRevision,
                        'updated_at' => $now,
                    ]);

                if ($updated !== 1) {
                    throw new LockPlanChanged('Dunning case policy migration CAS failed');
                }

                $inserted = $db->selectOne(
                    <<<'SQL'
                    INSERT INTO public.billium_collection_case_policy_migration (
                        seller_ref, environment, migration_ref, dunning_case_id, case_ref,
                        from_policy_id, from_policy_ref, from_policy_version,
                        to_policy_id, to_policy_ref, to_policy_version,
                        from_case_revision, to_case_revision,
                        reason, actor_ref, migration_digest, migrated_at
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?,
                        ?, ?, ?, ?
                    )
                    RETURNING id
                    SQL,
                    [
                        $caller->sellerRef,
                        $caller->environment,
                        $command->migrationRef,
                        $caseId,
                        $case->caseRef,
                        $case->policyId,
                        $case->policyRef,
                        $case->policyVersion,
                        $targetPolicy->id,
                        $targetPolicy->policyRef,
                        $targetPolicy->policyVersion,
                        $case->caseRevision,
                        $newRevision,
                        $command->reason,
                        $caller->actorRef,
                        $migrationDigest,
                        $now,
                    ],
                );

                if (! $inserted instanceof stdClass) {
                    throw new LogicException('Policy migration insert did not return an id');
                }

                return new CollectionReceipt('collection.dunning_case.policy_migrated', [
                    'migrationId' => RowCast::integer($inserted->id, 'id'),
                    'migrationRef' => $command->migrationRef,
                ]);
            },
        )->orThrow();

        $migrationId = RowCast::integer($outcome->body['migrationId'] ?? null, 'migrationId');
        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_case_policy_migration')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('id', $migrationId)
            ->first() ?? throw new LogicException('Policy migration record missing');

        return DatabaseDunningQueries::hydratePolicyMigration($row);
    }

    #[Override]
    public function planStep(PlanCollectionStep $command): CollectionStepView
    {
        return $this->createStep($command, null);
    }

    #[Override]
    public function authorizeCollection(AuthorizeCollection $command): CollectionStepView
    {
        return $this->createStep($command->step, $command);
    }

    public function authorizePinnedCollection(WireAuthorization $command, PinnedCollectionInputs $inputs): CommandReceipt
    {
        $codec = new Codec;
        $command = $codec->decode($codec->encode($command));
        assert($command instanceof WireAuthorization);
        $wire = new PinnedCommand($this->callers->current(), $command, $command->installationRef, $command->sellerRef, $command->environment, $command->commandKey);
        $stored = $this->receipts->replayCommand($wire->scope, $wire->kind(), $wire->key, $wire->inputDigest);
        if ($stored !== null) {
            return $wire->result($stored);
        }
        $authorization = $inputs->resolve($wire->scope, $command) ?? throw new ProblemException(ProblemCode::ReferenceUnknown);
        if ($authorization->step->businessPaymentIntentRef !== $command->businessPaymentIntentRef) {
            throw new ProblemException(ProblemCode::AuthDenied);
        }

        return $wire->result($this->createStepOutcome($authorization->step, $authorization, wire: $wire, obligationRef: $command->obligationRef));
    }

    public function authorizeDueCollection(AuthorizeCollection $command, DateTimeImmutable $dueAsOf): CollectionStepView
    {
        return $this->createStep($command->step, $command, $dueAsOf);
    }

    private function createStep(PlanCollectionStep $command, ?AuthorizeCollection $authorization, ?DateTimeImmutable $dueAsOf = null): CollectionStepView
    {
        $outcome = $this->createStepOutcome($command, $authorization, $dueAsOf)->orThrow();
        $stepId = RowCast::integer($outcome->body['stepId'] ?? null, 'stepId');
        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_step')
            ->where('seller_ref', $command->scope->sellerRef)
            ->where('environment', $command->scope->environment)
            ->where('id', $stepId)
            ->first() ?? throw new LogicException('Planned collection step missing');

        return DatabaseDunningQueries::hydrateStep($row);
    }

    private function createStepOutcome(PlanCollectionStep $command, ?AuthorizeCollection $authorization, ?DateTimeImmutable $dueAsOf = null,
        ?PinnedCommand $wire = null, ?string $obligationRef = null): StoredOutcome
    {
        $this->assertOutsideTransaction();
        $automaticDue = $dueAsOf !== null;
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        if ($authorization === null) {
            self::assertDirectLifecyclePermission($caller);
        }
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->caseRef, 'caseRef');
        RowCast::bounded($command->stepRef, 'stepRef');
        RowCast::bounded($command->businessPaymentIntentRef, 'businessPaymentIntentRef');
        RowCast::bounded($command->operationRef, 'operationRef');
        if ($command->stepAuthorizationRef !== null) {
            RowCast::bounded($command->stepAuthorizationRef, 'stepAuthorizationRef');
        }
        if ($command->stepNo < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'stepNo must be >= 1');
        }
        if ($command->amountMinor <= 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'amountMinor must be positive');
        }
        if ($command->expectedCaseRevision !== null && $command->expectedCaseRevision < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'expectedCaseRevision must be >= 1');
        }

        $executionScope = $authorization?->executionScope;
        $authorizationRef = $command->stepAuthorizationRef;
        if ($authorization !== null && $authorizationRef === null) {
            $authorizationRef = 'collection:'.hash('sha256', CanonicalJson::encode([
                $caller->installationRef, $caller->sellerRef, $caller->environment, $command->caseRef, $command->stepNo,
            ]));
        }
        $stepDigest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'caseRef' => $command->caseRef,
            'stepRef' => $command->stepRef,
            'stepNo' => $command->stepNo,
            'businessPaymentIntentRef' => $command->businessPaymentIntentRef,
            'operationRef' => $command->operationRef,
            'stepAuthorizationRef' => $command->stepAuthorizationRef,
            'amountMinor' => $authorization === null ? $command->amountMinor : (string) $command->amountMinor,
            'scheduledAt' => RowCast::formatUtc($command->scheduledAt),
        ] + ($authorization === null ? [] : [
            'executionScope' => $executionScope,
            'executionProfile' => $authorization->executionProfile,
            'routingEpoch' => $authorization->routingEpoch,
            'consentRef' => $authorization->consentRef,
            'consentVersion' => $authorization->consentVersion,
            'expectedCaseRevision' => $command->expectedCaseRevision,
        ]) + ($automaticDue ? ['automaticDue' => true] : []));

        $kind = $wire?->kind() ?? ($authorization === null ? 'collection.step.plan.v1' : 'collection.authorize.v1');
        $financialScope = $authorization === null ? null : new FinancialScope($authorization->executionScope->installationRef,
            $authorization->executionScope->sellerRef, $authorization->executionScope->environment,
            $authorization->executionScope->intentOwnerNamespace, $authorization->executionProfile);
        $cached = $wire !== null || $financialScope === null ? null : $this->receipts->byCommandKey($financialScope, $kind, $command->commandKey);
        if ($cached !== null && $cached->inputDigest !== $stepDigest) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'Collection command key has different terms');
        }
        if ($cached !== null) {
            return $cached->outcome;
        }
        $prepared = $authorization === null ? null : $this->admission->prepare($caller, $authorization, $obligationRef);

        return $this->runner->run(
            spec: new OperationSpec(
                commandKind: $kind,
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::NewIntent,
                modules: self::COLLECTION_MODULES,
                commandKey: $wire->key ?? $command->commandKey,
                digest: $stepDigest,
                commitKind: $authorization === null ? 'collection.step.plan' : 'collection.authorize',
                financialScope: $financialScope,
                inputDigest: $wire->inputDigest ?? ($authorization === null ? null : $stepDigest),
            ),
            body: PinnedCommand::retaining($wire, function (TransactionContext $context) use ($caller, $command, $stepDigest, $authorization, $authorizationRef, $prepared, $dueAsOf, $automaticDue): CollectionReceipt {
                $this->runner->assertCurrent($context, 'collection');
                $db = $this->databases->connection($context->connectionName());

                $caseRow = $db->table('billium_collection_dunning_case')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('case_ref', $command->caseRef)
                    ->first();

                if ($caseRow === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.collection.dunning_case_not_found');
                }

                $recurringOwnerId = null;
                if ($authorization !== null && $prepared !== null) {
                    $this->admission->lockMerchantAndIdentity($context, $authorization, $prepared['merchant']);
                    $this->consents->lockGuard($context, $command->scope, $authorization->consentRef);
                    $recurringOwnerId = $this->lockCollectionOwner($context, $db, $prepared['componentRef']);
                }
                $caseId = RowCast::integer($caseRow->id, 'id');
                $locked = $context->lock(new LockRequest(
                    LockRank::DunningCase,
                    'billium_collection_dunning_case',
                    [$caseId],
                    scope: LockScope::Seller,
                    segment: self::DUNNING_CASE_LOCK_SEGMENT,
                    guardedRoutine: $automaticDue ? 'billium_lock_collection_dunning_case' : null,
                ));
                if ($locked !== [$caseId]) {
                    if ($automaticDue) {
                        throw new ProblemException(ProblemCode::RecoveryRequired, 'billium.collection.due_case_busy');
                    }
                    throw new LockPlanChanged('Dunning case vanished before lock');
                }

                $caseRow = $db->table('billium_collection_dunning_case')
                    ->where('id', $caseId)
                    ->first() ?? throw new LockPlanChanged('Dunning case missing after lock');
                $case = DatabaseDunningQueries::hydrateCase($caseRow);
                if ($prepared !== null && ($case->obligationRef !== $prepared['obligationRef']
                    || ($case->componentRef !== null && $case->componentRef !== $prepared['componentRef']))) {
                    throw new ProblemException(ProblemCode::AuthDenied, 'Collection case differs from the ledger obligation');
                }

                $existingByStepNo = $db->table('billium_collection_step')
                    ->where('dunning_case_id', $caseId)
                    ->where('step_no', $command->stepNo)
                    ->first();

                if ($existingByStepNo !== null) {
                    if (
                        RowCast::str($existingByStepNo->step_ref, 'step_ref') === $command->stepRef
                        && RowCast::str($existingByStepNo->step_digest, 'step_digest') === $stepDigest
                    ) {
                        return new CollectionReceipt($authorization === null ? 'collection.step.planned' : 'collection.step.authorized', [
                            'stepId' => RowCast::integer($existingByStepNo->id, 'id'),
                            'stepRef' => $command->stepRef,
                        ]);
                    }

                    throw new ProblemException(ProblemCode::IdempotencyConflict, 'billium.collection.step_conflict');
                }

                $existingByRef = $db->table('billium_collection_step')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('step_ref', $command->stepRef)
                    ->first();

                if ($existingByRef !== null) {
                    throw new ProblemException(ProblemCode::IdempotencyConflict, 'billium.collection.step_conflict');
                }

                if ($command->expectedCaseRevision !== null && $case->caseRevision !== $command->expectedCaseRevision) {
                    throw new ProblemException(ProblemCode::IdempotencyConflict, 'billium.collection.case_revision_conflict');
                }
                if ($dueAsOf !== null && ($case->status !== DunningCaseStatus::Open || $case->activeStepNo !== null || $case->nextAttemptAt === null
                    || $case->nextAttemptAt > $dueAsOf)) {
                    throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.case_not_due');
                }
                $policyRow = $db->table('billium_collection_dunning_policy')
                    ->where('id', $case->policyId)
                    ->first() ?? throw new LogicException('Pinned dunning policy missing');
                $policy = DatabaseDunningQueries::hydratePolicy($policyRow);

                $existingSteps = $db->table('billium_collection_step')
                    ->where('dunning_case_id', $caseId)
                    ->orderBy('step_no')
                    ->get();

                $hydratedSteps = [];
                foreach ($existingSteps as $row) {
                    $hydratedSteps[] = DatabaseDunningQueries::hydrateStep($row);
                }
                $priorStepId = $this->stepPlanner->validateSequence($case, $policy, $hydratedSteps, $command->stepNo);

                $duplicateIntent = $db->table('billium_collection_step')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where(static function (Builder $query) use ($command): void {
                        $query->where('business_payment_intent_ref', $command->businessPaymentIntentRef)
                            ->orWhere('operation_ref', $command->operationRef);
                    })
                    ->first();

                if ($duplicateIntent !== null) {
                    throw new ProblemException(
                        ProblemCode::IdempotencyConflict,
                        'billium.collection.step_requires_new_explicit_intent',
                    );
                }

                $stepDef = $this->stepPlanner->resolveStepDefinition($policy, $command->stepNo);
                $amount = $command->amountMinor;
                $issuedAt = Date::now()->toDateTimeImmutable();
                if ($authorization !== null) {
                    $amount = $this->admission->lockAndSize($context, $db, $authorization, $case, $stepDef->channel, $authorizationRef,
                        $prepared['binding'], $prepared['merchant'], $prepared['host'], $prepared['componentRef'], $dueAsOf, $issuedAt);
                }
                $planDecision = $this->stepPlanner->decide(
                    case: $case,
                    policy: $policy,
                    stepDefinition: $stepDef,
                    priorStepId: $priorStepId,
                    amountMinor: $amount,
                    scheduledAt: $command->scheduledAt,
                    enforceOpenMinorCap: $authorization === null,
                );
                $priorStepId = $planDecision->priorStepId;
                $stepDef = $planDecision->stepDefinition;
                $amount = $planDecision->amountMinor;

                $now = RowCast::formatUtc($issuedAt);
                $scheduledAtUtc = RowCast::formatUtc($command->scheduledAt);

                $inserted = $db->selectOne(
                    <<<'SQL'
                            INSERT INTO public.billium_collection_step (
                                seller_ref, environment, dunning_case_id, case_ref, step_ref, step_no,
                                policy_id, policy_ref, policy_version, channel,
                                business_payment_intent_ref, operation_ref, step_authorization_ref,
                                currency, amount_minor, status, step_revision,
                                delivery_attempts_used, max_delivery_retries, prior_step_id,
                                decline_category, decline_reason, outcome_evidence_digest,
                                scheduled_at, last_delivery_attempt_at, terminal_at,
                                created_at, updated_at, step_digest
                            ) VALUES (
                                ?, ?, ?, ?, ?, ?,
                                ?, ?, ?, ?,
                                ?, ?, ?,
                                ?, ?, ?, 1,
                                0, ?, ?,
                                NULL, NULL, NULL,
                                ?, NULL, NULL,
                                ?, ?, ?
                            )
                            ON CONFLICT DO NOTHING
                            RETURNING id
                            SQL,
                    [
                        $caller->sellerRef,
                        $caller->environment,
                        $caseId,
                        $case->caseRef,
                        $command->stepRef,
                        $command->stepNo,
                        $policy->id,
                        $policy->policyRef,
                        $policy->policyVersion,
                        $stepDef->channel->value,
                        $command->businessPaymentIntentRef,
                        $command->operationRef,
                        $authorizationRef,
                        $case->currency,
                        $amount,
                        $authorization === null ? CollectionStepStatus::Planned->value : CollectionStepStatus::Authorized->value,
                        $policy->maxDeliveryRetries,
                        $priorStepId,
                        $scheduledAtUtc,
                        $now,
                        $now,
                        $stepDigest,
                    ],
                );

                $updated = $db->table('billium_collection_dunning_case')
                    ->where('id', $caseId)
                    ->where('case_revision', $case->caseRevision)
                    ->update([
                        'status' => DunningCaseStatus::Active->value,
                        'active_step_no' => $command->stepNo,
                        'next_attempt_at' => $scheduledAtUtc,
                        'case_revision' => $case->caseRevision + 1,
                        'updated_at' => $now,
                    ]);

                if ($updated !== 1) {
                    throw new LockPlanChanged('Dunning case step plan CAS failed');
                }

                if (! $inserted instanceof stdClass) {
                    throw new ProblemException(ProblemCode::IdempotencyConflict, 'billium.collection.step_conflict');
                }

                $stepId = RowCast::integer($inserted->id, 'id');
                if ($authorization !== null) {
                    $this->admission->record($context, $db, $authorization, $case, $prepared['binding'], $prepared['merchant'], $authorizationRef, $stepId, $amount, $now,
                        $recurringOwnerId, $prepared['componentRef'], $automaticDue);
                }

                return new CollectionReceipt($authorization === null ? 'collection.step.planned' : 'collection.step.authorized', [
                    'stepId' => $stepId,
                    'stepRef' => $command->stepRef,
                ]);
            }),
        );
    }

    #[Override]
    public function recordDeliveryRetry(RecordStepDeliveryRetry $command): CollectionStepView
    {
        $this->assertOutsideTransaction();
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->caseRef, 'caseRef');
        RowCast::bounded($command->stepRef, 'stepRef');
        if ($command->expectedStepRevision < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'expectedStepRevision must be >= 1');
        }
        if ($command->failureReason !== null) {
            RowCast::bounded($command->failureReason, 'failureReason', 500);
        }

        $digest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'caseRef' => $command->caseRef,
            'stepRef' => $command->stepRef,
            'expectedStepRevision' => $command->expectedStepRevision,
            'attemptedAt' => RowCast::formatUtc($command->attemptedAt),
            'failureReason' => $command->failureReason,
        ]);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'collection.step.delivery_retry.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::FrozenIntentCompletion,
                modules: self::COLLECTION_MODULES,
                commandKey: $command->commandKey,
                digest: $digest,
                commitKind: 'collection.step.delivery_retry',
            ),
            body: function (TransactionContext $context) use ($caller, $command): CollectionReceipt {
                $this->runner->assertCurrent($context, 'collection');
                $db = $this->databases->connection($context->connectionName());

                $caseRow = $db->table('billium_collection_dunning_case')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('case_ref', $command->caseRef)
                    ->first();

                if ($caseRow === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.collection.dunning_case_not_found');
                }

                $caseId = RowCast::integer($caseRow->id, 'id');
                $locked = $context->lock(new LockRequest(
                    LockRank::DunningCase,
                    'billium_collection_dunning_case',
                    [$caseId],
                    scope: LockScope::Seller,
                    segment: self::DUNNING_CASE_LOCK_SEGMENT,
                ));
                if ($locked !== [$caseId]) {
                    throw new LockPlanChanged('Dunning case vanished before lock');
                }

                $stepRow = $db->table('billium_collection_step')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('dunning_case_id', $caseId)
                    ->where('step_ref', $command->stepRef)
                    ->first();

                if ($stepRow === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.collection.step_not_found');
                }

                $step = DatabaseDunningQueries::hydrateStep($stepRow);
                if ($step->stepRevision !== $command->expectedStepRevision) {
                    throw new ProblemException(ProblemCode::IdempotencyConflict, 'billium.collection.step_revision_conflict');
                }
                if ($step->status === CollectionStepStatus::Unknown) {
                    throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.unknown_step_forbids_retry');
                }
                if (in_array($step->status, [
                    CollectionStepStatus::Succeeded,
                    CollectionStepStatus::FailedTerminal,
                    CollectionStepStatus::Cancelled,
                ], true)) {
                    throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.step_terminal');
                }
                if ($step->deliveryAttemptsUsed >= $step->maxDeliveryRetries) {
                    throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.delivery_retry_budget_exhausted');
                }

                $now = RowCast::formatUtc(new DateTimeImmutable('now', new DateTimeZone('UTC')));
                $updated = $db->table('billium_collection_step')
                    ->where('id', $step->id)
                    ->where('step_revision', $step->stepRevision)
                    ->update([
                        'delivery_attempts_used' => $step->deliveryAttemptsUsed + 1,
                        'step_revision' => $step->stepRevision + 1,
                        'last_delivery_attempt_at' => RowCast::formatUtc($command->attemptedAt),
                        'decline_reason' => $command->failureReason ?? $step->declineReason,
                        'updated_at' => $now,
                    ]);

                if ($updated !== 1) {
                    throw new LockPlanChanged('Collection step delivery retry CAS failed');
                }

                return new CollectionReceipt('collection.step.delivery_retried', [
                    'stepId' => $step->id,
                    'stepRef' => $step->stepRef,
                    'deliveryAttemptsUsed' => $step->deliveryAttemptsUsed + 1,
                ]);
            },
        )->orThrow();

        $stepId = RowCast::integer($outcome->body['stepId'] ?? null, 'stepId');
        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_step')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('id', $stepId)
            ->first() ?? throw new LogicException('Retried collection step missing');

        return DatabaseDunningQueries::hydrateStep($row);
    }

    #[Override]
    public function transitionStep(TransitionCollectionStep $command): DunningCaseTransitionResult
    {
        return $this->transition($command);
    }

    public function transitionClaimedStep(TransitionCollectionStep $command, CollectionWorkClaim $claim, ?int $executionRevision = null): DunningCaseTransitionResult
    {
        return $this->transition($command, $claim, $executionRevision);
    }

    private function transition(TransitionCollectionStep $command, ?CollectionWorkClaim $claim = null, ?int $executionRevision = null): DunningCaseTransitionResult
    {
        $this->assertOutsideTransaction();
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        if ($claim === null) {
            self::assertDirectLifecyclePermission($caller);
        }
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->caseRef, 'caseRef');
        RowCast::bounded($command->stepRef, 'stepRef');
        if ($command->expectedStepRevision < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'expectedStepRevision must be >= 1');
        }
        if ($command->declineReason !== null) {
            RowCast::bounded($command->declineReason, 'declineReason', 500);
        }
        if ($command->outcomeEvidenceDigest !== null) {
            RowCast::digest($command->outcomeEvidenceDigest, 'outcomeEvidenceDigest');
        }
        if ($command->stepAuthorizationRef !== null) {
            RowCast::bounded($command->stepAuthorizationRef, 'stepAuthorizationRef');
        }
        if ($command->updatedOpenMinor !== null && $command->updatedOpenMinor < 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'updatedOpenMinor must be nonnegative');
        }

        $digest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'caseRef' => $command->caseRef,
            'stepRef' => $command->stepRef,
            'targetStatus' => $command->targetStatus->value,
            'expectedStepRevision' => $command->expectedStepRevision,
            'occurredAt' => RowCast::formatUtc($command->occurredAt),
            'declineCategory' => $command->declineCategory?->value,
            'declineReason' => $command->declineReason,
            'outcomeEvidenceDigest' => $command->outcomeEvidenceDigest,
            'stepAuthorizationRef' => $command->stepAuthorizationRef,
            'updatedOpenMinor' => $command->updatedOpenMinor,
        ] + ($claim === null ? [] : ['executionRevision' => $executionRevision]));

        $authorization = $claim === null ? $this->participants->transitionAuthorization($command) : null;
        $financialScope = $claim->scope ?? $authorization['scope'] ?? null;
        $operationRef = $claim === null ? ($authorization['operationRef'] ?? null) : RowCast::str($claim->authorization->operation_ref, 'operation_ref');

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'collection.step.transition.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: $claim->mode ?? FinancialMode::Active,
                operationClass: $claim === null ? ModuleOperationClass::FrozenIntentCompletion : ModuleOperationClass::UnknownResolution,
                modules: self::COLLECTION_MODULES,
                commandKey: $command->commandKey,
                digest: $digest,
                commitKind: 'collection.step.transition',
                financialScope: $financialScope,
            ),
            body: function (TransactionContext $context) use ($caller, $command, $claim, $executionRevision, $financialScope, $operationRef): CollectionReceipt {
                $this->runner->assertCurrent($context, 'collection');
                $db = $this->databases->connection($context->connectionName());
                $plan = $financialScope === null || $operationRef === null ? null : $this->participants->prelockOutcome($context,
                    new ExecutionScope($caller->installationRef, $caller->sellerRef, $caller->environment,
                        RowCast::str($financialScope->intentOwnerNamespace, 'intentOwnerNamespace')), $operationRef);

                $caseRow = $db->table('billium_collection_dunning_case')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('case_ref', $command->caseRef)
                    ->first();

                if ($caseRow === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.collection.dunning_case_not_found');
                }

                $caseId = RowCast::integer($caseRow->id, 'id');
                $locked = $context->lock(new LockRequest(
                    LockRank::DunningCase,
                    'billium_collection_dunning_case',
                    [$caseId],
                    scope: LockScope::Seller,
                    segment: self::DUNNING_CASE_LOCK_SEGMENT,
                ));
                if ($locked !== [$caseId]) {
                    throw new LockPlanChanged('Dunning case vanished before lock');
                }
                if ($claim !== null && ! $this->work->owns($context, $claim)) {
                    throw new ProblemException(ProblemCode::RecoveryRequired, 'billium.collection.stale_worker');
                }

                $caseRow = $db->table('billium_collection_dunning_case')
                    ->where('id', $caseId)
                    ->first() ?? throw new LockPlanChanged('Dunning case missing after lock');
                $case = DatabaseDunningQueries::hydrateCase($caseRow);

                $stepRow = $db->table('billium_collection_step')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('dunning_case_id', $caseId)
                    ->where('step_ref', $command->stepRef)
                    ->first();

                if ($stepRow === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.collection.step_not_found');
                }

                $step = DatabaseDunningQueries::hydrateStep($stepRow);
                if ($claim !== null && $claim->stepId !== $step->id) {
                    throw new ProblemException(ProblemCode::AuthDenied, 'Collection worker step differs from claim');
                }
                if ($step->stepRevision !== $command->expectedStepRevision) {
                    throw new ProblemException(ProblemCode::IdempotencyConflict, 'billium.collection.step_revision_conflict');
                }

                $observedOpen = $this->participants->checkTransition($context, $step, $command, $executionRevision, $plan, $claim !== null);

                $now = RowCast::formatUtc(new DateTimeImmutable('now', new DateTimeZone('UTC')));
                $occurredUtc = RowCast::formatUtc($command->occurredAt);
                $nextStepEligible = false;
                $nextStepNo = null;
                $nextEligibleAtUtc = null;

                switch ($command->targetStatus) {
                    case CollectionStepStatus::Authorized:
                        $stepUpdate = [
                            'status' => CollectionStepStatus::Authorized->value,
                            'step_revision' => $step->stepRevision + 1,
                            'updated_at' => $now,
                        ];
                        if ($command->stepAuthorizationRef !== null && $step->stepAuthorizationRef === null) {
                            $stepUpdate['step_authorization_ref'] = $command->stepAuthorizationRef;
                        }
                        DunningTransitionWrites::updateStepCas($db, $step, $stepUpdate);
                        break;

                    case CollectionStepStatus::Dispatched:
                        DunningTransitionWrites::updateStepCas($db, $step, [
                            'status' => CollectionStepStatus::Dispatched->value,
                            'step_revision' => $step->stepRevision + 1,
                            'last_delivery_attempt_at' => $occurredUtc,
                            'updated_at' => $now,
                        ]);
                        break;

                    case CollectionStepStatus::Unknown:
                        $decision = $this->transitionReducer->reduceUnknown($case, $step, $command);
                        DunningTransitionWrites::updateStepCas($db, $step, [
                            'status' => CollectionStepStatus::Unknown->value,
                            'step_revision' => $step->stepRevision + 1,
                            'decline_reason' => $decision->parkedReason,
                            'updated_at' => $now,
                        ]);
                        DunningTransitionWrites::updateCaseCas($db, $case, [
                            'status' => $decision->caseStatus->value,
                            'parked_reason' => $decision->parkedReason,
                            'parked_step_ref' => $decision->parkedStepRef,
                            'next_attempt_at' => null,
                            'case_revision' => $case->caseRevision + 1,
                            'updated_at' => $now,
                        ]);
                        break;

                    case CollectionStepStatus::Succeeded:
                        $decision = $this->transitionReducer->reduceSucceeded($case, $step, $command, $observedOpen);
                        DunningTransitionWrites::updateStepCas($db, $step, [
                            'status' => CollectionStepStatus::Succeeded->value,
                            'step_revision' => $step->stepRevision + 1,
                            'outcome_evidence_digest' => $command->outcomeEvidenceDigest,
                            'terminal_at' => $occurredUtc,
                            'updated_at' => $now,
                        ]);

                        $caseUpdate = [
                            'status' => $decision->caseStatus->value,
                            'current_open_minor' => $decision->currentOpenMinor,
                            'active_step_no' => $decision->activeStepNo,
                            'last_completed_step_no' => $decision->lastCompletedStepNo,
                            'parked_reason' => $decision->parkedReason,
                            'parked_step_ref' => $decision->parkedStepRef,
                            'next_attempt_at' => null,
                            'case_revision' => $case->caseRevision + 1,
                            'updated_at' => $now,
                        ];
                        if ($decision->closedReason !== null) {
                            $caseUpdate['closed_reason'] = $decision->closedReason;
                            $caseUpdate['closed_at'] = $occurredUtc;
                        }
                        DunningTransitionWrites::updateCaseCas($db, $case, $caseUpdate);
                        break;

                    case CollectionStepStatus::FailedTerminal:
                        if ($command->declineCategory === null || $command->outcomeEvidenceDigest === null) {
                            throw new ProblemException(
                                ProblemCode::ValidationInvalid,
                                'billium.collection.terminal_decline_requires_category_and_evidence',
                            );
                        }

                        $policyRow = $db->table('billium_collection_dunning_policy')
                            ->where('id', $case->policyId)
                            ->first() ?? throw new LogicException('Pinned dunning policy missing');
                        $policy = DatabaseDunningQueries::hydratePolicy($policyRow);

                        $decision = $this->transitionReducer->reduceTerminalDecline($case, $step, $policy, $command, $observedOpen);

                        DunningTransitionWrites::updateStepCas($db, $step, [
                            'status' => CollectionStepStatus::FailedTerminal->value,
                            'step_revision' => $step->stepRevision + 1,
                            'decline_category' => $command->declineCategory->value,
                            'decline_reason' => $command->declineReason,
                            'outcome_evidence_digest' => $command->outcomeEvidenceDigest,
                            'terminal_at' => $occurredUtc,
                            'updated_at' => $now,
                        ]);

                        $nextStepEligible = $decision->nextStepEligible;
                        $nextStepNo = $decision->nextStepNo;
                        $nextEligibleAtUtc = $decision->nextAttemptAt === null ? null : RowCast::formatUtc($decision->nextAttemptAt);

                        $caseUpdate = [
                            'status' => $decision->caseStatus->value,
                            'current_open_minor' => $decision->currentOpenMinor,
                            'business_decline_attempts_used' => $decision->businessDeclineAttemptsUsed,
                            'active_step_no' => $decision->activeStepNo,
                            'last_completed_step_no' => $decision->lastCompletedStepNo,
                            'parked_reason' => $decision->parkedReason,
                            'parked_step_ref' => $decision->parkedStepRef,
                            'next_attempt_at' => $nextEligibleAtUtc,
                            'case_revision' => $case->caseRevision + 1,
                            'updated_at' => $now,
                        ];
                        if ($decision->closedReason !== null) {
                            $caseUpdate['closed_reason'] = $decision->closedReason;
                            $caseUpdate['closed_at'] = $occurredUtc;
                        }
                        DunningTransitionWrites::updateCaseCas($db, $case, $caseUpdate);
                        break;

                    case CollectionStepStatus::Cancelled:
                        $decision = $this->transitionReducer->reduceCancelled($case, $command);
                        DunningTransitionWrites::updateStepCas($db, $step, [
                            'status' => CollectionStepStatus::Cancelled->value,
                            'step_revision' => $step->stepRevision + 1,
                            'decline_reason' => $decision->closedReason,
                            'terminal_at' => $occurredUtc,
                            'updated_at' => $now,
                        ]);
                        DunningTransitionWrites::updateCaseCas($db, $case, [
                            'status' => $decision->caseStatus->value,
                            'active_step_no' => $decision->activeStepNo,
                            'parked_reason' => $decision->parkedReason,
                            'parked_step_ref' => $decision->parkedStepRef,
                            'next_attempt_at' => null,
                            'closed_reason' => $decision->closedReason,
                            'closed_at' => $occurredUtc,
                            'case_revision' => $case->caseRevision + 1,
                            'updated_at' => $now,
                        ]);
                        break;

                    case CollectionStepStatus::Planned:
                        throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.collection.step_transition_invalid');
                }

                return new CollectionReceipt('collection.step.transitioned', [
                    'caseId' => $caseId,
                    'stepId' => $step->id,
                    'nextStepEligible' => $nextStepEligible,
                    'nextStepNo' => $nextStepNo,
                    'nextEligibleAt' => $nextEligibleAtUtc,
                ]);
            },
        )->orThrow();

        $caseId = RowCast::integer($outcome->body['caseId'] ?? null, 'caseId');
        $stepId = RowCast::integer($outcome->body['stepId'] ?? null, 'stepId');
        $connection = $this->databases->connection($this->options->connection);

        $caseRow = $connection->table('billium_collection_dunning_case')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('id', $caseId)
            ->first() ?? throw new LogicException('Transitioned dunning case missing');
        $stepRow = $connection->table('billium_collection_step')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('id', $stepId)
            ->first() ?? throw new LogicException('Transitioned collection step missing');

        $nextEligibleAtRaw = RowCast::optStr($outcome->body['nextEligibleAt'] ?? null, 'nextEligibleAt');

        return new DunningCaseTransitionResult(
            case: DatabaseDunningQueries::hydrateCase($caseRow),
            step: DatabaseDunningQueries::hydrateStep($stepRow),
            nextStepEligible: (bool) ($outcome->body['nextStepEligible'] ?? false),
            nextStepNo: RowCast::optInt($outcome->body['nextStepNo'] ?? null, 'nextStepNo'),
            nextEligibleAt: $nextEligibleAtRaw === null ? null : RowCast::utcDateTime($nextEligibleAtRaw, 'nextEligibleAt'),
        );
    }

    private function lockCollectionOwner(TransactionContext $ctx, Connection $db, string $componentRef): int
    {
        $caller = $ctx->caller();
        $owner = $db->table('billium_collection_recurring_owner')->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)->where('component_ref', $componentRef)
            ->where('status', RecurringOwnerStatus::Active->value);
        $id = $owner->value('id');
        if ($id === null) {
            throw new ProblemException(ProblemCode::OperationFinancialHold, 'Collection requires an active recurring owner');
        }
        $id = RowCast::integer($id, 'id');
        $lock = new LockRequest(LockRank::DunningCase, 'billium_collection_recurring_owner', [$id], scope: LockScope::Seller,
            segment: DatabaseDunningPolicyService::RECURRING_OWNER_LOCK_SEGMENT);
        if ($ctx->lock($lock) !== [$id] || $owner->where('id', $id)->value('owner_mode') !== RecurringOwnerMode::BilliumScheduler->value) {
            throw new ProblemException(ProblemCode::OperationFinancialHold, 'Billium does not own recurrence for this component');
        }

        return $id;
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

    private static function assertDirectLifecyclePermission(Caller $caller): void
    {
        if (! in_array(self::DIRECT_LIFECYCLE_PERMISSION, $caller->permissions, true)) {
            throw new ProblemException(ProblemCode::AuthDenied, 'billium.collection.direct_step_lifecycle_permission_required');
        }
    }

    private function assertOutsideTransaction(): void
    {
        foreach ($this->databases->getConnections() as $connection) {
            $writePdo = $connection->getRawPdo();
            $readPdo = $connection->getRawReadPdo();
            if (
                $connection->transactionLevel() !== 0
                || ($writePdo instanceof PDO && $writePdo->inTransaction())
                || ($readPdo instanceof PDO && $readPdo->inTransaction())
            ) {
                throw new LogicException('Collection dunning case operations must run outside every database transaction');
            }
        }
    }
}
