<?php

declare(strict_types=1);

namespace Billium\Collection\Internal\Services;

use Billium\Collection\Contracts\CollectionManualInterventionService;
use Billium\Collection\Data\ApplyManualIntervention;
use Billium\Collection\Data\ManualInterventionReceipt;
use Billium\Collection\Enums\CollectionSignalKind;
use Billium\Collection\Enums\CollectionStepStatus;
use Billium\Collection\Enums\DunningCaseStatus;
use Billium\Collection\Enums\ManualOverrideAction;
use Billium\Collection\Internal\Canonical\CanonicalJson;
use Billium\Collection\Internal\CollectionReceipt;
use Billium\Collection\Internal\Queries\DatabaseCollectionInterventionQueries;
use Billium\Collection\Internal\Queries\DatabaseDunningQueries;
use Billium\Collection\Internal\Support\PanCvvGuard;
use Billium\Collection\Internal\Support\RowCast;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runner\LockScope;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use PDO;
use stdClass;

#[Internal]
final readonly class DatabaseCollectionManualInterventionService implements CollectionManualInterventionService
{
    /** @var list<string> */
    private const array COLLECTION_MODULES = ['platform', 'ledger', 'execution', 'collection'];

    public function __construct(
        private DatabaseManager $databases,
        private OperationRunner $runner,
        private CallerContext $callers,
        private BilliumOptions $options,
    ) {}

    #[Override]
    public function applyOverride(ApplyManualIntervention $command): ManualInterventionReceipt
    {
        $this->assertOutsideTransaction();
        $caller = $this->callers->current();
        RowCast::assertScope($caller, $command->scope);
        $privilegeRef = self::assertSellerPrivilege($caller);

        RowCast::bounded($command->commandKey, 'commandKey');
        RowCast::bounded($command->interventionRef, 'interventionRef');
        RowCast::bounded($command->caseRef, 'caseRef');

        if ($command->action === ManualOverrideAction::ForgePaymentSuccess) {
            throw new ProblemException(
                ProblemCode::OperationFinancialHold,
                'billium.collection.manual_override_cannot_forge_payment_success',
            );
        }
        if ($command->action === ManualOverrideAction::ClearUnknownOutcome) {
            throw new ProblemException(
                ProblemCode::OperationFinancialHold,
                'billium.collection.manual_override_cannot_clear_unknown',
            );
        }

        if (trim($command->reason) === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.collection.manual_override_reason_required');
        }
        RowCast::bounded($command->reason, 'reason', 500);
        PanCvvGuard::assertOpaqueReference($command->reason, 'reason');

        if (trim($command->recoveryProofRef) === '') {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.collection.manual_override_recovery_proof_required');
        }
        PanCvvGuard::assertOpaqueReference($command->recoveryProofRef, 'recoveryProofRef');
        RowCast::digest($command->recoveryProofDigest, 'recoveryProofDigest');

        if ($command->expectedCaseRevision < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'expectedCaseRevision must be >= 1');
        }
        if ($command->stepRef !== null) {
            RowCast::bounded($command->stepRef, 'stepRef');
        }
        if ($command->expectedStepRevision !== null && $command->expectedStepRevision < 1) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'expectedStepRevision must be >= 1');
        }
        if (in_array($command->action, [ManualOverrideAction::ResumeCase, ManualOverrideAction::RescheduleNextAttempt], true)
            && $command->nextAttemptAt === null) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'nextAttemptAt is required for resume or reschedule override');
        }

        $commandDigest = CanonicalJson::digest([
            'sellerRef' => $caller->sellerRef,
            'environment' => $caller->environment,
            'interventionRef' => $command->interventionRef,
            'caseRef' => $command->caseRef,
            'action' => $command->action->value,
            'expectedCaseRevision' => $command->expectedCaseRevision,
            'stepRef' => $command->stepRef,
            'expectedStepRevision' => $command->expectedStepRevision,
            'reason' => $command->reason,
            'recoveryProofRef' => $command->recoveryProofRef,
            'recoveryProofDigest' => $command->recoveryProofDigest,
            'nextAttemptAt' => $command->nextAttemptAt === null ? null : RowCast::formatUtc($command->nextAttemptAt),
        ]);

        $outcome = $this->runner->run(
            spec: new OperationSpec(
                commandKind: 'collection.manual_intervention.apply.v1',
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::FrozenIntentCompletion,
                modules: self::COLLECTION_MODULES,
                commandKey: $command->commandKey,
                digest: $commandDigest,
                commitKind: 'collection.manual_intervention.apply',
            ),
            body: function (TransactionContext $context) use ($caller, $command, $privilegeRef, $commandDigest): CollectionReceipt {
                $this->runner->assertCurrent($context, 'collection');
                $txCaller = $context->caller();
                RowCast::assertScope($txCaller, $command->scope);
                $txPrivilegeRef = self::assertSellerPrivilege($txCaller);
                if ($txCaller->actorRef !== $caller->actorRef || $txPrivilegeRef !== $privilegeRef) {
                    throw new ProblemException(ProblemCode::AuthDenied, 'billium.collection.manual_override_operator_changed');
                }

                $db = $this->databases->connection($context->connectionName());

                $caseRow = $db->table('billium_collection_dunning_case')
                    ->where('seller_ref', $txCaller->sellerRef)
                    ->where('environment', $txCaller->environment)
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
                    segment: DatabaseDunningCaseService::DUNNING_CASE_LOCK_SEGMENT,
                ));
                if ($locked !== [$caseId]) {
                    throw new LockPlanChanged('Dunning case vanished before manual intervention lock');
                }

                $existing = $db->table('billium_collection_manual_intervention')
                    ->where('seller_ref', $txCaller->sellerRef)
                    ->where('environment', $txCaller->environment)
                    ->where('intervention_ref', $command->interventionRef)
                    ->first();

                if ($existing !== null) {
                    if (RowCast::str($existing->receipt_digest, 'receipt_digest') !== $commandDigest) {
                        throw new ProblemException(
                            ProblemCode::IdempotencyConflict,
                            'billium.collection.manual_intervention_conflict',
                        );
                    }

                    return new CollectionReceipt('collection.manual_intervention.applied', [
                        'interventionId' => RowCast::integer($existing->id, 'id'),
                        'interventionRef' => $command->interventionRef,
                    ]);
                }

                $caseRow = $db->table('billium_collection_dunning_case')
                    ->where('id', $caseId)
                    ->first() ?? throw new LockPlanChanged('Dunning case missing after lock');
                $case = DatabaseDunningQueries::hydrateCase($caseRow);

                if (in_array($case->status, [
                    DunningCaseStatus::Exhausted,
                    DunningCaseStatus::Settled,
                    DunningCaseStatus::Cancelled,
                ], true)) {
                    throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.case_terminal');
                }

                if ($case->status === DunningCaseStatus::ParkedUnknown) {
                    throw new ProblemException(
                        ProblemCode::OperationFinancialHold,
                        'billium.collection.manual_override_cannot_clear_unknown',
                    );
                }

                $stepRows = $db->table('billium_collection_step')
                    ->where('dunning_case_id', $caseId)
                    ->orderBy('step_no')
                    ->get();

                $targetStep = null;
                $hasInFlightAuthorizedOrDispatched = false;
                $hasCommittedDispatchPermit = false;
                foreach ($stepRows as $sRow) {
                    $step = DatabaseDunningQueries::hydrateStep($sRow);
                    if ($step->status === CollectionStepStatus::Unknown) {
                        throw new ProblemException(
                            ProblemCode::OperationFinancialHold,
                            'billium.collection.manual_override_cannot_clear_unknown',
                        );
                    }
                    if (in_array($step->status, [CollectionStepStatus::Authorized, CollectionStepStatus::Dispatched], true)) {
                        $hasInFlightAuthorizedOrDispatched = true;
                        if ($db->table('billium_collection_dispatch_permit')->where('step_id', $step->id)->exists()) {
                            $hasCommittedDispatchPermit = true;
                        }
                    }
                    if ($command->stepRef !== null && $step->stepRef === $command->stepRef) {
                        $targetStep = $step;
                    }
                }

                if ($command->stepRef !== null) {
                    if ($targetStep === null) {
                        throw new ProblemException(ProblemCode::ReferenceUnknown, 'billium.collection.step_not_found');
                    }
                    if ($command->expectedStepRevision !== null && $targetStep->stepRevision !== $command->expectedStepRevision) {
                        throw new ProblemException(ProblemCode::IdempotencyConflict, 'billium.collection.step_revision_conflict');
                    }
                }

                if ($case->caseRevision !== $command->expectedCaseRevision) {
                    throw new ProblemException(ProblemCode::IdempotencyConflict, 'billium.collection.case_revision_conflict');
                }

                $utc = new DateTimeZone('UTC');
                $recordedAt = ($command->recordedAt ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
                $recordedUtc = RowCast::formatUtc($recordedAt);
                $newRevision = $case->caseRevision + 1;

                $beforeDigest = CanonicalJson::digest([
                    'caseRef' => $case->caseRef,
                    'caseRevision' => $case->caseRevision,
                    'status' => $case->status->value,
                    'nextAttemptAt' => $case->nextAttemptAt === null ? null : RowCast::formatUtc($case->nextAttemptAt),
                    'currentOpenMinor' => $case->currentOpenMinor,
                ]);

                $afterStatus = $case->status;
                $nextAttemptUtc = $case->nextAttemptAt === null ? null : RowCast::formatUtc($case->nextAttemptAt);
                $caseUpdate = [
                    'case_revision' => $newRevision,
                    'updated_at' => $recordedUtc,
                ];

                switch ($command->action) {
                    case ManualOverrideAction::PauseCase:
                        if (! in_array($case->status, [DunningCaseStatus::Open, DunningCaseStatus::Active], true)) {
                            throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.case_cannot_pause');
                        }
                        if ($hasCommittedDispatchPermit || $hasInFlightAuthorizedOrDispatched) {
                            throw new ProblemException(
                                ProblemCode::OperationFinancialHold,
                                'billium.collection.in_flight_dispatch_forbids_override',
                            );
                        }
                        $afterStatus = DunningCaseStatus::Paused;
                        $nextAttemptUtc = null;
                        $caseUpdate['status'] = $afterStatus->value;
                        $caseUpdate['next_attempt_at'] = null;
                        break;

                    case ManualOverrideAction::ResumeCase:
                        if ($case->status !== DunningCaseStatus::Paused) {
                            throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.case_not_paused');
                        }
                        $resumeAt = $command->nextAttemptAt?->setTimezone($utc)
                            ?? throw new ProblemException(ProblemCode::ValidationInvalid, 'nextAttemptAt is required for resume');
                        if ($resumeAt > $case->recoveryDeadlineAt) {
                            throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.recovery_horizon_exceeded');
                        }
                        $afterStatus = DunningCaseStatus::Open;
                        $nextAttemptUtc = RowCast::formatUtc($resumeAt);
                        $caseUpdate['status'] = $afterStatus->value;
                        $caseUpdate['next_attempt_at'] = $nextAttemptUtc;
                        break;

                    case ManualOverrideAction::RescheduleNextAttempt:
                        if ($case->status !== DunningCaseStatus::Open) {
                            throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.case_not_open_for_reschedule');
                        }
                        $rescheduledAt = $command->nextAttemptAt?->setTimezone($utc)
                            ?? throw new ProblemException(ProblemCode::ValidationInvalid, 'nextAttemptAt is required for reschedule');
                        if ($rescheduledAt > $case->recoveryDeadlineAt) {
                            throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.recovery_horizon_exceeded');
                        }
                        $afterStatus = DunningCaseStatus::Open;
                        $nextAttemptUtc = RowCast::formatUtc($rescheduledAt);
                        $caseUpdate['next_attempt_at'] = $nextAttemptUtc;
                        break;

                    case ManualOverrideAction::CancelCase:
                        if (! in_array($case->status, [DunningCaseStatus::Open, DunningCaseStatus::Paused], true)) {
                            throw new ProblemException(ProblemCode::OperationFinancialHold, 'billium.collection.case_cannot_cancel');
                        }
                        if ($hasCommittedDispatchPermit || $hasInFlightAuthorizedOrDispatched) {
                            throw new ProblemException(
                                ProblemCode::OperationFinancialHold,
                                'billium.collection.in_flight_dispatch_forbids_override',
                            );
                        }
                        $afterStatus = DunningCaseStatus::Cancelled;
                        $nextAttemptUtc = null;
                        $caseUpdate['status'] = $afterStatus->value;
                        $caseUpdate['active_step_no'] = null;
                        $caseUpdate['next_attempt_at'] = null;
                        $caseUpdate['closed_reason'] = 'manual_override:'.$command->interventionRef;
                        $caseUpdate['closed_at'] = $recordedUtc;
                        break;
                }

                $updated = $db->table('billium_collection_dunning_case')
                    ->where('id', $caseId)
                    ->where('case_revision', $case->caseRevision)
                    ->update($caseUpdate);

                if ($updated !== 1) {
                    throw new LockPlanChanged('Dunning case manual intervention CAS failed');
                }

                $afterDigest = CanonicalJson::digest([
                    'caseRef' => $case->caseRef,
                    'caseRevision' => $newRevision,
                    'status' => $afterStatus->value,
                    'nextAttemptAt' => $nextAttemptUtc,
                    'currentOpenMinor' => $case->currentOpenMinor,
                ]);

                $signalDedupeKey = 'manual-intervention:'.$command->interventionRef;
                $signalRef = DatabaseCollectionSignalService::signalRef(
                    $txCaller->sellerRef,
                    $txCaller->environment,
                    $case->caseRef,
                    CollectionSignalKind::ManualIntervention->value,
                    $signalDedupeKey,
                );

                DatabaseCollectionSignalService::insertSignalIdempotent(
                    db: $db,
                    sellerRef: $txCaller->sellerRef,
                    environment: $txCaller->environment,
                    dunningCaseId: $caseId,
                    caseRef: $case->caseRef,
                    billingAccountRef: $case->billingAccountRef,
                    obligationRef: $case->obligationRef,
                    componentRef: $case->componentRef,
                    kind: CollectionSignalKind::ManualIntervention,
                    dedupeKey: $signalDedupeKey,
                    caseRevision: $newRevision,
                    policyRef: $case->policyRef,
                    policyVersion: $case->policyVersion,
                    payload: [
                        'signalOnly' => true,
                        'interventionRef' => $command->interventionRef,
                        'action' => $command->action->value,
                        'actorRef' => $txCaller->actorRef,
                        'beforeStatus' => $case->status->value,
                        'afterStatus' => $afterStatus->value,
                        'fromCaseRevision' => $case->caseRevision,
                        'toCaseRevision' => $newRevision,
                        'recoveryProofRef' => $command->recoveryProofRef,
                        'paymentOutcomeMutated' => false,
                        'unknownOutcomeCleared' => false,
                    ],
                    effectiveAt: $recordedAt,
                    recordedAt: $recordedAt,
                );

                $inserted = $db->selectOne(
                    <<<'SQL'
                    INSERT INTO public.billium_collection_manual_intervention (
                        seller_ref, environment, intervention_ref,
                        dunning_case_id, case_ref, step_id, step_ref,
                        action, actor_ref, privilege_ref, reason,
                        recovery_proof_ref, recovery_proof_digest,
                        from_case_revision, to_case_revision,
                        before_status, after_status,
                        before_digest, after_digest, receipt_digest,
                        signal_ref, recorded_at
                    ) VALUES (
                        ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?,
                        ?, ?,
                        ?, ?,
                        ?, ?, ?,
                        ?, ?
                    )
                    RETURNING id
                    SQL,
                    [
                        $txCaller->sellerRef,
                        $txCaller->environment,
                        $command->interventionRef,
                        $caseId,
                        $case->caseRef,
                        $targetStep?->id,
                        $targetStep?->stepRef,
                        $command->action->value,
                        $txCaller->actorRef,
                        $txPrivilegeRef,
                        $command->reason,
                        $command->recoveryProofRef,
                        $command->recoveryProofDigest,
                        $case->caseRevision,
                        $newRevision,
                        $case->status->value,
                        $afterStatus->value,
                        $beforeDigest,
                        $afterDigest,
                        $commandDigest,
                        $signalRef,
                        $recordedUtc,
                    ],
                );

                if (! $inserted instanceof stdClass) {
                    throw new LogicException('Manual intervention insert did not return an id');
                }

                return new CollectionReceipt('collection.manual_intervention.applied', [
                    'interventionId' => RowCast::integer($inserted->id, 'id'),
                    'interventionRef' => $command->interventionRef,
                ]);
            },
        )->orThrow();

        $interventionId = RowCast::integer($outcome->body['interventionId'] ?? null, 'interventionId');
        $row = $this->databases->connection($this->options->connection)
            ->table('billium_collection_manual_intervention')
            ->where('seller_ref', $caller->sellerRef)
            ->where('environment', $caller->environment)
            ->where('id', $interventionId)
            ->first() ?? throw new LogicException('Manual intervention receipt missing after commit');

        return DatabaseCollectionInterventionQueries::hydrateManualIntervention($row);
    }

    private static function assertSellerPrivilege(Caller $caller): string
    {
        $sellerScoped = self::PRIVILEGE_MANUAL_OVERRIDE.':'.$caller->sellerRef;
        if (in_array($sellerScoped, $caller->permissions, true)) {
            return $sellerScoped;
        }
        if (in_array(self::PRIVILEGE_MANUAL_OVERRIDE, $caller->permissions, true)) {
            return self::PRIVILEGE_MANUAL_OVERRIDE;
        }

        throw new ProblemException(
            ProblemCode::AuthDenied,
            'billium.collection.manual_override_privilege_required',
        );
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
                throw new LogicException('Collection manual intervention operations must run outside every database transaction');
            }
        }
    }
}
