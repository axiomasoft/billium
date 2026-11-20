<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Services;

use Billium\Collection\Contracts\DunningPolicyService;
use Billium\Collection\Data\CollectionScope;
use Billium\Collection\Data\DunningPolicyStepDefinition;
use Billium\Collection\Data\DunningPolicyView;
use Billium\Collection\Data\RecurringOwnerView;
use Billium\Collection\Data\RegisterDunningPolicyVersion;
use Billium\Collection\Data\RegisterRecurringOwner;
use Billium\Collection\Data\RetireRecurringOwner;
use Billium\Collection\Enums\CollectionChannel;
use Billium\Collection\Enums\DeclineCategory;
use Billium\Collection\Enums\RecurringOwnerMode;
use Billium\Collection\Enums\RecurringOwnerStatus;
use Billium\Collection\Internal\Canonical\CanonicalJson;
use Billium\Collection\Internal\CollectionReceipt;
use Billium\Collection\Internal\Queries\DatabaseDunningQueries;
use Billium\Collection\Internal\Support\RowCast;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runner\LockScope;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use PDO;
use stdClass;

#[Internal]
final readonly class DatabaseDunningPolicyService implements DunningPolicyService
{
    public const int RECURRING_OWNER_LOCK_SEGMENT = 1;

    /** @var list<string> */
    private const array COLLECTION_MODULES = ['platform', 'ledger', 'execution', 'collection'];

    public function __construct(
        private DatabaseManager $databases,
        private OperationRunner $runner,
        private CallerContext $callers,
        private BilliumOptions $options,
    ) {}

    #[Override]
    public function registerPolicyVersion(RegisterDunningPolicyVersion $command): DunningPolicyView
    {
        $this->assertOutsideTransaction();
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->policyRef, 'policyRef');

        if ($command->policyVersion < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'policyVersion must be >= 1');
        }
        if ($command->allowedChannels === []) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'allowedChannels must not be empty');
        }
        if ($command->orderedSteps === []) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'orderedSteps must not be empty');
        }
        if ($command->maxBusinessDeclineAttempts < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'maxBusinessDeclineAttempts must be >= 1');
        }
        if ($command->maxDeliveryRetries < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'maxDeliveryRetries must be >= 1');
        }
        if ($command->recoveryHorizonSeconds <= 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'recoveryHorizonSeconds must be > 0');
        }
        if ($command->graceSignalAfterSeconds !== null
            && ($command->graceSignalAfterSeconds < 0 || $command->graceSignalAfterSeconds > $command->recoveryHorizonSeconds)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'graceSignalAfterSeconds must be within [0, recoveryHorizonSeconds]');
        }
        if ($command->readOnlySignalAfterSeconds !== null
            && ($command->readOnlySignalAfterSeconds < 0 || $command->readOnlySignalAfterSeconds > $command->recoveryHorizonSeconds)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'readOnlySignalAfterSeconds must be within [0, recoveryHorizonSeconds]');
        }
        if ($command->graceSignalAfterSeconds !== null && $command->readOnlySignalAfterSeconds !== null
            && $command->graceSignalAfterSeconds > $command->readOnlySignalAfterSeconds) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'graceSignalAfterSeconds cannot exceed readOnlySignalAfterSeconds');
        }
        if ($command->minOutstandingMinor < 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'minOutstandingMinor must be nonnegative');
        }
        $thresholdCurrency = $command->thresholdCurrency;
        if ($command->minOutstandingMinor > 0) {
            if ($thresholdCurrency === null) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'thresholdCurrency is required when minOutstandingMinor > 0');
            }
            $thresholdCurrency = RowCast::currency($thresholdCurrency, 'thresholdCurrency');
        } elseif ($thresholdCurrency !== null) {
            $thresholdCurrency = RowCast::currency($thresholdCurrency, 'thresholdCurrency');
        }

        $allowedChannelValues = array_values(array_unique(array_map(
            static fn (CollectionChannel $channel): string => $channel->value,
            $command->allowedChannels,
        )));
        sort($allowedChannelValues, SORT_STRING);

        $retryableValues = array_values(array_unique(array_map(
            static fn (DeclineCategory $category): string => $category->value,
            $command->retryableDeclineCategories,
        )));
        sort($retryableValues, SORT_STRING);

        $normalizedSteps = $this->normalizeSteps($command->orderedSteps, $allowedChannelValues);

        if ($command->supersedesPolicyVersion !== null && ($command->supersedesPolicyVersion < 1 || $command->supersedesPolicyVersion >= $command->policyVersion)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'supersedesPolicyVersion must be between 1 and policyVersion - 1');
        }

        $policyPayload = [
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'policyRef' => $command->policyRef,
            'policyVersion' => $command->policyVersion,
            'allowedChannels' => $allowedChannelValues,
            'orderedSteps' => $normalizedSteps,
            'maxBusinessDeclineAttempts' => $command->maxBusinessDeclineAttempts,
            'maxDeliveryRetries' => $command->maxDeliveryRetries,
            'retryableDeclineCategories' => $retryableValues,
            'recoveryHorizonSeconds' => $command->recoveryHorizonSeconds,
            'graceSignalAfterSeconds' => $command->graceSignalAfterSeconds,
            'readOnlySignalAfterSeconds' => $command->readOnlySignalAfterSeconds,
            'minOutstandingMinor' => $command->minOutstandingMinor,
            'thresholdCurrency' => $thresholdCurrency,
            'supersedesPolicyVersion' => $command->supersedesPolicyVersion,
        ];
        $policyDigest = CanonicalJson::digest($policyPayload);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'collection.dunning_policy.register.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::NewIntent,
                modules: self::COLLECTION_MODULES,
                commandKey: $command->commandKey,
                digest: $policyDigest,
                commitKind: 'collection.dunning_policy.register',
            ),
            body: function (TransactionContext $context) use (
                $caller,
                $command,
                $allowedChannelValues,
                $normalizedSteps,
                $retryableValues,
                $thresholdCurrency,
                $policyDigest,
            ): CollectionReceipt {
                $this->runner->assertCurrent($context, 'collection');
                $db = $this->databases->connection($context->connectionName());

                $existing = $db->table('billium_collection_dunning_policy')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('policy_ref', $command->policyRef)
                    ->where('policy_version', $command->policyVersion)
                    ->first();

                if ($existing !== null) {
                    if (RowCast::str($existing->policy_digest, 'policy_digest') !== $policyDigest) {
                        throw new ProblemException(
                            ProblemCode::IdempotencyConflict,
                            'billium.collection.policy_version_conflict',
                        );
                    }

                    return new CollectionReceipt('collection.dunning_policy.registered', [
                        'policyId' => RowCast::integer($existing->id, 'id'),
                        'policyRef' => $command->policyRef,
                        'policyVersion' => $command->policyVersion,
                    ]);
                }

                $supersedesPolicyId = null;
                if ($command->supersedesPolicyVersion !== null) {
                    $prior = $db->table('billium_collection_dunning_policy')
                        ->where('seller_ref', $caller->sellerRef)
                        ->where('environment', $caller->environment)
                        ->where('policy_ref', $command->policyRef)
                        ->where('policy_version', $command->supersedesPolicyVersion)
                        ->first();
                    if ($prior === null) {
                        throw new ProblemException(
                            ProblemCode::ReferenceUnknown,
                            'billium.collection.superseded_policy_not_found',
                        );
                    }
                    $supersedesPolicyId = RowCast::integer($prior->id, 'id');
                }

                $now = RowCast::formatUtc(new DateTimeImmutable('now', new DateTimeZone('UTC')));
                $inserted = $db->selectOne(
                    <<<'SQL'
                    INSERT INTO public.billium_collection_dunning_policy (
                        seller_ref, environment, policy_ref, policy_version,
                        allowed_channels, ordered_steps,
                        max_business_decline_attempts, max_delivery_retries,
                        retryable_decline_categories, recovery_horizon_seconds,
                        grace_signal_after_seconds, read_only_signal_after_seconds,
                        min_outstanding_minor, threshold_currency,
                        supersedes_policy_id, policy_digest, created_at
                    ) VALUES (
                        ?, ?, ?, ?,
                        ?::jsonb, ?::jsonb,
                        ?, ?,
                        ?::jsonb, ?,
                        ?, ?,
                        ?, ?,
                        ?, ?, ?
                    )
                    ON CONFLICT (seller_ref, environment, policy_ref, policy_version) DO NOTHING
                    RETURNING id
                    SQL,
                    [
                        $caller->sellerRef,
                        $caller->environment,
                        $command->policyRef,
                        $command->policyVersion,
                        CanonicalJson::encode($allowedChannelValues),
                        CanonicalJson::encode($normalizedSteps),
                        $command->maxBusinessDeclineAttempts,
                        $command->maxDeliveryRetries,
                        CanonicalJson::encode($retryableValues),
                        $command->recoveryHorizonSeconds,
                        $command->graceSignalAfterSeconds,
                        $command->readOnlySignalAfterSeconds,
                        $command->minOutstandingMinor,
                        $thresholdCurrency,
                        $supersedesPolicyId,
                        $policyDigest,
                        $now,
                    ],
                );

                if (! $inserted instanceof stdClass) {
                    $raced = $db->table('billium_collection_dunning_policy')
                        ->where('seller_ref', $caller->sellerRef)
                        ->where('environment', $caller->environment)
                        ->where('policy_ref', $command->policyRef)
                        ->where('policy_version', $command->policyVersion)
                        ->first() ?? throw new LogicException('Dunning policy vanished after conflict');

                    if (RowCast::str($raced->policy_digest, 'policy_digest') !== $policyDigest) {
                        throw new ProblemException(
                            ProblemCode::IdempotencyConflict,
                            'billium.collection.policy_version_conflict',
                        );
                    }
                    $policyId = RowCast::integer($raced->id, 'id');
                } else {
                    $policyId = RowCast::integer($inserted->id, 'id');
                }

                return new CollectionReceipt('collection.dunning_policy.registered', [
                    'policyId' => $policyId,
                    'policyRef' => $command->policyRef,
                    'policyVersion' => $command->policyVersion,
                ]);
            },
        )->orThrow();

        $policyId = RowCast::integer($outcome->body['policyId'] ?? null, 'policyId');
        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_dunning_policy')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('id', $policyId)
            ->first() ?? throw new LogicException('Registered dunning policy missing');

        return DatabaseDunningQueries::hydratePolicy($row);
    }

    #[Override]
    public function registerRecurringOwner(RegisterRecurringOwner $command): RecurringOwnerView
    {
        $this->assertOutsideTransaction();
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->agreementRef, 'agreementRef');
        RowCast::bounded($command->componentRef, 'componentRef');
        RowCast::bounded($command->ownerRef, 'ownerRef');

        $configDigest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'agreementRef' => $command->agreementRef,
            'componentRef' => $command->componentRef,
            'ownerMode' => $command->ownerMode->value,
            'ownerRef' => $command->ownerRef,
        ]);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'collection.recurring_owner.register.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::NewIntent,
                modules: self::COLLECTION_MODULES,
                commandKey: $command->commandKey,
                digest: $configDigest,
                commitKind: 'collection.recurring_owner.register',
            ),
            body: function (TransactionContext $context) use ($caller, $command, $configDigest): CollectionReceipt {
                $this->runner->assertCurrent($context, 'collection');
                $db = $this->databases->connection($context->connectionName());

                $latest = $db->table('billium_collection_recurring_owner')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('component_ref', $command->componentRef)
                    ->orderByDesc('owner_revision')
                    ->first();

                if ($latest !== null) {
                    $latestId = RowCast::integer($latest->id, 'id');
                    $locked = $context->lock(new LockRequest(
                        LockRank::DunningCase,
                        'billium_collection_recurring_owner',
                        [$latestId],
                        scope: LockScope::Seller,
                        segment: self::RECURRING_OWNER_LOCK_SEGMENT,
                    ));
                    if ($locked !== [$latestId]) {
                        throw new LockPlanChanged('Recurring owner row disappeared before lock');
                    }
                    $latest = $db->table('billium_collection_recurring_owner')
                        ->where('seller_ref', $caller->sellerRef)
                        ->where('environment', $caller->environment)
                        ->where('component_ref', $command->componentRef)
                        ->orderByDesc('owner_revision')
                        ->first();
                }

                $active = $db->table('billium_collection_recurring_owner')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('component_ref', $command->componentRef)
                    ->where('status', RecurringOwnerStatus::Active->value)
                    ->first();

                if ($active !== null) {
                    if (RowCast::str($active->config_digest, 'config_digest') === $configDigest) {
                        return new CollectionReceipt('collection.recurring_owner.registered', [
                            'ownerId' => RowCast::integer($active->id, 'id'),
                            'componentRef' => $command->componentRef,
                            'ownerMode' => $command->ownerMode->value,
                        ]);
                    }

                    throw new ProblemException(
                        ProblemCode::OperationFinancialHold,
                        'billium.collection.recurring_owner_conflict',
                    );
                }

                $nextRevision = $latest === null
                    ? 1
                    : RowCast::integer($latest->owner_revision, 'owner_revision') + 1;
                $now = RowCast::formatUtc(new DateTimeImmutable('now', new DateTimeZone('UTC')));

                try {
                    $inserted = $db->selectOne(
                        <<<'SQL'
                        INSERT INTO public.billium_collection_recurring_owner (
                            seller_ref, environment, agreement_ref, component_ref,
                            owner_mode, owner_ref, owner_revision, status, retired_reason,
                            config_digest, recorded_at, updated_at
                        ) VALUES (
                            ?, ?, ?, ?,
                            ?, ?, ?, 'active', NULL,
                            ?, ?, ?
                        )
                        ON CONFLICT (seller_ref, environment, component_ref) WHERE status = 'active' DO NOTHING
                        RETURNING id
                        SQL,
                        [
                            $caller->sellerRef,
                            $caller->environment,
                            $command->agreementRef,
                            $command->componentRef,
                            $command->ownerMode->value,
                            $command->ownerRef,
                            $nextRevision,
                            $configDigest,
                            $now,
                            $now,
                        ],
                    );
                } catch (UniqueConstraintViolationException) {
                    // The revision key can lose the race when the partial active index does not.
                    $inserted = null;
                }

                if (! $inserted instanceof stdClass) {
                    $racedActive = $db->table('billium_collection_recurring_owner')
                        ->where('seller_ref', $caller->sellerRef)
                        ->where('environment', $caller->environment)
                        ->where('component_ref', $command->componentRef)
                        ->where('status', RecurringOwnerStatus::Active->value)
                        ->first() ?? throw new LockPlanChanged('Recurring owner conflicted without visible active row');

                    if (RowCast::str($racedActive->config_digest, 'config_digest') === $configDigest) {
                        $ownerId = RowCast::integer($racedActive->id, 'id');
                    } else {
                        throw new ProblemException(
                            ProblemCode::OperationFinancialHold,
                            'billium.collection.recurring_owner_conflict',
                        );
                    }
                } else {
                    $ownerId = RowCast::integer($inserted->id, 'id');
                }

                return new CollectionReceipt('collection.recurring_owner.registered', [
                    'ownerId' => $ownerId,
                    'componentRef' => $command->componentRef,
                    'ownerMode' => $command->ownerMode->value,
                ]);
            },
        )->orThrow();

        $ownerId = RowCast::integer($outcome->body['ownerId'] ?? null, 'ownerId');
        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_recurring_owner')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('id', $ownerId)
            ->first() ?? throw new LogicException('Registered recurring owner missing');

        return DatabaseDunningQueries::hydrateRecurringOwner($row);
    }

    #[Override]
    public function retireRecurringOwner(RetireRecurringOwner $command): RecurringOwnerView
    {
        $this->assertOutsideTransaction();
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->componentRef, 'componentRef');
        RowCast::bounded($command->reason, 'reason', 500);
        if ($command->expectedOwnerRevision < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'expectedOwnerRevision must be >= 1');
        }

        $digest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'componentRef' => $command->componentRef,
            'expectedOwnerRevision' => $command->expectedOwnerRevision,
            'reason' => $command->reason,
        ]);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'collection.recurring_owner.retire.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::FrozenIntentCompletion,
                modules: self::COLLECTION_MODULES,
                commandKey: $command->commandKey,
                digest: $digest,
                commitKind: 'collection.recurring_owner.retire',
            ),
            body: function (TransactionContext $context) use ($caller, $command): CollectionReceipt {
                $this->runner->assertCurrent($context, 'collection');
                $db = $this->databases->connection($context->connectionName());

                $active = $db->table('billium_collection_recurring_owner')
                    ->where('seller_ref', $caller->sellerRef)
                    ->where('environment', $caller->environment)
                    ->where('component_ref', $command->componentRef)
                    ->where('status', RecurringOwnerStatus::Active->value)
                    ->first();

                if ($active === null) {
                    throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.collection.recurring_owner_not_found');
                }

                $ownerId = RowCast::integer($active->id, 'id');
                $locked = $context->lock(new LockRequest(
                    LockRank::DunningCase,
                    'billium_collection_recurring_owner',
                    [$ownerId],
                    scope: LockScope::Seller,
                    segment: self::RECURRING_OWNER_LOCK_SEGMENT,
                ));
                if ($locked !== [$ownerId]) {
                    throw new LockPlanChanged('Recurring owner vanished before lock');
                }

                $active = $db->table('billium_collection_recurring_owner')
                    ->where('id', $ownerId)
                    ->first() ?? throw new LockPlanChanged('Recurring owner missing after lock');

                $currentRevision = RowCast::integer($active->owner_revision, 'owner_revision');
                if ($currentRevision !== $command->expectedOwnerRevision) {
                    throw new ProblemException(ProblemCode::IdempotencyConflict, 'billium.collection.recurring_owner_revision_conflict');
                }
                if (RowCast::str($active->status, 'status') !== RecurringOwnerStatus::Active->value) {
                    throw new ProblemException(ProblemCode::IdempotencyConflict, 'billium.collection.recurring_owner_already_retired');
                }

                $now = RowCast::formatUtc(new DateTimeImmutable('now', new DateTimeZone('UTC')));
                $updated = $db->table('billium_collection_recurring_owner')
                    ->where('id', $ownerId)
                    ->where('owner_revision', $currentRevision)
                    ->update([
                        'status' => RecurringOwnerStatus::Retired->value,
                        'owner_revision' => $currentRevision + 1,
                        'retired_reason' => $command->reason,
                        'updated_at' => $now,
                    ]);

                if ($updated !== 1) {
                    throw new LockPlanChanged('Recurring owner retire CAS failed');
                }

                return new CollectionReceipt('collection.recurring_owner.retired', [
                    'ownerId' => $ownerId,
                    'componentRef' => $command->componentRef,
                    'ownerRevision' => $currentRevision + 1,
                ]);
            },
        )->orThrow();

        $ownerId = RowCast::integer($outcome->body['ownerId'] ?? null, 'ownerId');
        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_recurring_owner')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('id', $ownerId)
            ->first() ?? throw new LogicException('Retired recurring owner missing');

        return DatabaseDunningQueries::hydrateRecurringOwner($row);
    }

    #[Override]
    public function assertBilliumSchedulerAllowed(CollectionScope $scope, string $componentRef): void
    {
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $scope);
        RowCast::bounded($componentRef, 'componentRef');

        $active = $this->databases->connection($this->options->connection)
            ->table('billium_collection_recurring_owner')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('component_ref', $componentRef)
            ->where('status', RecurringOwnerStatus::Active->value)
            ->first();

        if ($active !== null && RowCast::str($active->owner_mode, 'owner_mode') === RecurringOwnerMode::ProviderAutonomousSubscription->value) {
            throw new ProblemException(
                ProblemCode::OperationFinancialHold,
                'billium.collection.provider_autonomous_subscription_forbidden',
            );
        }
    }

    /**
     * @param  list<DunningPolicyStepDefinition>  $steps
     * @param  list<string>  $allowedChannels
     * @return list<array{
     *     stepNo: int,
     *     channel: string,
     *     delaySeconds: int,
     *     backoffMultiplierBps: int,
     *     maxBackoffSeconds: int,
     *     jitterMaxSeconds: int,
     *     sendNotification: bool,
     *     notificationTemplateRef: ?string
     * }>
     */
    private function normalizeSteps(array $steps, array $allowedChannels): array
    {
        $byStepNo = [];
        foreach ($steps as $step) {
            if ($step->stepNo < 1) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'orderedSteps[].stepNo must be >= 1');
            }
            if (isset($byStepNo[$step->stepNo])) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'orderedSteps contains duplicate stepNo '.$step->stepNo);
            }
            if (! in_array($step->channel->value, $allowedChannels, true)) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'step channel must be listed in allowedChannels');
            }
            if ($step->delaySeconds < 0) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'orderedSteps[].delaySeconds must be nonnegative');
            }
            if ($step->backoffMultiplierBps < 10000) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'orderedSteps[].backoffMultiplierBps must be >= 10000');
            }
            $maxBackoff = $step->maxBackoffSeconds === 0 ? $step->delaySeconds : $step->maxBackoffSeconds;
            if ($maxBackoff < $step->delaySeconds) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'orderedSteps[].maxBackoffSeconds must be >= delaySeconds');
            }
            if ($step->jitterMaxSeconds < 0) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'orderedSteps[].jitterMaxSeconds must be nonnegative');
            }
            $templateRef = $step->notificationTemplateRef;
            if ($step->sendNotification) {
                if ($templateRef === null || $templateRef === '') {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'notificationTemplateRef is required when sendNotification is true');
                }
                RowCast::bounded($templateRef, 'notificationTemplateRef');
            } elseif ($templateRef !== null) {
                RowCast::bounded($templateRef, 'notificationTemplateRef');
            }

            $byStepNo[$step->stepNo] = [
                'stepNo' => $step->stepNo,
                'channel' => $step->channel->value,
                'delaySeconds' => $step->delaySeconds,
                'backoffMultiplierBps' => $step->backoffMultiplierBps,
                'maxBackoffSeconds' => $maxBackoff,
                'jitterMaxSeconds' => $step->jitterMaxSeconds,
                'sendNotification' => $step->sendNotification,
                'notificationTemplateRef' => $templateRef,
            ];
        }

        ksort($byStepNo, SORT_NUMERIC);
        $expected = 1;
        foreach (array_keys($byStepNo) as $stepNo) {
            if ($stepNo !== $expected) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'orderedSteps must be strictly contiguous starting at stepNo 1');
            }
            $expected++;
        }

        return array_values($byStepNo);
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
                throw new LogicException('Collection policy operations must run outside every database transaction');
            }
        }
    }
}
