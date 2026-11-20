<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Services;

use Billium\Collection\Contracts\ConsentBindingService;
use Billium\Collection\Contracts\DunningCaseService;
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
use Billium\Collection\Internal\Support\RowCast;
use Billium\Collection\Internal\Work\CollectionWork;
use Billium\Collection\Internal\Work\CollectionWorkClaim;
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
use Billium\Execution\Data\ExecutionScope;
use Billium\Platform\Contracts\Idempotency\IdempotencyReceiptQueries;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
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

    public function authorizeDueCollection(AuthorizeCollection $command, DateTimeImmutable $dueAsOf): CollectionStepView
    {
        return $this->createStep($command->step, $command, $dueAsOf);
    }

    private function createStep(PlanCollectionStep $command, ?AuthorizeCollection $authorization, ?DateTimeImmutable $dueAsOf = null): CollectionStepView
    {
        $this->assertOutsideTransaction();
        $automaticDue = $dueAsOf !== null;
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
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

        $kind = $authorization === null ? 'collection.step.plan.v1' : 'collection.authorize.v1';
        $financialScope = $authorization === null ? null : new FinancialScope($authorization->executionScope->installationRef,
            $authorization->executionScope->sellerRef, $authorization->executionScope->environment,
            $authorization->executionScope->intentOwnerNamespace, $authorization->executionProfile);
        $cached = $financialScope === null ? null : $this->receipts->byCommandKey($financialScope, $kind, $command->commandKey);
        if ($cached !== null && $cached->inputDigest !== $stepDigest) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'Collection command key has different terms');
        }
        $prepared = $authorization === null || $cached !== null ? null : $this->admission->prepare($caller, $authorization);

        $outcome = ($cached->outcome ?? $this->runner->run(
            spec: new OperationSpec(
                commandKind: $kind,
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::NewIntent,
                modules: self::COLLECTION_MODULES,
                commandKey: $command->commandKey,
                digest: $stepDigest,
                commitKind: $authorization === null ? 'collection.step.plan' : 'collection.authorize',
                financialScope: $financialScope,
                inputDigest: $authorization === null ? null : $stepDigest,
            ),
            body: function (TransactionContext $context) use ($caller, $command, $stepDigest, $authorization, $authorizationRef, $prepared, $dueAsOf, $automaticDue): CollectionReceipt {
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

                $policyRow = $db->table('billium_collection_dunning_policy')
                    ->where('id', $case->policyId)
                    ->first() ?? throw new LogicException('Pinned dunning policy missing');
                $policy = DatabaseDunningQueries::hydratePolicy($policyRow);

                $existingSteps = $db->table('billium_collection_step')
                    ->where('dunning_case_id', $caseId)
                    ->orderBy('step_no')
                    ->get();

                $priorStep = null;
                foreach ($existingSteps as $row) {
                    $step = DatabaseDunningQueries::hydrateStep($row);
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
                    if ($step->stepNo === $command->stepNo - 1) {
                        $priorStep = $step;
                    }
                }

                $priorStepId = null;
                if ($command->stepNo === 1) {
                    if ($existingSteps->count() > 0) {
                        throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.collection.step_sequence_invalid');
                    }
                } else {
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
                    $priorStepId = $priorStep->id;
                }

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

                $stepDef = self::stepDefinition($policy, $command->stepNo);
                if ($stepDef === null) {
                    throw new ProblemException(ProblemCode::PolicyUnknown, 'billium.collection.policy_step_not_defined');
                }
                $amount = $command->amountMinor;
                if ($authorization !== null && $prepared !== null) {
                    $amount = $this->admission->lockAndSize($context, $db, $authorization, $case, $stepDef->channel, $authorizationRef,
                        $prepared['binding'], $prepared['merchant'], $prepared['host'], $prepared['componentRef'], $dueAsOf);
                } elseif ($amount > $case->currentOpenMinor) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.collection.amount_exceeds_open');
                }
                if ($policy->minOutstandingMinor > 0 && $amount < $policy->minOutstandingMinor) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.collection.below_outstanding_threshold');
                }
                if ($command->scheduledAt > $case->recoveryDeadlineAt) {
                    throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.recovery_horizon_exceeded');
                }

                $now = RowCast::formatUtc(new DateTimeImmutable);
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
                if ($authorization !== null && $prepared !== null) {
                    $this->admission->record($context, $db, $authorization, $case, $prepared['binding'], $prepared['merchant'], $authorizationRef, $stepId, $amount, $now,
                        $recurringOwnerId ?? throw new LogicException('Collection authorization requires a locked recurring owner'), $prepared['componentRef'], $automaticDue);
                }

                return new CollectionReceipt($authorization === null ? 'collection.step.planned' : 'collection.step.authorized', [
                    'stepId' => $stepId,
                    'stepRef' => $command->stepRef,
                ]);
            },
        ))->orThrow();

        $stepId = RowCast::integer($outcome->body['stepId'] ?? null, 'stepId');
        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_step')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('id', $stepId)
            ->first() ?? throw new LogicException('Planned collection step missing');

        return DatabaseDunningQueries::hydrateStep($row);
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
                financialScope: $claim->scope ?? $this->participants->transitionScope($command),
            ),
            body: function (TransactionContext $context) use ($caller, $command, $claim, $executionRevision): CollectionReceipt {
                $this->runner->assertCurrent($context, 'collection');
                $db = $this->databases->connection($context->connectionName());
                $plan = $claim === null ? null : $this->participants->prelockOutcome($context,
                    new ExecutionScope($caller->installationRef, $caller->sellerRef, $caller->environment,
                        RowCast::str($claim->scope->intentOwnerNamespace, 'intentOwnerNamespace')),
                    RowCast::str($claim->authorization->operation_ref, 'operation_ref'));

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
                if (! self::isValidStepTransition($step->status, $command->targetStatus)) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.collection.step_transition_invalid');
                }

                $observedOpen = $this->participants->checkTransition($context, $step, $command, $executionRevision, $plan);

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
                        $this->updateStepCas($db, $step, $stepUpdate);
                        break;

                    case CollectionStepStatus::Dispatched:
                        $this->updateStepCas($db, $step, [
                            'status' => CollectionStepStatus::Dispatched->value,
                            'step_revision' => $step->stepRevision + 1,
                            'last_delivery_attempt_at' => $occurredUtc,
                            'updated_at' => $now,
                        ]);
                        break;

                    case CollectionStepStatus::Unknown:
                        $parkReason = $command->declineReason ?? 'prior_step_unknown';
                        $this->updateStepCas($db, $step, [
                            'status' => CollectionStepStatus::Unknown->value,
                            'step_revision' => $step->stepRevision + 1,
                            'decline_reason' => $parkReason,
                            'updated_at' => $now,
                        ]);
                        $this->updateCaseCas($db, $case, [
                            'status' => DunningCaseStatus::ParkedUnknown->value,
                            'parked_reason' => $parkReason,
                            'parked_step_ref' => $step->stepRef,
                            'next_attempt_at' => null,
                            'case_revision' => $case->caseRevision + 1,
                            'updated_at' => $now,
                        ]);
                        break;

                    case CollectionStepStatus::Succeeded:
                        if ($command->outcomeEvidenceDigest === null) {
                            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.collection.success_requires_evidence');
                        }
                        $newOpen = $observedOpen ?? $command->updatedOpenMinor ?? 0;
                        if ($observedOpen === null && $newOpen > $case->currentOpenMinor) {
                            throw new ProblemException(ProblemCode::ValidationInvalid, 'updatedOpenMinor cannot exceed currentOpenMinor');
                        }
                        $this->updateStepCas($db, $step, [
                            'status' => CollectionStepStatus::Succeeded->value,
                            'step_revision' => $step->stepRevision + 1,
                            'outcome_evidence_digest' => $command->outcomeEvidenceDigest,
                            'terminal_at' => $occurredUtc,
                            'updated_at' => $now,
                        ]);

                        $caseUpdate = [
                            'current_open_minor' => $newOpen,
                            'active_step_no' => null,
                            'last_completed_step_no' => max($case->lastCompletedStepNo, $step->stepNo),
                            'parked_reason' => null,
                            'parked_step_ref' => null,
                            'next_attempt_at' => null,
                            'case_revision' => $case->caseRevision + 1,
                            'updated_at' => $now,
                        ];
                        if ($newOpen === 0) {
                            $caseUpdate['status'] = DunningCaseStatus::Settled->value;
                            $caseUpdate['closed_reason'] = 'step_succeeded';
                            $caseUpdate['closed_at'] = $occurredUtc;
                        } else {
                            $caseUpdate['status'] = DunningCaseStatus::Open->value;
                        }
                        $this->updateCaseCas($db, $case, $caseUpdate);
                        break;

                    case CollectionStepStatus::FailedTerminal:
                        if ($command->declineCategory === null || $command->outcomeEvidenceDigest === null) {
                            throw new ProblemException(
                                ProblemCode::ValidationInvalid,
                                'billium.collection.terminal_decline_requires_category_and_evidence',
                            );
                        }

                        $this->updateStepCas($db, $step, [
                            'status' => CollectionStepStatus::FailedTerminal->value,
                            'step_revision' => $step->stepRevision + 1,
                            'decline_category' => $command->declineCategory->value,
                            'decline_reason' => $command->declineReason,
                            'outcome_evidence_digest' => $command->outcomeEvidenceDigest,
                            'terminal_at' => $occurredUtc,
                            'updated_at' => $now,
                        ]);

                        $policyRow = $db->table('billium_collection_dunning_policy')
                            ->where('id', $case->policyId)
                            ->first() ?? throw new LogicException('Pinned dunning policy missing');
                        $policy = DatabaseDunningQueries::hydratePolicy($policyRow);

                        $usedDeclines = $case->businessDeclineAttemptsUsed + 1;
                        $isRetryable = in_array($command->declineCategory, $policy->retryableDeclineCategories, true);
                        $budgetRemains = $usedDeclines < $case->maxBusinessDeclineAttempts;
                        $candidateStepNo = $step->stepNo + 1;
                        $nextDef = self::stepDefinition($policy, $candidateStepNo);

                        $candidateEligibleAt = null;
                        $withinHorizon = false;
                        if ($nextDef !== null) {
                            $scaledDelay = intdiv($nextDef->delaySeconds * $nextDef->backoffMultiplierBps, 10000);
                            $effectiveDelay = min($nextDef->maxBackoffSeconds, max($nextDef->delaySeconds, $scaledDelay));
                            $candidateEligibleAt = $command->occurredAt->setTimezone(new DateTimeZone('UTC'))
                                ->modify('+'.$effectiveDelay.' seconds');
                            $withinHorizon = $candidateEligibleAt <= $case->recoveryDeadlineAt;
                        }
                        $open = $observedOpen ?? $case->currentOpenMinor;
                        $aboveThreshold = $open > 0 && $open >= $policy->minOutstandingMinor;

                        if ($isRetryable && $budgetRemains && $nextDef !== null && $withinHorizon && $aboveThreshold) {
                            $nextStepEligible = true;
                            $nextStepNo = $candidateStepNo;
                            $nextEligibleAtUtc = RowCast::formatUtc($candidateEligibleAt);
                            $this->updateCaseCas($db, $case, [
                                'status' => DunningCaseStatus::Open->value,
                                'current_open_minor' => $open,
                                'business_decline_attempts_used' => $usedDeclines,
                                'active_step_no' => null,
                                'last_completed_step_no' => max($case->lastCompletedStepNo, $step->stepNo),
                                'parked_reason' => null,
                                'parked_step_ref' => null,
                                'next_attempt_at' => $nextEligibleAtUtc,
                                'case_revision' => $case->caseRevision + 1,
                                'updated_at' => $now,
                            ]);
                        } else {
                            $closedReason = match (true) {
                                ! $isRetryable => 'non_retryable_decline:'.$command->declineCategory->value,
                                ! $budgetRemains => 'business_decline_budget_exhausted',
                                $nextDef === null => 'policy_steps_exhausted',
                                ! $withinHorizon => 'recovery_horizon_exceeded',
                                default => 'below_outstanding_threshold',
                            };
                            $this->updateCaseCas($db, $case, [
                                'status' => DunningCaseStatus::Exhausted->value,
                                'current_open_minor' => $open,
                                'business_decline_attempts_used' => $usedDeclines,
                                'active_step_no' => null,
                                'last_completed_step_no' => max($case->lastCompletedStepNo, $step->stepNo),
                                'parked_reason' => null,
                                'parked_step_ref' => null,
                                'next_attempt_at' => null,
                                'closed_reason' => $closedReason,
                                'closed_at' => $occurredUtc,
                                'case_revision' => $case->caseRevision + 1,
                                'updated_at' => $now,
                            ]);
                        }
                        break;

                    case CollectionStepStatus::Cancelled:
                        $cancelReason = $command->declineReason ?? 'step_cancelled';
                        $this->updateStepCas($db, $step, [
                            'status' => CollectionStepStatus::Cancelled->value,
                            'step_revision' => $step->stepRevision + 1,
                            'decline_reason' => $cancelReason,
                            'terminal_at' => $occurredUtc,
                            'updated_at' => $now,
                        ]);
                        $this->updateCaseCas($db, $case, [
                            'status' => DunningCaseStatus::Cancelled->value,
                            'active_step_no' => null,
                            'parked_reason' => null,
                            'parked_step_ref' => null,
                            'next_attempt_at' => null,
                            'closed_reason' => $cancelReason,
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

    /** @param array<string, mixed> $values */
    private function updateStepCas(Connection $db, CollectionStepView $step, array $values): void
    {
        $updated = $db->table('billium_collection_step')
            ->where('id', $step->id)
            ->where('step_revision', $step->stepRevision)
            ->update($values);

        if ($updated !== 1) {
            throw new LockPlanChanged('Collection step transition CAS failed');
        }
    }

    /** @param array<string, mixed> $values */
    private function updateCaseCas(Connection $db, DunningCaseView $case, array $values): void
    {
        $updated = $db->table('billium_collection_dunning_case')
            ->where('id', $case->id)
            ->where('case_revision', $case->caseRevision)
            ->update($values);

        if ($updated !== 1) {
            throw new LockPlanChanged('Dunning case transition CAS failed');
        }
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

    private static function isValidStepTransition(CollectionStepStatus $from, CollectionStepStatus $to): bool
    {
        return match ($from) {
            CollectionStepStatus::Planned => in_array($to, [
                CollectionStepStatus::Authorized,
                CollectionStepStatus::Dispatched,
                CollectionStepStatus::Unknown,
                CollectionStepStatus::Cancelled,
            ], true),
            CollectionStepStatus::Authorized => in_array($to, [
                CollectionStepStatus::Dispatched,
                CollectionStepStatus::Unknown,
                CollectionStepStatus::Cancelled,
            ], true),
            CollectionStepStatus::Dispatched => in_array($to, [
                CollectionStepStatus::Unknown,
                CollectionStepStatus::Succeeded,
                CollectionStepStatus::FailedTerminal,
            ], true),
            CollectionStepStatus::Unknown => in_array($to, [
                CollectionStepStatus::Succeeded,
                CollectionStepStatus::FailedTerminal,
            ], true),
            CollectionStepStatus::Succeeded,
            CollectionStepStatus::FailedTerminal,
            CollectionStepStatus::Cancelled => false,
        };
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
