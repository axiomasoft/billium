<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Import;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Contracts\MoneyMath;
use Billium\Contracts\Contracts\MoneyMathFactory;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Platform\Contracts\Import\ImportBatchApplier;
use Billium\Platform\Contracts\Import\ImportIdentityStore;
use Billium\Platform\Contracts\Import\ImportMonetaryPort;
use Billium\Platform\Contracts\Import\ImportPreviewEvaluator;
use Billium\Platform\Contracts\Import\ImportReconciler;
use Billium\Platform\Data\Import\ImportApplyOptions;
use Billium\Platform\Data\Import\ImportApplyResult;
use Billium\Platform\Data\Import\ImportBatchStatus;
use Billium\Platform\Data\Import\ImportCorrectionResult;
use Billium\Platform\Data\Import\ImportLinkedCorrection;
use Billium\Platform\Data\Import\ImportManifest;
use Billium\Platform\Data\Import\ImportMappingRegistrationResult;
use Billium\Platform\Data\Import\ImportMappingStatus;
use Billium\Platform\Data\Import\ImportObligationWrite;
use Billium\Platform\Data\Import\ImportReceiptStatus;
use Billium\Platform\Data\Import\ImportReconciliationReport;
use Billium\Platform\Data\Import\ImportRecordApplyOutcome;
use Billium\Platform\Data\Import\ImportRecordApplyStatus;
use Billium\Platform\Data\Import\ImportRecordKind;
use Billium\Platform\Data\Import\ImportSettlementIntentWrite;
use Billium\Platform\Data\Import\ImportValidationStatus;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Platform\Internal\Feed\PartitionStore;
use Billium\Platform\Internal\Inbox\CustodyProcessor;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Override;
use Packman\Attributes\Internal;

/**
 * Dependency-ordered, chunked and crash-resumable external import applier over existing Billium monetary APIs (P9.3, D60, D71).
 *
 * Strictly enforces:
 * - Explicit 'import:apply' capability scope and frozen manifestDigest match prior to any mutation.
 * - Proven obligations run via LedgerParticipants::recordObligation in the SAME root Tx as mapping + applied receipt + outbox.
 * - Proven settlement targets run via LedgerParticipants::declareSettlementIntent before observation reduction; missing proof is never fabricated.
 * - Proven payment/refund observations commit in an import root Tx as 'observation_queued' + durable inbox custody + command outbox
 *   (never paid/applied, never direct INSERT into payments, never nested ObservationApplier).
 * - Independent observation-owner root Txs reduce one economic event per root Tx; import then adopts the real AllocationReceipt by query.
 * - Post-apply linked corrections use positive CorrectionBasis::derive obligations or negative credit notes without destructive rollback.
 */
#[Internal]
final readonly class DatabaseImportBatchApplier implements ImportBatchApplier
{
    private ImportStagedRecordHydrator $hydrator;

    private ImportLinkedCorrectionApplier $correctionApplier;

    private ImportObservationCoordinator $observationCoordinator;

    /**
     * @param  Closure(): OperationRunner  $runner
     * @param  Closure(): CallerContext  $callers
     * @param  Closure(): ImportMonetaryPort  $monetaryPort
     */
    public function __construct(
        DatabaseManager $databases,
        BilliumOptions $options,
        private ImportIdentityStore $store,
        private ImportPreviewEvaluator $previewEvaluator,
        private ImportReconciler $reconciler,
        PartitionStore $partitions,
        CustodyProcessor $custody,
        private Closure $runner,
        private Closure $callers,
        private Closure $monetaryPort,
        private Codec $codec = new Codec,
        ?MoneyMath $math = null,
    ) {
        $resolvedMath = $math ?? MoneyMathFactory::create();
        $envelopeBuilder = new ImportObservationEnvelopeBuilder($this->codec);
        $this->hydrator = new ImportStagedRecordHydrator(
            $databases,
            $options,
            $this->store,
            $envelopeBuilder,
            $this->monetaryPort,
            $this->codec,
        );
        $this->correctionApplier = new ImportLinkedCorrectionApplier(
            $this->store,
            $this->runner,
            $this->monetaryPort,
            $this->codec,
            $resolvedMath,
        );
        $this->observationCoordinator = new ImportObservationCoordinator(
            $databases,
            $this->store,
            $partitions,
            $custody,
            $envelopeBuilder,
            $this->hydrator,
            $this->runner,
            $this->monetaryPort,
            $this->codec,
        );
    }

    #[Override]
    public function apply(
        ImportManifest $manifest,
        array $records,
        ImportApplyOptions $options,
    ): ImportApplyResult {
        $caller = $this->assertApplyAuthorized($options, $manifest->installationRef, $manifest->environment, $manifest->sellerRef);
        if (
            preg_match('/^sha256:[0-9a-f]{64}$/', $options->expectedManifestDigest) !== 1
            || ! hash_equals($manifest->manifestDigest, $options->expectedManifestDigest)
        ) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'Import apply rejected before mutation: expectedManifestDigest does not match frozen manifestDigest',
            );
        }

        // Pre-mutation cross-seller / cross-environment & sourceAuthority authorization checks (IM-12).
        $needsSourceAuthority = false;
        foreach ($records as $record) {
            if (
                $record->identity->installationRef !== $caller->installationRef
                || $record->identity->environment !== $caller->environment
                || $record->identity->sellerRef !== $caller->sellerRef
            ) {
                throw new ProblemException(
                    ProblemCode::AuthDenied,
                    'Cross-seller or cross-environment import record rejected before mutation',
                );
            }
            if ($record->manifestDigest !== $manifest->manifestDigest) {
                throw new ProblemException(
                    ProblemCode::ValidationInvalid,
                    'Import record manifestDigest mismatch rejected before mutation',
                );
            }
            if (in_array($record->identity->recordKind, [ImportRecordKind::ProvenPaymentEvidence, ImportRecordKind::ProvenRefundEvidence], true)) {
                $needsSourceAuthority = true;
            }
        }
        if ($needsSourceAuthority && ! in_array($options->migrationSourceAuthority, $caller->sourceAuthorities, true)) {
            throw new ProblemException(
                ProblemCode::AuthDenied,
                "Caller lacks authenticated grant for migration sourceAuthority '{$options->migrationSourceAuthority}'",
            );
        }
        if (count($records) !== $manifest->recordCount) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                sprintf(
                    'Import apply rejected before mutation: supplied record count (%d) does not match manifest recordCount (%d)',
                    count($records),
                    $manifest->recordCount,
                ),
            );
        }
        if (! ImportStagedRecordHydrator::matchesInputInventory($manifest, $records, $this->codec)) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'Import apply rejected before mutation: supplied records inventory does not match manifest recordsChecksum',
            );
        }

        $preview = $this->previewEvaluator->evaluate($manifest, $records);
        $batchPk = $this->connection()->transaction(function () use ($manifest, $records, $preview): int {
            $stagedBatchPk = $this->store->stageBatch($manifest);
            foreach ($records as $idx => $record) {
                $this->store->stageRecord($stagedBatchPk, $record, $preview->records[$idx]);
            }

            return $stagedBatchPk;
        });

        return $this->driveBatch($batchPk, $manifest->batchId, $manifest->manifestDigest, $options, $caller);
    }

    #[Override]
    public function resume(
        string $batchId,
        ImportApplyOptions $options,
    ): ImportApplyResult {
        if (trim($batchId) === '' || strlen($batchId) > 200) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Import batchId must be bounded and nonempty');
        }
        $caller = ($this->callers)()->current();
        if ($options->capabilityScope !== ImportApplyOptions::REQUIRED_SCOPE) {
            throw new ProblemException(
                ProblemCode::AuthDenied,
                'Import resume requires explicit import:apply capability scope',
            );
        }
        if ($caller->actorRef === '') {
            throw new ProblemException(ProblemCode::AuthUnauthenticated, 'Import resume requires an authenticated actor');
        }

        $batchRow = $this->connection()->selectOne(
            'SELECT * FROM public.billium_import_batch '
            .'WHERE installation_ref = ? AND environment = ? AND seller_ref = ? AND batch_id = ?',
            [$caller->installationRef, $caller->environment, $caller->sellerRef, $batchId],
        );
        if (! is_object($batchRow)) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, "Unknown import batch '{$batchId}'");
        }

        $manifestDigest = ImportStagedRecordHydrator::strField($batchRow, 'manifest_digest');
        if (
            preg_match('/^sha256:[0-9a-f]{64}$/', $options->expectedManifestDigest) !== 1
            || ! hash_equals($manifestDigest, $options->expectedManifestDigest)
        ) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'Import resume rejected: expectedManifestDigest does not match staged batch manifestDigest',
            );
        }

        $batchPk = ImportStagedRecordHydrator::intField($batchRow, 'id');
        $hasObservations = $this->connection()->selectOne(
            'SELECT 1 FROM public.billium_import_record WHERE batch_pk = ? '
            ."AND record_kind IN ('proven_payment_evidence', 'proven_refund_evidence') LIMIT 1",
            [$batchPk],
        ) !== null;
        if ($hasObservations && ! in_array($options->migrationSourceAuthority, $caller->sourceAuthorities, true)) {
            throw new ProblemException(
                ProblemCode::AuthDenied,
                "Caller lacks authenticated grant for migration sourceAuthority '{$options->migrationSourceAuthority}'",
            );
        }

        return $this->driveBatch($batchPk, $batchId, $manifestDigest, $options, $caller);
    }

    #[Override]
    public function rejectBatch(string $batchId, string $reason): void
    {
        if (trim($batchId) === '' || strlen($batchId) > 200 || trim($reason) === '' || strlen($reason) > 200) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Batch rejection requires bounded batchId and reason');
        }
        $caller = ($this->callers)()->current();
        if ($caller->actorRef === '') {
            throw new ProblemException(ProblemCode::AuthUnauthenticated, 'Batch rejection requires an authenticated actor');
        }
        $row = $this->connection()->selectOne(
            'SELECT id, status FROM public.billium_import_batch '
            .'WHERE installation_ref = ? AND environment = ? AND seller_ref = ? AND batch_id = ?',
            [$caller->installationRef, $caller->environment, $caller->sellerRef, $batchId],
        );
        if (! is_object($row)) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, "Unknown import batch '{$batchId}'");
        }
        $status = ImportBatchStatus::from(ImportStagedRecordHydrator::strField($row, 'status'));
        if ($status === ImportBatchStatus::Rejected) {
            return;
        }
        if (! in_array($status, [ImportBatchStatus::Staged, ImportBatchStatus::Validated, ImportBatchStatus::Approved], true)) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                "Import batch in status '{$status->value}' cannot transition to rejected; use linked corrections after apply starts",
            );
        }
        $this->store->transitionBatch(ImportStagedRecordHydrator::intField($row, 'id'), ImportBatchStatus::Rejected);
    }

    #[Override]
    public function applyLinkedCorrection(ImportLinkedCorrection $correction): ImportCorrectionResult
    {
        $identity = $correction->originalIdentity;
        $caller = $this->assertApplyAuthorized(
            new ImportApplyOptions(
                capabilityScope: $correction->capabilityScope,
                expectedManifestDigest: 'sha256:'.str_repeat('0', 64),
            ),
            $identity->installationRef,
            $identity->environment,
            $identity->sellerRef,
        );

        return $this->correctionApplier->apply($correction, $caller);
    }

    private function driveBatch(
        int $batchPk,
        string $batchId,
        string $manifestDigest,
        ImportApplyOptions $options,
        Caller $caller,
    ): ImportApplyResult {
        $db = $this->connection();
        $batchRow = $db->selectOne('SELECT * FROM public.billium_import_batch WHERE id = ?', [$batchPk])
            ?? throw new ProblemException(ProblemCode::ReferenceUnknown, "Unknown import batch {$batchPk}");
        $status = ImportBatchStatus::from(ImportStagedRecordHydrator::strField($batchRow, 'status'));

        if (in_array($status, [ImportBatchStatus::Completed, ImportBatchStatus::Rejected, ImportBatchStatus::Quarantined], true)) {
            $reconciliation = $status === ImportBatchStatus::Completed
                ? $this->reconciler->reconcileBatch($batchId, $options->migrationSourceAuthority)
                : null;

            return $this->summarizeResult($batchPk, $batchId, $manifestDigest, $status, 0, 0, [], $reconciliation);
        }

        $stagedRows = ImportStagedRecordHydrator::objectRows($db->select('SELECT * FROM public.billium_import_record WHERE batch_pk = ? ORDER BY id ASC', [$batchPk]));
        $expectedRecordCount = ImportStagedRecordHydrator::intField($batchRow, 'record_count');
        $expectedRecordsChecksum = ImportStagedRecordHydrator::strField($batchRow, 'records_checksum');
        $inventoryCheck = ImportStagedRecordHydrator::verifyStagedRowsInventory(
            $stagedRows,
            $expectedRecordCount,
            $expectedRecordsChecksum,
            $this->codec,
        );
        if (! $inventoryCheck['countMatches'] || ! $inventoryCheck['checksumMatches']) {
            $reconciliation = $this->reconciler->reconcileBatch($batchId, $options->migrationSourceAuthority);
            if ($status === ImportBatchStatus::Staged && count($stagedRows) < $expectedRecordCount) {
                return $this->summarizeResult(
                    $batchPk,
                    $batchId,
                    $manifestDigest,
                    ImportBatchStatus::Staged,
                    0,
                    $expectedRecordCount - count($stagedRows),
                    [],
                    $reconciliation,
                );
            }
            $this->store->transitionBatch($batchPk, ImportBatchStatus::Quarantined);

            return $this->summarizeResult($batchPk, $batchId, $manifestDigest, ImportBatchStatus::Quarantined, 0, 0, [], $reconciliation);
        }

        // Pre-apply lifecycle: staged -> validated -> approved -> applying (or rejected / quarantined).
        if ($status === ImportBatchStatus::Staged) {
            $hasConflict = false;
            $hasRejected = false;
            $outcomes = [];
            foreach ($stagedRows as $row) {
                $valStatus = ImportValidationStatus::from(ImportStagedRecordHydrator::strField($row, 'validation_status'));
                if ($valStatus === ImportValidationStatus::Conflict) {
                    $hasConflict = true;
                    $outcomes[] = $this->hydrator->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
                } elseif ($valStatus === ImportValidationStatus::Rejected) {
                    $hasRejected = true;
                    $outcomes[] = $this->hydrator->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Rejected, ImportRecordApplyStatus::Rejected);
                }
            }
            if ($hasConflict) {
                $this->store->transitionBatch($batchPk, ImportBatchStatus::Quarantined);

                return $this->summarizeResult($batchPk, $batchId, $manifestDigest, ImportBatchStatus::Quarantined, 0, 0, $outcomes, null);
            }
            if ($hasRejected) {
                $this->store->transitionBatch($batchPk, ImportBatchStatus::Rejected);

                return $this->summarizeResult($batchPk, $batchId, $manifestDigest, ImportBatchStatus::Rejected, 0, 0, $outcomes, null);
            }
            $this->store->transitionBatch($batchPk, ImportBatchStatus::Validated);
            $status = ImportBatchStatus::Validated;
        }

        if ($status === ImportBatchStatus::Validated) {
            $approvalRef = $options->approvalRef ?? ('imp-approve:'.substr($manifestDigest, 7, 24));
            $this->store->transitionBatch($batchPk, ImportBatchStatus::Approved, $approvalRef, $options->authorityFenceRef);
            $status = ImportBatchStatus::Approved;
        }

        if ($status === ImportBatchStatus::Approved) {
            $this->store->transitionBatch($batchPk, ImportBatchStatus::Applying, $options->approvalRef, $options->authorityFenceRef);
            $status = ImportBatchStatus::Applying;
        }

        if ($status === ImportBatchStatus::Reconciled) {
            $reconciliation = $this->reconciler->reconcileBatch($batchId, $options->migrationSourceAuthority);
            $finalStatus = $reconciliation->reconciled ? ImportBatchStatus::Completed : ImportBatchStatus::Quarantined;
            $this->store->transitionBatch($batchPk, $finalStatus);

            return $this->summarizeResult($batchPk, $batchId, $manifestDigest, $finalStatus, 0, 0, [], $reconciliation);
        }

        // Build DAG-ordered execution worklist across chunks:
        // 1) nonfinancial_agreement_reference & unanchored_evidence
        // 2) proven_obligation (chronological)
        // 3) proven_invoice_history (chronological)
        // 4) proven_allocation + proven payment settlement intents
        // 5) proven_payment_evidence -> proven_refund_evidence (custody queueing)
        // 6) observation owner reduction + receipt adoption
        $outcomes = [];
        $processedSteps = 0;
        $maxSteps = $options->maxSteps;
        $chunkSize = max(1, $options->chunkSize);
        $quarantined = false;

        /** @var list<object> $stage1 */
        $stage1 = [];
        /** @var list<object> $stage2Obligations */
        $stage2Obligations = [];
        /** @var list<object> $stage3Invoices */
        $stage3Invoices = [];
        /** @var list<object> $stage4Allocations */
        $stage4Allocations = [];
        /** @var list<object> $stage5Payments */
        $stage5Payments = [];
        /** @var list<object> $stage5Refunds */
        $stage5Refunds = [];

        foreach ($stagedRows as $row) {
            $kind = ImportRecordKind::from(ImportStagedRecordHydrator::strField($row, 'record_kind'));
            $valStatus = ImportValidationStatus::from(ImportStagedRecordHydrator::strField($row, 'validation_status'));
            if ($valStatus === ImportValidationStatus::UnanchoredEvidence || $kind === ImportRecordKind::UnanchoredEvidence || $kind === ImportRecordKind::NonfinancialAgreementReference) {
                $stage1[] = $row;
            } elseif ($kind === ImportRecordKind::ProvenObligation) {
                $stage2Obligations[] = $row;
            } elseif ($kind === ImportRecordKind::ProvenInvoiceHistory) {
                $stage3Invoices[] = $row;
            } elseif ($kind === ImportRecordKind::ProvenAllocation) {
                $stage4Allocations[] = $row;
            } elseif ($kind === ImportRecordKind::ProvenPaymentEvidence) {
                $stage5Payments[] = $row;
            } elseif ($kind === ImportRecordKind::ProvenRefundEvidence) {
                $stage5Refunds[] = $row;
            }
        }

        $chronologicalSort = static function (mixed $a, mixed $b): int {
            $cmp = strcmp(
                ImportStagedRecordHydrator::strField($a, 'event_business_date'),
                ImportStagedRecordHydrator::strField($b, 'event_business_date'),
            );

            return $cmp !== 0 ? $cmp : (ImportStagedRecordHydrator::intField($a, 'id') <=> ImportStagedRecordHydrator::intField($b, 'id'));
        };
        usort($stage2Obligations, $chronologicalSort);
        usort($stage3Invoices, $chronologicalSort);
        usort($stage4Allocations, $chronologicalSort);
        usort($stage5Payments, $chronologicalSort);
        usort($stage5Refunds, $chronologicalSort);

        // Map sourceRecordId / paymentRef -> obligationRef / operationRef across the batch for deterministic linkage.
        $obligationRefBySourceId = $this->hydrator->collectKnownObligationRefs($stage2Obligations);
        $chargeOpRefByLookup = $this->hydrator->collectKnownChargeOperationRefs($stage5Payments);

        // Stage 1: Non-monetary & unanchored evidence records (zero monetary state).
        foreach (array_chunk($stage1, $chunkSize) as $chunk) {
            foreach ($chunk as $row) {
                if ($this->budgetReached($processedSteps, $maxSteps)) {
                    break 2;
                }
                $stepOutcome = $this->applyStage1Row($batchPk, $row, $processedSteps, $quarantined);
                $outcomes[] = $stepOutcome;
                if ($quarantined) {
                    break 2;
                }
            }
        }

        // Stage 2: Proven obligations via LedgerParticipants::recordObligation in the SAME root Tx as mapping + receipt + outbox.
        if (! $quarantined && ! $this->budgetReached($processedSteps, $maxSteps)) {
            foreach (array_chunk($stage2Obligations, $chunkSize) as $chunk) {
                foreach ($chunk as $row) {
                    if ($this->budgetReached($processedSteps, $maxSteps)) {
                        break 2;
                    }
                    $stepOutcome = $this->applyProvenObligationRow($batchPk, $row, $caller, $processedSteps, $quarantined);
                    $outcomes[] = $stepOutcome;
                    if ($stepOutcome->billiumTargetRef !== null) {
                        $obligationRefBySourceId[$stepOutcome->identity->sourceRecordId] = $stepOutcome->billiumTargetRef;
                    }
                    if ($quarantined) {
                        break 2;
                    }
                }
            }
        }

        // Stage 3: Historical invoices preserving originalIssuer & sourceNumber without issuing a second legal number.
        if (! $quarantined && ! $this->budgetReached($processedSteps, $maxSteps)) {
            foreach (array_chunk($stage3Invoices, $chunkSize) as $chunk) {
                foreach ($chunk as $row) {
                    if ($this->budgetReached($processedSteps, $maxSteps)) {
                        break 2;
                    }
                    $outcomes[] = $this->applyHistoricalInvoiceRow($batchPk, $row, $processedSteps, $quarantined);
                    if ($quarantined) {
                        break 2;
                    }
                }
            }
        }

        // Stage 4: Proven settlement intents via LedgerParticipants::declareSettlementIntent BEFORE observation reduction.
        if (! $quarantined && ! $this->budgetReached($processedSteps, $maxSteps)) {
            $aggregatedIntents = $this->buildAggregatedAllocationIntents(
                $stage4Allocations,
                $obligationRefBySourceId,
                $chargeOpRefByLookup,
            );
            foreach (array_chunk($stage4Allocations, $chunkSize) as $chunk) {
                foreach ($chunk as $row) {
                    if ($this->budgetReached($processedSteps, $maxSteps)) {
                        break 2;
                    }
                    $outcomes[] = $this->applyProvenAllocationRow(
                        $batchPk,
                        $row,
                        $caller,
                        $obligationRefBySourceId,
                        $chargeOpRefByLookup,
                        $aggregatedIntents,
                        $processedSteps,
                        $quarantined,
                    );
                    if ($quarantined) {
                        break 2;
                    }
                }
            }
            if (! $quarantined && ! $this->budgetReached($processedSteps, $maxSteps)) {
                foreach ($stage5Payments as $paymentRow) {
                    if ($this->budgetReached($processedSteps, $maxSteps)) {
                        break;
                    }
                    $this->observationCoordinator->ensureInlinePaymentSettlementIntent(
                        $paymentRow,
                        $caller,
                        $obligationRefBySourceId,
                        $processedSteps,
                    );
                }
            }
        }

        // Stage 5: Queue proven payment & refund observations as 'observation_queued' + inbox custody + command outbox.
        $touchedPartitionIds = [];
        if (! $quarantined && ! $this->budgetReached($processedSteps, $maxSteps)) {
            foreach (array_chunk([...$stage5Payments, ...$stage5Refunds], $chunkSize) as $chunk) {
                foreach ($chunk as $row) {
                    if ($this->budgetReached($processedSteps, $maxSteps)) {
                        break 2;
                    }
                    $outcome = $this->observationCoordinator->queueObservationRow(
                        $batchPk,
                        $row,
                        $caller,
                        $options->migrationSourceAuthority,
                        $chargeOpRefByLookup,
                        $touchedPartitionIds,
                        $processedSteps,
                        $quarantined,
                    );
                    $outcomes[] = $outcome;
                    if ($quarantined) {
                        break 2;
                    }
                }
            }
        }

        if ($quarantined) {
            $this->store->transitionBatch($batchPk, ImportBatchStatus::Quarantined);

            return $this->summarizeResult($batchPk, $batchId, $manifestDigest, ImportBatchStatus::Quarantined, $processedSteps, 0, $outcomes, null);
        }

        // Stage 6: Independent observation-owner root reduction (1 event per root Tx) + idempotent receipt adoption by query.
        if ($options->reduceObservations && ! $this->budgetReached($processedSteps, $maxSteps) && ($stage5Payments !== [] || $stage5Refunds !== [])) {
            $this->observationCoordinator->reducePartitions($caller, $options->migrationSourceAuthority, $touchedPartitionIds);
        }

        if ($options->adoptReceipts && ! $this->budgetReached($processedSteps, $maxSteps)) {
            foreach ([...$stage5Payments, ...$stage5Refunds] as $row) {
                if ($this->budgetReached($processedSteps, $maxSteps)) {
                    break;
                }
                $adoptOutcome = $this->observationCoordinator->adoptObservationRow(
                    $row,
                    $options->migrationSourceAuthority,
                    $processedSteps,
                    $quarantined,
                );
                if ($adoptOutcome !== null) {
                    $outcomes = $this->replaceOrAppendOutcome($outcomes, $adoptOutcome);
                }
                if ($quarantined) {
                    $this->store->transitionBatch($batchPk, ImportBatchStatus::Quarantined);

                    return $this->summarizeResult($batchPk, $batchId, $manifestDigest, ImportBatchStatus::Quarantined, $processedSteps, 0, $outcomes, null);
                }
            }
        }

        $remainingSteps = $this->hydrator->countRemainingWork($batchPk, $options);
        if ($remainingSteps > 0 || ! $options->adoptReceipts) {
            return $this->summarizeResult($batchPk, $batchId, $manifestDigest, ImportBatchStatus::Applying, $processedSteps, $remainingSteps, $outcomes, null);
        }

        // Stage 7: Reconcile per-(sellerRef, currency, recordKind) and transition applying -> reconciled -> completed (or quarantined).
        $reconciliation = $this->reconciler->reconcileBatch($batchId, $options->migrationSourceAuthority);
        if ($reconciliation->discrepancies !== []) {
            $this->store->transitionBatch($batchPk, ImportBatchStatus::Quarantined);

            return $this->summarizeResult($batchPk, $batchId, $manifestDigest, ImportBatchStatus::Quarantined, $processedSteps, 0, $outcomes, $reconciliation);
        }
        if (! $reconciliation->reconciled) {
            // Parked observations awaiting external/account dependency stay in 'applying', never claiming completed/paid.
            return $this->summarizeResult($batchPk, $batchId, $manifestDigest, ImportBatchStatus::Applying, $processedSteps, 0, $outcomes, $reconciliation);
        }

        $this->store->transitionBatch($batchPk, ImportBatchStatus::Reconciled);
        $this->store->transitionBatch($batchPk, ImportBatchStatus::Completed);
        $finalReconciliation = $this->reconciler->reconcileBatch($batchId, $options->migrationSourceAuthority);

        return $this->summarizeResult($batchPk, $batchId, $manifestDigest, ImportBatchStatus::Completed, $processedSteps, 0, $outcomes, $finalReconciliation);
    }

    private function applyStage1Row(int $batchPk, object $row, int &$processedSteps, bool &$quarantined): ImportRecordApplyOutcome
    {
        $record = $this->hydrator->hydrateInputFromStagedRow($row);
        $recordPk = ImportStagedRecordHydrator::intField($row, 'id');
        $identity = $record->identity;
        $revision = $record->sourceRecordRevision;
        $factRef = $record->sourceFactRef ?? $identity->stableSourceFactRef($revision, $this->codec);
        $isUnanchored = ImportStagedRecordHydrator::strField($row, 'validation_status') === ImportValidationStatus::UnanchoredEvidence->value
            || $identity->recordKind === ImportRecordKind::UnanchoredEvidence;

        $existingMapping = $this->store->findMapping($identity, $revision);
        if ($existingMapping !== null) {
            if ($existingMapping->payloadDigest !== $record->payloadDigest || $existingMapping->status === ImportMappingStatus::Tombstoned) {
                $quarantined = true;
                $this->hydrator->markStagedRecordConflict($recordPk);

                return $this->hydrator->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
            }
            if ($isUnanchored) {
                $existingReceipt = $this->hydrator->findBatchRecordReceipt($batchPk, $recordPk, ImportReceiptStatus::UnanchoredEvidence);
                if ($existingReceipt !== null) {
                    return new ImportRecordApplyOutcome(
                        identity: $identity,
                        sourceRecordRevision: $revision,
                        sourceFactRef: $existingMapping->sourceFactRef,
                        status: ImportRecordApplyStatus::UnanchoredEvidence,
                        mappingRef: $existingMapping->mappingRef,
                        importReceiptRef: $existingReceipt,
                    );
                }
            } else {
                $applied = $this->store->findAppliedReceipt($identity, $revision);
                if ($applied !== null) {
                    $this->hydrator->markStagedRecordAlreadyApplied($recordPk, $existingMapping->sourceFactRef);

                    return new ImportRecordApplyOutcome(
                        identity: $identity,
                        sourceRecordRevision: $revision,
                        sourceFactRef: $existingMapping->sourceFactRef,
                        status: ImportRecordApplyStatus::AlreadyApplied,
                        mappingRef: $existingMapping->mappingRef,
                        importReceiptRef: $applied->receiptRef,
                        billiumTargetKind: $existingMapping->billiumTargetKind,
                        billiumTargetRef: $existingMapping->billiumTargetRef,
                    );
                }
            }
        }

        $processedSteps++;
        $reg = $this->store->registerMapping(
            identity: $identity,
            sourceRecordRevision: $revision,
            payloadDigest: $record->payloadDigest,
            sourceFactRef: $factRef,
            importPolicyVersion: $record->importPolicyVersion,
        );
        if (in_array($reg->outcome, [ImportMappingRegistrationResult::CONFLICT, ImportMappingRegistrationResult::TOMBSTONED_CONFLICT], true)) {
            $quarantined = true;
            $this->hydrator->markStagedRecordConflict($recordPk);

            return $this->hydrator->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
        }

        $mapping = $reg->mapping;
        if ($isUnanchored) {
            $receiptRef = ImportStagedRecordHydrator::boundedRef('imp-rcpt:unanchored:'.$batchPk.':'.$recordPk, 'imp-rcpt');
            $existingRef = $this->hydrator->findBatchRecordReceipt($batchPk, $recordPk, ImportReceiptStatus::UnanchoredEvidence);
            if ($existingRef === null) {
                $this->store->recordReceipt(
                    batchPk: $batchPk,
                    receiptRef: $receiptRef,
                    identity: $identity,
                    sourceRecordRevision: $revision,
                    payloadDigest: $record->payloadDigest,
                    status: ImportReceiptStatus::UnanchoredEvidence,
                    recordPk: $recordPk,
                    mappingPk: $mapping->id,
                    sourceFactRef: $mapping->sourceFactRef,
                    details: ['missingEvidence' => $record->missingEvidence],
                );
            } else {
                $receiptRef = $existingRef;
            }

            return new ImportRecordApplyOutcome(
                identity: $identity,
                sourceRecordRevision: $revision,
                sourceFactRef: $mapping->sourceFactRef,
                status: ImportRecordApplyStatus::UnanchoredEvidence,
                mappingRef: $mapping->mappingRef,
                importReceiptRef: $receiptRef,
            );
        }

        $targetRef = ImportStagedRecordHydrator::boundedRef('agreement-ref:'.$identity->sourceRecordId, 'agr-ref');
        $mapping = $this->store->assignMappingTarget($identity, $revision, 'nonfinancial_reference', $targetRef);
        $applied = $this->store->findAppliedReceipt($identity, $revision)
            ?? $this->store->recordReceipt(
                batchPk: $batchPk,
                receiptRef: ImportStagedRecordHydrator::boundedRef('imp-rcpt:applied:'.$mapping->sourceFactRef, 'imp-rcpt'),
                identity: $identity,
                sourceRecordRevision: $revision,
                payloadDigest: $record->payloadDigest,
                status: ImportReceiptStatus::Applied,
                recordPk: $recordPk,
                mappingPk: $mapping->id,
                sourceFactRef: $mapping->sourceFactRef,
                billiumReceiptRef: $targetRef,
            );
        $this->hydrator->markStagedRecordAlreadyApplied($recordPk, $mapping->sourceFactRef);

        return new ImportRecordApplyOutcome(
            identity: $identity,
            sourceRecordRevision: $revision,
            sourceFactRef: $mapping->sourceFactRef,
            status: ImportRecordApplyStatus::NonMonetaryMapped,
            mappingRef: $mapping->mappingRef,
            importReceiptRef: $applied->receiptRef,
            billiumTargetKind: $mapping->billiumTargetKind,
            billiumTargetRef: $mapping->billiumTargetRef,
        );
    }

    private function applyProvenObligationRow(
        int $batchPk,
        object $row,
        Caller $caller,
        int &$processedSteps,
        bool &$quarantined,
    ): ImportRecordApplyOutcome {
        $record = $this->hydrator->hydrateInputFromStagedRow($row);
        $recordPk = ImportStagedRecordHydrator::intField($row, 'id');
        $identity = $record->identity;
        $revision = $record->sourceRecordRevision;
        $factRef = $record->sourceFactRef ?? $identity->stableSourceFactRef($revision, $this->codec);

        // Fast-path checkpoint check before opening a root transaction.
        $existingMapping = $this->store->findMapping($identity, $revision);
        if ($existingMapping !== null) {
            if ($existingMapping->payloadDigest !== $record->payloadDigest || $existingMapping->status === ImportMappingStatus::Tombstoned) {
                $quarantined = true;
                $this->hydrator->markStagedRecordConflict($recordPk);

                return $this->hydrator->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
            }
            $applied = $this->store->findAppliedReceipt($identity, $revision);
            if ($applied !== null && $existingMapping->billiumTargetRef !== null) {
                $this->hydrator->markStagedRecordAlreadyApplied($recordPk, $existingMapping->sourceFactRef);

                return new ImportRecordApplyOutcome(
                    identity: $identity,
                    sourceRecordRevision: $revision,
                    sourceFactRef: $existingMapping->sourceFactRef,
                    status: ImportRecordApplyStatus::AlreadyApplied,
                    mappingRef: $existingMapping->mappingRef,
                    importReceiptRef: $applied->receiptRef,
                    billiumTargetKind: $existingMapping->billiumTargetKind,
                    billiumTargetRef: $existingMapping->billiumTargetRef,
                );
            }
        }

        $money = $record->money;
        $frozen = $record->frozenPriceSnapshot;
        if ($money === null || $frozen === null) {
            throw new LogicException('Unanchored obligation reached proven obligation applier');
        }

        $origRefs = $record->originalBusinessRefs;
        $obligationRef = ImportStagedRecordHydrator::boundedRef(
            ImportStagedRecordHydrator::nonEmptyString($origRefs['obligationRef'] ?? null) ?? ('imp-obl:'.$identity->sourceRecordId),
            'imp-obl',
        );
        $customerRef = ImportStagedRecordHydrator::boundedRef(
            ImportStagedRecordHydrator::nonEmptyString($origRefs['customerRef'] ?? null) ?? ('imp-cust:'.$identity->sourceRecordId),
            'imp-cust',
        );
        $billingAccountRef = ImportStagedRecordHydrator::boundedRef(
            ImportStagedRecordHydrator::nonEmptyString($origRefs['billingAccountRef'] ?? null) ?? ('imp-acct:'.$customerRef),
            'imp-acct',
        );
        $componentRef = ImportStagedRecordHydrator::boundedRef(
            ImportStagedRecordHydrator::nonEmptyString($origRefs['componentRef'] ?? null)
                ?? ImportStagedRecordHydrator::nonEmptyString($frozen['termsRef'] ?? null)
                ?? ImportStagedRecordHydrator::nonEmptyString($origRefs['subscriptionRef'] ?? null)
                ?? 'imported-obligation',
            'imp-comp',
        );
        $priceVersionRef = ImportStagedRecordHydrator::nonEmptyString($frozen['priceVersionRef'] ?? null);
        $taxSnapshotRef = ImportStagedRecordHydrator::nonEmptyString($frozen['taxPolicyVersion'] ?? null)
            ?? ImportStagedRecordHydrator::nonEmptyString($frozen['taxSnapshotDigest'] ?? null);
        $termsDigest = is_string($frozen['priceDigest'] ?? null) && preg_match('/^sha256:[0-9a-f]{64}$/', $frozen['priceDigest']) === 1
            ? $frozen['priceDigest']
            : $this->codec->digest($frozen);

        $processedSteps++;
        $spec = new OperationSpec(
            commandKind: 'platform.import.obligation.v1',
            sellerRef: $caller->sellerRef,
            environment: $caller->environment,
            mode: FinancialMode::Active,
            operationClass: ModuleOperationClass::NewIntent,
            modules: ['ledger', 'platform'],
            commandKey: ImportStagedRecordHydrator::boundedRef($factRef, 'imp-obl-key'),
            digest: $record->payloadDigest,
            commitKind: 'platform.import.obligation',
        );

        /** @var ImportRecordApplyOutcome|null $captured */
        $captured = null;
        try {
            $storedOutcome = ($this->runner)()->run($spec, function (TransactionContext $context) use (
                $batchPk,
                $recordPk,
                $record,
                $identity,
                $revision,
                $factRef,
                $caller,
                $obligationRef,
                $billingAccountRef,
                $customerRef,
                $componentRef,
                $money,
                $termsDigest,
                $priceVersionRef,
                $taxSnapshotRef,
                &$captured,
            ): Receipt {
                ($this->runner)()->assertCurrent($context, 'platform');

                $reg = $this->store->registerMapping(
                    identity: $identity,
                    sourceRecordRevision: $revision,
                    payloadDigest: $record->payloadDigest,
                    sourceFactRef: $factRef,
                    importPolicyVersion: $record->importPolicyVersion,
                );
                if (in_array($reg->outcome, [ImportMappingRegistrationResult::CONFLICT, ImportMappingRegistrationResult::TOMBSTONED_CONFLICT], true)) {
                    throw new ProblemException(
                        ProblemCode::IdempotencyConflict,
                        'Source identity mapping conflict: '.($reg->conflictReason ?? 'conflict'),
                    );
                }

                $ledgerReceipt = ($this->monetaryPort)()->recordObligation($context, new ImportObligationWrite(
                    commandKey: ImportStagedRecordHydrator::boundedRef($factRef, 'imp-obl-cmd'),
                    sellerRef: $caller->sellerRef,
                    environment: $caller->environment,
                    billingAccountRef: $billingAccountRef,
                    obligationRef: $obligationRef,
                    basisRef: ImportStagedRecordHydrator::boundedRef($reg->mapping->sourceFactRef, 'imp-basis'),
                    customerRef: $customerRef,
                    componentRef: $componentRef,
                    money: $money,
                    termsDigest: $termsDigest,
                    priceVersionRef: $priceVersionRef !== null ? ImportStagedRecordHydrator::boundedRef($priceVersionRef, 'pv') : null,
                    taxSnapshotRef: $taxSnapshotRef !== null ? ImportStagedRecordHydrator::boundedRef($taxSnapshotRef, 'tax') : null,
                    dueAt: $record->eventBusinessDate,
                ));

                $billiumReceiptRef = is_string($ledgerReceipt->body()['receipt_ref'] ?? null)
                    ? $ledgerReceipt->body()['receipt_ref']
                    : 'obligation:'.$obligationRef;

                $boundMapping = $this->store->assignMappingTarget($identity, $revision, 'obligation', $obligationRef);
                $importReceipt = $this->store->findAppliedReceipt($identity, $revision)
                    ?? $this->store->recordReceipt(
                        batchPk: $batchPk,
                        receiptRef: ImportStagedRecordHydrator::boundedRef('imp-rcpt:obl:'.$boundMapping->sourceFactRef, 'imp-rcpt'),
                        identity: $identity,
                        sourceRecordRevision: $revision,
                        payloadDigest: $record->payloadDigest,
                        status: ImportReceiptStatus::Applied,
                        recordPk: $recordPk,
                        mappingPk: $boundMapping->id,
                        sourceFactRef: $boundMapping->sourceFactRef,
                        billiumReceiptRef: $billiumReceiptRef,
                        details: [
                            'obligationRef' => $obligationRef,
                            'billingAccountRef' => $billingAccountRef,
                            'priceVersionRef' => $priceVersionRef,
                            'currency' => $money->currency->code,
                            'amountMinor' => $money->minor,
                        ],
                    );
                $this->hydrator->markStagedRecordAlreadyApplied($recordPk, $boundMapping->sourceFactRef);

                $captured = new ImportRecordApplyOutcome(
                    identity: $identity,
                    sourceRecordRevision: $revision,
                    sourceFactRef: $boundMapping->sourceFactRef,
                    status: $reg->outcome === ImportMappingRegistrationResult::DUPLICATE
                        ? ImportRecordApplyStatus::AlreadyApplied
                        : ImportRecordApplyStatus::Applied,
                    mappingRef: $boundMapping->mappingRef,
                    importReceiptRef: $importReceipt->receiptRef,
                    billiumTargetKind: 'obligation',
                    billiumTargetRef: $obligationRef,
                );

                return new PlatformReceipt('platform.import.obligation', [
                    'source_fact_ref' => $boundMapping->sourceFactRef,
                    'mapping_ref' => $boundMapping->mappingRef,
                    'obligation_ref' => $obligationRef,
                    'billium_receipt_ref' => $billiumReceiptRef,
                ]);
            });
            if ($storedOutcome->problem !== null) {
                throw new ProblemException($storedOutcome->problem, $storedOutcome->detail);
            }
        } catch (ProblemException $problem) {
            if ($problem->problem === ProblemCode::IdempotencyConflict) {
                $quarantined = true;
                $this->hydrator->markStagedRecordConflict($recordPk);

                return $this->hydrator->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
            }
            throw $problem;
        }

        if ($captured !== null) {
            return $captured;
        }

        // Replayed across another batch or concurrent worker: adopt the committed mapping & receipt.
        $committedMapping = $this->store->findMapping($identity, $revision)
            ?? throw new LogicException('Replayed obligation root without committed mapping');
        $committedReceipt = $this->store->findAppliedReceipt($identity, $revision);
        $this->hydrator->markStagedRecordAlreadyApplied($recordPk, $committedMapping->sourceFactRef);

        return new ImportRecordApplyOutcome(
            identity: $identity,
            sourceRecordRevision: $revision,
            sourceFactRef: $committedMapping->sourceFactRef,
            status: ImportRecordApplyStatus::AlreadyApplied,
            mappingRef: $committedMapping->mappingRef,
            importReceiptRef: $committedReceipt?->receiptRef,
            billiumTargetKind: $committedMapping->billiumTargetKind,
            billiumTargetRef: $committedMapping->billiumTargetRef,
        );
    }

    private function applyHistoricalInvoiceRow(
        int $batchPk,
        object $row,
        int &$processedSteps,
        bool &$quarantined,
    ): ImportRecordApplyOutcome {
        $record = $this->hydrator->hydrateInputFromStagedRow($row);
        $recordPk = ImportStagedRecordHydrator::intField($row, 'id');
        $identity = $record->identity;
        $revision = $record->sourceRecordRevision;
        $factRef = $record->sourceFactRef ?? $identity->stableSourceFactRef($revision, $this->codec);

        $existingMapping = $this->store->findMapping($identity, $revision);
        if ($existingMapping !== null) {
            if ($existingMapping->payloadDigest !== $record->payloadDigest || $existingMapping->status === ImportMappingStatus::Tombstoned) {
                $quarantined = true;
                $this->hydrator->markStagedRecordConflict($recordPk);

                return $this->hydrator->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
            }
            $applied = $this->store->findAppliedReceipt($identity, $revision);
            if ($applied !== null && $existingMapping->billiumTargetRef !== null) {
                $this->hydrator->markStagedRecordAlreadyApplied($recordPk, $existingMapping->sourceFactRef);

                return new ImportRecordApplyOutcome(
                    identity: $identity,
                    sourceRecordRevision: $revision,
                    sourceFactRef: $existingMapping->sourceFactRef,
                    status: ImportRecordApplyStatus::AlreadyApplied,
                    mappingRef: $existingMapping->mappingRef,
                    importReceiptRef: $applied->receiptRef,
                    billiumTargetKind: $existingMapping->billiumTargetKind,
                    billiumTargetRef: $existingMapping->billiumTargetRef,
                );
            }
        }

        $legal = $record->legalDocument ?? [];
        $originalIssuer = ImportStagedRecordHydrator::nonEmptyString($legal['originalIssuer'] ?? null)
            ?? ImportStagedRecordHydrator::nonEmptyString($record->originalBusinessRefs['originalIssuer'] ?? null);
        $sourceNumber = ImportStagedRecordHydrator::nonEmptyString($legal['sourceNumber'] ?? null)
            ?? ImportStagedRecordHydrator::nonEmptyString($record->originalBusinessRefs['sourceNumber'] ?? null);
        if ($originalIssuer === null || $sourceNumber === null) {
            throw new LogicException('Historical invoice without originalIssuer/sourceNumber reached stage 3');
        }

        $processedSteps++;
        // Preserve originalIssuer + sourceNumber in mapping without calling Documents numbering or issuing a Billium legal number (IM-16).
        $reg = $this->store->registerMapping(
            identity: $identity,
            sourceRecordRevision: $revision,
            payloadDigest: $record->payloadDigest,
            sourceFactRef: $factRef,
            originalIssuer: ImportStagedRecordHydrator::boundedRef($originalIssuer, 'issuer'),
            originalSourceNumber: ImportStagedRecordHydrator::boundedRef($sourceNumber, 'src-num'),
            importPolicyVersion: $record->importPolicyVersion,
        );
        if (in_array($reg->outcome, [ImportMappingRegistrationResult::CONFLICT, ImportMappingRegistrationResult::TOMBSTONED_CONFLICT], true)) {
            $quarantined = true;
            $this->hydrator->markStagedRecordConflict($recordPk);

            return $this->hydrator->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
        }

        $historicalTargetRef = ImportStagedRecordHydrator::boundedRef('historical-invoice:'.$originalIssuer.':'.$sourceNumber, 'hist-inv');
        $bound = $this->store->assignMappingTarget($identity, $revision, 'historical_invoice', $historicalTargetRef);
        $applied = $this->store->findAppliedReceipt($identity, $revision)
            ?? $this->store->recordReceipt(
                batchPk: $batchPk,
                receiptRef: ImportStagedRecordHydrator::boundedRef('imp-rcpt:hist-inv:'.$bound->sourceFactRef, 'imp-rcpt'),
                identity: $identity,
                sourceRecordRevision: $revision,
                payloadDigest: $record->payloadDigest,
                status: ImportReceiptStatus::Applied,
                recordPk: $recordPk,
                mappingPk: $bound->id,
                sourceFactRef: $bound->sourceFactRef,
                billiumReceiptRef: $historicalTargetRef,
                details: [
                    'originalIssuer' => $originalIssuer,
                    'sourceNumber' => $sourceNumber,
                    'secondLegalNumberIssued' => false,
                ],
            );
        $this->hydrator->markStagedRecordAlreadyApplied($recordPk, $bound->sourceFactRef);

        return new ImportRecordApplyOutcome(
            identity: $identity,
            sourceRecordRevision: $revision,
            sourceFactRef: $bound->sourceFactRef,
            status: ImportRecordApplyStatus::HistoricalInvoiceMapped,
            mappingRef: $bound->mappingRef,
            importReceiptRef: $applied->receiptRef,
            billiumTargetKind: 'historical_invoice',
            billiumTargetRef: $historicalTargetRef,
        );
    }

    /**
     * Pre-aggregates proven allocation targets sharing the same settlement intent (e.g. split payment across obligations A and B).
     *
     * @param  list<object>  $stage4Allocations
     * @param  array<string, string>  $obligationRefBySourceId
     * @param  array<string, string>  $chargeOpRefByLookup
     * @return array<string, array{obligationRefs: list<string>, requestedMinor: ?int}>
     */
    private function buildAggregatedAllocationIntents(
        array $stage4Allocations,
        array $obligationRefBySourceId,
        array $chargeOpRefByLookup,
    ): array {
        /**
         * @var array<string, array{
         *   billingAccountRef: string,
         *   currency: string,
         *   conflictedScope: bool,
         *   obligationRefs: list<string>,
         *   requestedMinor: ?int,
         *   hasNullMoney: bool
         * }> $groups
         */
        $groups = [];
        foreach ($stage4Allocations as $row) {
            $record = $this->hydrator->hydrateInputFromStagedRow($row);
            $origRefs = $record->originalBusinessRefs;
            $billingAccountRef = ImportStagedRecordHydrator::nonEmptyString($origRefs['billingAccountRef'] ?? null);
            $paymentLookup = ImportStagedRecordHydrator::nonEmptyString($origRefs['paymentRecordId'] ?? null)
                ?? ImportStagedRecordHydrator::nonEmptyString($origRefs['paymentRef'] ?? null)
                ?? ImportStagedRecordHydrator::nonEmptyString($origRefs['originalOperationRef'] ?? null);
            $obligationLookup = ImportStagedRecordHydrator::nonEmptyString($origRefs['obligationRecordId'] ?? null)
                ?? ImportStagedRecordHydrator::nonEmptyString($origRefs['obligationRef'] ?? null);
            $currency = $record->money?->currency->code ?? ImportStagedRecordHydrator::nonEmptyString($record->payload['currency'] ?? null);

            if ($billingAccountRef === null || $paymentLookup === null || $obligationLookup === null || $currency === null) {
                continue;
            }

            $targetObligationRef = $obligationRefBySourceId[$obligationLookup] ?? $obligationLookup;
            $chargeOpRef = $chargeOpRefByLookup[$paymentLookup] ?? $paymentLookup;
            $businessIntentRef = ImportStagedRecordHydrator::nonEmptyString($origRefs['settlementIntentRef'] ?? null)
                ?? ImportStagedRecordHydrator::boundedRef('imp-intent:'.$chargeOpRef, 'imp-int');

            $inspectedObl = ($this->monetaryPort)()->inspectObligation($targetObligationRef);
            if (! $inspectedObl['exists']) {
                continue;
            }
            if (($inspectedObl['billingAccountRef'] ?? '') !== $billingAccountRef || ($inspectedObl['currency'] ?? '') !== $currency) {
                continue;
            }

            if (! isset($groups[$businessIntentRef])) {
                $groups[$businessIntentRef] = [
                    'billingAccountRef' => $billingAccountRef,
                    'currency' => $currency,
                    'conflictedScope' => false,
                    'obligationRefs' => [$targetObligationRef],
                    'requestedMinor' => $record->money?->minor,
                    'hasNullMoney' => $record->money === null,
                ];
            } else {
                if (
                    $groups[$businessIntentRef]['billingAccountRef'] !== $billingAccountRef
                    || $groups[$businessIntentRef]['currency'] !== $currency
                ) {
                    $groups[$businessIntentRef]['conflictedScope'] = true;

                    continue;
                }
                if (! in_array($targetObligationRef, $groups[$businessIntentRef]['obligationRefs'], true)) {
                    $groups[$businessIntentRef]['obligationRefs'][] = $targetObligationRef;
                }
                if ($record->money === null || $groups[$businessIntentRef]['hasNullMoney']) {
                    $groups[$businessIntentRef]['hasNullMoney'] = true;
                    $groups[$businessIntentRef]['requestedMinor'] = null;
                } else {
                    $groups[$businessIntentRef]['requestedMinor'] = ($groups[$businessIntentRef]['requestedMinor'] ?? 0) + $record->money->minor;
                }
            }
        }

        $result = [];
        foreach ($groups as $intentRef => $group) {
            if (! $group['conflictedScope'] && $group['obligationRefs'] !== []) {
                $result[$intentRef] = [
                    'obligationRefs' => $group['obligationRefs'],
                    'requestedMinor' => $group['requestedMinor'],
                ];
            }
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $obligationRefBySourceId
     * @param  array<string, string>  $chargeOpRefByLookup
     * @param  array<string, array{obligationRefs: list<string>, requestedMinor: ?int}>  $aggregatedIntents
     */
    private function applyProvenAllocationRow(
        int $batchPk,
        object $row,
        Caller $caller,
        array $obligationRefBySourceId,
        array $chargeOpRefByLookup,
        array $aggregatedIntents,
        int &$processedSteps,
        bool &$quarantined,
    ): ImportRecordApplyOutcome {
        $record = $this->hydrator->hydrateInputFromStagedRow($row);
        $recordPk = ImportStagedRecordHydrator::intField($row, 'id');
        $identity = $record->identity;
        $revision = $record->sourceRecordRevision;
        $factRef = $record->sourceFactRef ?? $identity->stableSourceFactRef($revision, $this->codec);

        $existingMapping = $this->store->findMapping($identity, $revision);
        if ($existingMapping !== null) {
            if ($existingMapping->payloadDigest !== $record->payloadDigest || $existingMapping->status === ImportMappingStatus::Tombstoned) {
                $quarantined = true;
                $this->hydrator->markStagedRecordConflict($recordPk);

                return $this->hydrator->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
            }
            $applied = $this->store->findAppliedReceipt($identity, $revision);
            if ($applied !== null && $existingMapping->billiumTargetRef !== null) {
                $this->hydrator->markStagedRecordAlreadyApplied($recordPk, $existingMapping->sourceFactRef);

                return new ImportRecordApplyOutcome(
                    identity: $identity,
                    sourceRecordRevision: $revision,
                    sourceFactRef: $existingMapping->sourceFactRef,
                    status: ImportRecordApplyStatus::AlreadyApplied,
                    mappingRef: $existingMapping->mappingRef,
                    importReceiptRef: $applied->receiptRef,
                    billiumTargetKind: $existingMapping->billiumTargetKind,
                    billiumTargetRef: $existingMapping->billiumTargetRef,
                );
            }
        }

        $origRefs = $record->originalBusinessRefs;
        $billingAccountRef = ImportStagedRecordHydrator::nonEmptyString($origRefs['billingAccountRef'] ?? null);
        $paymentLookup = ImportStagedRecordHydrator::nonEmptyString($origRefs['paymentRecordId'] ?? null)
            ?? ImportStagedRecordHydrator::nonEmptyString($origRefs['paymentRef'] ?? null)
            ?? ImportStagedRecordHydrator::nonEmptyString($origRefs['originalOperationRef'] ?? null);
        $obligationLookup = ImportStagedRecordHydrator::nonEmptyString($origRefs['obligationRecordId'] ?? null)
            ?? ImportStagedRecordHydrator::nonEmptyString($origRefs['obligationRef'] ?? null);
        $currency = $record->money?->currency->code ?? ImportStagedRecordHydrator::nonEmptyString($record->payload['currency'] ?? null);

        if ($billingAccountRef === null || $paymentLookup === null || $obligationLookup === null || $currency === null) {
            // Do not fabricate missing account/intent/target proof: record as unanchored evidence.
            return $this->applyStage1Row($batchPk, $row, $processedSteps, $quarantined);
        }

        $targetObligationRef = $obligationRefBySourceId[$obligationLookup] ?? $obligationLookup;
        $chargeOpRef = $chargeOpRefByLookup[$paymentLookup] ?? $paymentLookup;
        $businessIntentRef = ImportStagedRecordHydrator::nonEmptyString($origRefs['settlementIntentRef'] ?? null)
            ?? ImportStagedRecordHydrator::boundedRef('imp-intent:'.$chargeOpRef, 'imp-int');

        $inspectedObl = ($this->monetaryPort)()->inspectObligation($targetObligationRef);
        if (! $inspectedObl['exists']) {
            // Target obligation is not proven in ledger: keep parked/unanchored without fabricating targets.
            return $this->applyStage1Row($batchPk, $row, $processedSteps, $quarantined);
        }

        $intentTargetRefs = $aggregatedIntents[$businessIntentRef]['obligationRefs'] ?? [$targetObligationRef];
        $intentRequestedMinor = array_key_exists($businessIntentRef, $aggregatedIntents)
            ? $aggregatedIntents[$businessIntentRef]['requestedMinor']
            : $record->money?->minor;

        $processedSteps++;
        $spec = new OperationSpec(
            commandKind: 'platform.import.settlement_intent.v1',
            sellerRef: $caller->sellerRef,
            environment: $caller->environment,
            mode: FinancialMode::Active,
            operationClass: ModuleOperationClass::NewIntent,
            modules: ['ledger', 'platform'],
            commandKey: ImportStagedRecordHydrator::boundedRef($factRef, 'imp-sint-key'),
            digest: $record->payloadDigest,
            commitKind: 'platform.import.settlement_intent',
        );

        /** @var ImportRecordApplyOutcome|null $captured */
        $captured = null;
        $storedOutcome = ($this->runner)()->run($spec, function (TransactionContext $context) use (
            $batchPk,
            $recordPk,
            $record,
            $identity,
            $revision,
            $factRef,
            $caller,
            $billingAccountRef,
            $businessIntentRef,
            $currency,
            $intentTargetRefs,
            $intentRequestedMinor,
            &$captured,
        ): Receipt {
            ($this->runner)()->assertCurrent($context, 'platform');

            $reg = $this->store->registerMapping(
                identity: $identity,
                sourceRecordRevision: $revision,
                payloadDigest: $record->payloadDigest,
                sourceFactRef: $factRef,
                importPolicyVersion: $record->importPolicyVersion,
            );
            if (in_array($reg->outcome, [ImportMappingRegistrationResult::CONFLICT, ImportMappingRegistrationResult::TOMBSTONED_CONFLICT], true)) {
                throw new ProblemException(ProblemCode::IdempotencyConflict, 'Proven allocation mapping conflict');
            }

            $intentReceipt = ($this->monetaryPort)()->declareSettlementIntent($context, new ImportSettlementIntentWrite(
                commandKey: ImportStagedRecordHydrator::boundedRef($factRef, 'imp-sint-cmd'),
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                billingAccountRef: $billingAccountRef,
                businessIntentRef: $businessIntentRef,
                currency: $currency,
                obligationRefs: $intentTargetRefs,
                requestedMinor: $intentRequestedMinor,
            ));

            $billiumReceiptRef = is_string($intentReceipt->body()['receipt_ref'] ?? null)
                ? $intentReceipt->body()['receipt_ref']
                : 'intent:'.$businessIntentRef;
            $bound = $this->store->assignMappingTarget($identity, $revision, 'settlement_intent', $businessIntentRef);
            $importReceipt = $this->store->findAppliedReceipt($identity, $revision)
                ?? $this->store->recordReceipt(
                    batchPk: $batchPk,
                    receiptRef: ImportStagedRecordHydrator::boundedRef('imp-rcpt:sint:'.$bound->sourceFactRef, 'imp-rcpt'),
                    identity: $identity,
                    sourceRecordRevision: $revision,
                    payloadDigest: $record->payloadDigest,
                    status: ImportReceiptStatus::Applied,
                    recordPk: $recordPk,
                    mappingPk: $bound->id,
                    sourceFactRef: $bound->sourceFactRef,
                    billiumReceiptRef: $billiumReceiptRef,
                    details: [
                        'businessIntentRef' => $businessIntentRef,
                        'obligationRefs' => $intentTargetRefs,
                    ],
                );
            $this->hydrator->markStagedRecordAlreadyApplied($recordPk, $bound->sourceFactRef);

            $captured = new ImportRecordApplyOutcome(
                identity: $identity,
                sourceRecordRevision: $revision,
                sourceFactRef: $bound->sourceFactRef,
                status: ImportRecordApplyStatus::SettlementIntentDeclared,
                mappingRef: $bound->mappingRef,
                importReceiptRef: $importReceipt->receiptRef,
                billiumTargetKind: 'settlement_intent',
                billiumTargetRef: $businessIntentRef,
            );

            return new PlatformReceipt('platform.import.settlement_intent', [
                'business_intent_ref' => $businessIntentRef,
            ]);
        });
        if ($storedOutcome->problem !== null) {
            if ($storedOutcome->problem === ProblemCode::IdempotencyConflict) {
                $quarantined = true;
                $this->hydrator->markStagedRecordConflict($recordPk);

                return $this->hydrator->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
            }
            throw new ProblemException($storedOutcome->problem, $storedOutcome->detail);
        }

        if ($captured !== null) {
            return $captured;
        }

        $committed = $this->store->findMapping($identity, $revision)
            ?? throw new LogicException('Replayed settlement intent without mapping');
        $committedReceipt = $this->store->findAppliedReceipt($identity, $revision);
        $this->hydrator->markStagedRecordAlreadyApplied($recordPk, $committed->sourceFactRef);

        return new ImportRecordApplyOutcome(
            identity: $identity,
            sourceRecordRevision: $revision,
            sourceFactRef: $committed->sourceFactRef,
            status: ImportRecordApplyStatus::AlreadyApplied,
            mappingRef: $committed->mappingRef,
            importReceiptRef: $committedReceipt?->receiptRef,
            billiumTargetKind: $committed->billiumTargetKind,
            billiumTargetRef: $committed->billiumTargetRef,
        );
    }

    /**
     * @param  list<ImportRecordApplyOutcome>  $outcomes
     * @return list<ImportRecordApplyOutcome>
     */
    private function replaceOrAppendOutcome(array $outcomes, ImportRecordApplyOutcome $replacement): array
    {
        foreach ($outcomes as $idx => $existing) {
            if (
                $existing->identity->sourceRecordId === $replacement->identity->sourceRecordId
                && $existing->identity->recordKind === $replacement->identity->recordKind
                && $existing->sourceRecordRevision === $replacement->sourceRecordRevision
            ) {
                $outcomes[$idx] = $replacement;

                return $outcomes;
            }
        }
        $outcomes[] = $replacement;

        return $outcomes;
    }

    /**
     * @param  list<ImportRecordApplyOutcome>  $outcomes
     */
    private function summarizeResult(
        int $batchPk,
        string $batchId,
        string $manifestDigest,
        ImportBatchStatus $batchStatus,
        int $processedSteps,
        int $remainingSteps,
        array $outcomes,
        ?ImportReconciliationReport $reconciliation,
    ): ImportApplyResult {
        $counts = [];
        foreach (ImportRecordApplyStatus::cases() as $case) {
            $counts[$case->value] = 0;
        }
        foreach ($outcomes as $outcome) {
            $counts[$outcome->status->value]++;
        }

        return new ImportApplyResult(
            batchPk: $batchPk,
            batchId: $batchId,
            manifestDigest: $manifestDigest,
            batchStatus: $batchStatus,
            processedSteps: $processedSteps,
            remainingSteps: $remainingSteps,
            counts: $counts,
            outcomes: $outcomes,
            reconciliation: $reconciliation,
        );
    }

    private function assertApplyAuthorized(
        ImportApplyOptions $options,
        string $installationRef,
        string $environment,
        string $sellerRef,
    ): Caller {
        if ($options->capabilityScope !== ImportApplyOptions::REQUIRED_SCOPE) {
            throw new ProblemException(
                ProblemCode::AuthDenied,
                'Import apply rejected before mutation: missing required import:apply capability scope',
            );
        }
        $caller = ($this->callers)()->current();
        if ($caller->actorRef === '' || $caller->installationRef === '' || $caller->sellerRef === '' || $caller->environment === '') {
            throw new ProblemException(ProblemCode::AuthUnauthenticated, 'Import apply rejected: unauthenticated or revoked caller');
        }
        if (
            $caller->installationRef !== $installationRef
            || $caller->environment !== $environment
            || $caller->sellerRef !== $sellerRef
        ) {
            throw new ProblemException(
                ProblemCode::AuthDenied,
                'Import apply rejected before mutation: caller scope differs from manifest seller/environment/installation',
            );
        }

        return $caller;
    }

    private function budgetReached(int $processedSteps, ?int $maxSteps): bool
    {
        return $maxSteps !== null && $processedSteps >= $maxSteps;
    }

    private function connection(): Connection
    {
        return $this->hydrator->connection();
    }
}
