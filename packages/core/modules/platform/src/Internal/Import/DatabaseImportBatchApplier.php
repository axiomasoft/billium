<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Import;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Contracts\MoneyMath;
use Billium\Contracts\Contracts\MoneyMathFactory;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Platform\Contracts\Import\ImportBatchApplier;
use Billium\Platform\Contracts\Import\ImportIdentityStore;
use Billium\Platform\Contracts\Import\ImportMonetaryPort;
use Billium\Platform\Contracts\Import\ImportPreviewEvaluator;
use Billium\Platform\Contracts\Import\ImportReconciler;
use Billium\Platform\Contracts\Inbox\CustodyHandler;
use Billium\Platform\Data\EventContracts\PreparedInternalCommand;
use Billium\Platform\Data\Import\ImportApplyOptions;
use Billium\Platform\Data\Import\ImportApplyResult;
use Billium\Platform\Data\Import\ImportBatchStatus;
use Billium\Platform\Data\Import\ImportCorrectionResult;
use Billium\Platform\Data\Import\ImportCreditNoteWrite;
use Billium\Platform\Data\Import\ImportEvidenceRef;
use Billium\Platform\Data\Import\ImportLinkedCorrection;
use Billium\Platform\Data\Import\ImportManifest;
use Billium\Platform\Data\Import\ImportMappingRegistrationResult;
use Billium\Platform\Data\Import\ImportMappingStatus;
use Billium\Platform\Data\Import\ImportObligationWrite;
use Billium\Platform\Data\Import\ImportObservationOutcome;
use Billium\Platform\Data\Import\ImportReceiptStatus;
use Billium\Platform\Data\Import\ImportReconciliationReport;
use Billium\Platform\Data\Import\ImportRecordApplyOutcome;
use Billium\Platform\Data\Import\ImportRecordApplyStatus;
use Billium\Platform\Data\Import\ImportRecordInput;
use Billium\Platform\Data\Import\ImportRecordKind;
use Billium\Platform\Data\Import\ImportSettlementIntentWrite;
use Billium\Platform\Data\Import\ImportSourceIdentity;
use Billium\Platform\Data\Import\ImportValidationStatus;
use Billium\Platform\Data\Inbox\CustodyDisposition;
use Billium\Platform\Data\Inbox\HandlerOutcome;
use Billium\Platform\Data\Inbox\InboxRow;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Billium\Platform\Internal\CommandOutbox\CommandPersistence;
use Billium\Platform\Internal\Feed\PartitionStore;
use Billium\Platform\Internal\Inbox\CustodyProcessor;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Override;
use Packman\Attributes\Internal;
use stdClass;

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
    private MoneyMath $math;

    private ImportObservationEnvelopeBuilder $envelopeBuilder;

    /**
     * @param  Closure(): OperationRunner  $runner
     * @param  Closure(): CallerContext  $callers
     * @param  Closure(): ImportMonetaryPort  $monetaryPort
     */
    public function __construct(
        private DatabaseManager $databases,
        private BilliumOptions $options,
        private ImportIdentityStore $store,
        private ImportPreviewEvaluator $previewEvaluator,
        private ImportReconciler $reconciler,
        private PartitionStore $partitions,
        private CustodyProcessor $custody,
        private Closure $runner,
        private Closure $callers,
        private Closure $monetaryPort,
        private Codec $codec = new Codec,
        ?MoneyMath $math = null,
    ) {
        $this->math = $math ?? MoneyMathFactory::create();
        $this->envelopeBuilder = new ImportObservationEnvelopeBuilder($this->codec);
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

        $preview = $this->previewEvaluator->evaluate($manifest, $records);
        $batchPk = $this->store->stageBatch($manifest);
        foreach ($records as $idx => $record) {
            $this->store->stageRecord($batchPk, $record, $preview->records[$idx]);
        }

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

        $manifestDigest = self::strField($batchRow, 'manifest_digest');
        if (
            preg_match('/^sha256:[0-9a-f]{64}$/', $options->expectedManifestDigest) !== 1
            || ! hash_equals($manifestDigest, $options->expectedManifestDigest)
        ) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'Import resume rejected: expectedManifestDigest does not match staged batch manifestDigest',
            );
        }

        $batchPk = self::intField($batchRow, 'id');
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
        $status = ImportBatchStatus::from(self::strField($row, 'status'));
        if ($status === ImportBatchStatus::Rejected) {
            return;
        }
        if (! in_array($status, [ImportBatchStatus::Staged, ImportBatchStatus::Validated, ImportBatchStatus::Approved], true)) {
            throw new ProblemException(
                ProblemCode::ValidationInvalid,
                "Import batch in status '{$status->value}' cannot transition to rejected; use linked corrections after apply starts",
            );
        }
        $this->store->transitionBatch(self::intField($row, 'id'), ImportBatchStatus::Rejected);
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

        $existingMapping = $this->store->findMapping($identity, $correction->originalRevision);
        if ($existingMapping === null) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, 'Original imported mapping not found for linked correction');
        }
        if ($existingMapping->status === ImportMappingStatus::Tombstoned && $correction->kind !== ImportLinkedCorrection::KIND_TOMBSTONE_MAPPING) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'Tombstoned import mapping cannot receive further corrections');
        }

        if ($correction->kind === ImportLinkedCorrection::KIND_TOMBSTONE_MAPPING) {
            $tombstoned = $existingMapping->status === ImportMappingStatus::Tombstoned
                ? $existingMapping
                : $this->store->tombstoneMapping($identity, $correction->originalRevision, $correction->reason);

            return new ImportCorrectionResult(
                correctionRef: $correction->correctionRef,
                kind: $correction->kind,
                originalSourceFactRef: $existingMapping->sourceFactRef,
                correctionSourceFactRef: $existingMapping->sourceFactRef,
                mapping: $tombstoned,
                externalProviderActionDispatched: false,
            );
        }

        if ($correction->deltaMoney === null || $correction->deltaMoney->minor === 0) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Monetary linked correction requires non-zero deltaMoney');
        }

        $monetary = ($this->monetaryPort)();
        $originalTargetRef = $existingMapping->billiumTargetRef;
        if ($originalTargetRef === null) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'Cannot correct an imported record without an applied Billium target');
        }

        $correctionFactRef = $identity->stableSourceFactRef($correction->newRevision, $this->codec);
        $correctionPayloadDigest = $this->codec->digest([
            'correctionRef' => $correction->correctionRef,
            'deltaMoney' => $correction->deltaMoney->toWire(),
            'kind' => $correction->kind,
            'newRevision' => $correction->newRevision,
            'originalRevision' => $correction->originalRevision,
            'originalSourceFactRef' => $existingMapping->sourceFactRef,
            'reason' => $correction->reason,
        ]);

        // Replay check: if newRevision mapping already superseded originalRevision with identical payloadDigest, return it idempotently.
        $alreadySuperseded = $this->store->findMapping($identity, $correction->newRevision);
        if ($alreadySuperseded !== null) {
            if ($alreadySuperseded->payloadDigest !== $correctionPayloadDigest) {
                throw new ProblemException(ProblemCode::IdempotencyConflict, 'Correction revision already exists with different payload');
            }

            return new ImportCorrectionResult(
                correctionRef: $correction->correctionRef,
                kind: $correction->kind,
                originalSourceFactRef: $existingMapping->sourceFactRef,
                correctionSourceFactRef: $alreadySuperseded->sourceFactRef,
                mapping: $alreadySuperseded,
                billiumReceiptRef: $alreadySuperseded->billiumTargetRef,
                totalDelta: $correction->deltaMoney,
                externalProviderActionDispatched: false,
            );
        }

        $inspected = $monetary->inspectObligation($originalTargetRef);
        if (! $inspected['exists']) {
            throw new ProblemException(ProblemCode::ReferenceUnknown, "Original obligation '{$originalTargetRef}' not found in ledger");
        }
        $currency = $inspected['currency'] ?? '';
        if ($currency !== $correction->deltaMoney->currency->code) {
            throw new ProblemException(ProblemCode::MoneyCurrencyMismatch, 'Linked correction currency differs from original obligation');
        }

        $billingAccountRef = $correction->billingAccountRef ?? ($inspected['billingAccountRef'] ?? '');
        $customerRef = $correction->customerRef ?? ($inspected['customerRef'] ?? '');
        $originalBasisRef = $inspected['basisRef'] ?? '';
        $termsDigest = $correction->termsDigest ?? ($inspected['termsDigest'] ?? '');
        $priceVersionRef = $correction->priceVersionRef ?? ($inspected['priceVersionRef'] ?? null);
        $taxSnapshotRef = $correction->taxSnapshotRef ?? ($inspected['taxSnapshotRef'] ?? null);

        $spec = new OperationSpec(
            commandKind: 'platform.import.linked_correction.v1',
            sellerRef: $caller->sellerRef,
            environment: $caller->environment,
            mode: FinancialMode::Active,
            operationClass: ModuleOperationClass::NewIntent,
            modules: ['ledger', 'platform'],
            commandKey: 'imp-corr:'.$correction->correctionRef,
            digest: $correctionPayloadDigest,
            commitKind: 'platform.import.linked_correction',
        );

        /** @var ImportCorrectionResult|null $capturedResult */
        $capturedResult = null;
        ($this->runner)()->run($spec, function (TransactionContext $context) use (
            $correction,
            $identity,
            $existingMapping,
            $correctionFactRef,
            $correctionPayloadDigest,
            $monetary,
            $caller,
            $originalTargetRef,
            $billingAccountRef,
            $customerRef,
            $originalBasisRef,
            $termsDigest,
            $priceVersionRef,
            $taxSnapshotRef,
            $inspected,
            &$capturedResult,
        ): Receipt {
            ($this->runner)()->assertCurrent($context, 'platform');
            $delta = $correction->deltaMoney ?? throw new LogicException('Missing deltaMoney');

            if ($correction->kind === ImportLinkedCorrection::KIND_POSITIVE_OBLIGATION) {
                if ($delta->minor <= 0) {
                    throw new ProblemException(ProblemCode::ValidationInvalid, 'Positive obligation correction requires positive Money');
                }
                $allocatedComponents = [];
                if ($correction->componentWeights !== []) {
                    $weights = array_map(static fn (array $w): string => (string) $w['weightMinor'], $correction->componentWeights);
                    $shares = $this->math->allocate($delta, $weights);
                    $primaryTargetRef = null;
                    foreach ($correction->componentWeights as $idx => $comp) {
                        $share = $shares[$idx];
                        if ($share->minor <= 0) {
                            continue;
                        }
                        $compCorrectionRef = $correction->correctionRef.':'.$comp['componentRef'];
                        $derivedBasis = $monetary->derivePositiveCorrectionBasis($originalBasisRef, $compCorrectionRef, $correction->newRevision);
                        $newOblRef = self::boundedRef('imp-corr-obl:'.$correction->correctionRef.':'.$comp['componentRef'], 'imp-cobl');
                        $monetary->recordObligation($context, new ImportObligationWrite(
                            commandKey: self::boundedRef('imp-corr-cmd:'.$compCorrectionRef, 'imp-ccmd'),
                            sellerRef: $caller->sellerRef,
                            environment: $caller->environment,
                            billingAccountRef: $billingAccountRef,
                            obligationRef: $newOblRef,
                            basisRef: self::boundedRef($derivedBasis, 'corr-basis'),
                            customerRef: $customerRef,
                            componentRef: $comp['componentRef'],
                            money: $share,
                            termsDigest: $termsDigest,
                            priceVersionRef: $priceVersionRef,
                            taxSnapshotRef: $taxSnapshotRef,
                        ));
                        $primaryTargetRef ??= $newOblRef;
                        $allocatedComponents[] = [
                            'componentRef' => $comp['componentRef'],
                            'obligationRef' => $newOblRef,
                            'amount' => $share->toWire(),
                        ];
                    }
                    $targetRef = $primaryTargetRef ?? throw new ProblemException(ProblemCode::ValidationInvalid, 'Component allocation produced zero obligations');
                    $receiptRef = 'obligation:'.$targetRef;
                } else {
                    $derivedBasis = $monetary->derivePositiveCorrectionBasis($originalBasisRef, $correction->correctionRef, $correction->newRevision);
                    $targetRef = self::boundedRef('imp-corr-obl:'.$correction->correctionRef, 'imp-cobl');
                    $componentRefVal = $inspected['componentRef'] ?? 'imported-component';
                    $receipt = $monetary->recordObligation($context, new ImportObligationWrite(
                        commandKey: self::boundedRef('imp-corr-cmd:'.$correction->correctionRef, 'imp-ccmd'),
                        sellerRef: $caller->sellerRef,
                        environment: $caller->environment,
                        billingAccountRef: $billingAccountRef,
                        obligationRef: $targetRef,
                        basisRef: self::boundedRef($derivedBasis, 'corr-basis'),
                        customerRef: $customerRef,
                        componentRef: $componentRefVal,
                        money: $delta,
                        termsDigest: $termsDigest,
                        priceVersionRef: $priceVersionRef,
                        taxSnapshotRef: $taxSnapshotRef,
                    ));
                    $receiptRef = is_string($receipt->body()['receipt_ref'] ?? null) ? $receipt->body()['receipt_ref'] : 'obligation:'.$targetRef;
                    $allocatedComponents[] = [
                        'componentRef' => $componentRefVal,
                        'obligationRef' => $targetRef,
                        'amount' => $delta->toWire(),
                    ];
                }

                $superseded = $this->store->supersedeMapping(
                    identity: $identity,
                    previousRevision: $correction->originalRevision,
                    newRevision: $correction->newRevision,
                    newPayloadDigest: $correctionPayloadDigest,
                    newSourceFactRef: $correctionFactRef,
                    importPolicyVersion: $existingMapping->importPolicyVersion,
                );
                $bound = $this->store->assignMappingTarget($identity, $correction->newRevision, 'obligation', $targetRef);

                $capturedResult = new ImportCorrectionResult(
                    correctionRef: $correction->correctionRef,
                    kind: $correction->kind,
                    originalSourceFactRef: $existingMapping->sourceFactRef,
                    correctionSourceFactRef: $correctionFactRef,
                    mapping: $bound,
                    billiumReceiptRef: $receiptRef,
                    totalDelta: $delta,
                    allocatedComponents: $allocatedComponents,
                    externalProviderActionDispatched: false,
                );

                return new PlatformReceipt('platform.import.linked_correction', [
                    'correction_ref' => $correction->correctionRef,
                    'target_ref' => $targetRef,
                    'mapping_ref' => $superseded->mappingRef,
                ]);
            }

            if ($correction->kind === ImportLinkedCorrection::KIND_NEGATIVE_CREDIT_NOTE) {
                $magnitude = $delta->minor < 0 ? new Money(-$delta->minor, $delta->currency) : $delta;
                $invoiceRef = $correction->invoiceRef ?? ($inspected['invoiceRef'] ?? null);
                if (! is_string($invoiceRef) || $invoiceRef === '') {
                    throw new ProblemException(
                        ProblemCode::ValidationInvalid,
                        'Negative credit note correction requires an issued invoiceRef',
                    );
                }
                $creditNoteRef = self::boundedRef('imp-cn:'.$correction->correctionRef, 'imp-cn');
                /** @var array{obligationRef: string, money: Money, componentRef?: string, taxSnapshotRef?: string} $lineItem */
                $lineItem = [
                    'obligationRef' => $originalTargetRef,
                    'money' => $magnitude,
                ];
                if (is_string($inspected['componentRef'] ?? null)) {
                    $lineItem['componentRef'] = $inspected['componentRef'];
                }
                if ($taxSnapshotRef !== null) {
                    $lineItem['taxSnapshotRef'] = $taxSnapshotRef;
                }
                $receipt = $monetary->issueCreditNote($context, new ImportCreditNoteWrite(
                    commandKey: self::boundedRef('imp-cn-cmd:'.$correction->correctionRef, 'imp-cncmd'),
                    sellerRef: $caller->sellerRef,
                    environment: $caller->environment,
                    billingAccountRef: $billingAccountRef,
                    creditNoteRef: $creditNoteRef,
                    invoiceRef: $invoiceRef,
                    mode: $correction->creditNoteMode,
                    lines: [$lineItem],
                    reason: $correction->reason,
                ));
                $receiptRef = is_string($receipt->body()['receipt_ref'] ?? null) ? $receipt->body()['receipt_ref'] : 'credit-note:'.$creditNoteRef;

                $this->store->supersedeMapping(
                    identity: $identity,
                    previousRevision: $correction->originalRevision,
                    newRevision: $correction->newRevision,
                    newPayloadDigest: $correctionPayloadDigest,
                    newSourceFactRef: $correctionFactRef,
                    importPolicyVersion: $existingMapping->importPolicyVersion,
                );
                $bound = $this->store->assignMappingTarget($identity, $correction->newRevision, 'credit_note', $creditNoteRef);

                $capturedResult = new ImportCorrectionResult(
                    correctionRef: $correction->correctionRef,
                    kind: $correction->kind,
                    originalSourceFactRef: $existingMapping->sourceFactRef,
                    correctionSourceFactRef: $correctionFactRef,
                    mapping: $bound,
                    billiumReceiptRef: $receiptRef,
                    totalDelta: $magnitude,
                    externalProviderActionDispatched: false,
                );

                return new PlatformReceipt('platform.import.linked_correction', [
                    'correction_ref' => $correction->correctionRef,
                    'credit_note_ref' => $creditNoteRef,
                ]);
            }

            // KIND_DRAFT_ADJUSTMENT
            $adjustmentRef = self::boundedRef('imp-adj:'.$correction->correctionRef, 'imp-adj');
            $receipt = $monetary->adjustDraftObligation(
                context: $context,
                commandKey: self::boundedRef('imp-adj-cmd:'.$correction->correctionRef, 'imp-adjcmd'),
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                billingAccountRef: $billingAccountRef,
                adjustmentRef: $adjustmentRef,
                obligationRef: $originalTargetRef,
                delta: $delta,
                causeRef: $correction->reason,
            );
            $receiptRef = is_string($receipt->body()['receipt_ref'] ?? null) ? $receipt->body()['receipt_ref'] : 'adjustment:'.$adjustmentRef;

            $this->store->supersedeMapping(
                identity: $identity,
                previousRevision: $correction->originalRevision,
                newRevision: $correction->newRevision,
                newPayloadDigest: $correctionPayloadDigest,
                newSourceFactRef: $correctionFactRef,
                importPolicyVersion: $existingMapping->importPolicyVersion,
            );
            $bound = $this->store->assignMappingTarget($identity, $correction->newRevision, 'obligation_adjustment', $adjustmentRef);

            $capturedResult = new ImportCorrectionResult(
                correctionRef: $correction->correctionRef,
                kind: $correction->kind,
                originalSourceFactRef: $existingMapping->sourceFactRef,
                correctionSourceFactRef: $correctionFactRef,
                mapping: $bound,
                billiumReceiptRef: $receiptRef,
                totalDelta: $delta,
                externalProviderActionDispatched: false,
            );

            return new PlatformReceipt('platform.import.linked_correction', [
                'correction_ref' => $correction->correctionRef,
                'adjustment_ref' => $adjustmentRef,
            ]);
        });

        if ($capturedResult !== null) {
            return $capturedResult;
        }

        // Replayed root outcome: reconstruct from committed superseded mapping.
        $committed = $this->store->findMapping($identity, $correction->newRevision)
            ?? throw new LogicException('Linked correction replayed without a committed mapping');

        return new ImportCorrectionResult(
            correctionRef: $correction->correctionRef,
            kind: $correction->kind,
            originalSourceFactRef: $existingMapping->sourceFactRef,
            correctionSourceFactRef: $committed->sourceFactRef,
            mapping: $committed,
            billiumReceiptRef: $committed->billiumTargetRef,
            totalDelta: $correction->deltaMoney,
            externalProviderActionDispatched: false,
        );
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
        $status = ImportBatchStatus::from(self::strField($batchRow, 'status'));

        if (in_array($status, [ImportBatchStatus::Completed, ImportBatchStatus::Rejected, ImportBatchStatus::Quarantined], true)) {
            $reconciliation = $status === ImportBatchStatus::Completed
                ? $this->reconciler->reconcileBatch($batchId, $options->migrationSourceAuthority)
                : null;

            return $this->summarizeResult($batchPk, $batchId, $manifestDigest, $status, 0, 0, [], $reconciliation);
        }

        $stagedRows = self::objectRows($db->select('SELECT * FROM public.billium_import_record WHERE batch_pk = ? ORDER BY id ASC', [$batchPk]));

        // Pre-apply lifecycle: staged -> validated -> approved -> applying (or rejected / quarantined).
        if ($status === ImportBatchStatus::Staged) {
            $hasConflict = false;
            $hasRejected = false;
            $outcomes = [];
            foreach ($stagedRows as $row) {
                $valStatus = ImportValidationStatus::from(self::strField($row, 'validation_status'));
                if ($valStatus === ImportValidationStatus::Conflict) {
                    $hasConflict = true;
                    $outcomes[] = $this->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
                } elseif ($valStatus === ImportValidationStatus::Rejected) {
                    $hasRejected = true;
                    $outcomes[] = $this->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Rejected, ImportRecordApplyStatus::Rejected);
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
            $kind = ImportRecordKind::from(self::strField($row, 'record_kind'));
            $valStatus = ImportValidationStatus::from(self::strField($row, 'validation_status'));
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
            $cmp = strcmp(self::strField($a, 'event_business_date'), self::strField($b, 'event_business_date'));

            return $cmp !== 0 ? $cmp : (self::intField($a, 'id') <=> self::intField($b, 'id'));
        };
        usort($stage2Obligations, $chronologicalSort);
        usort($stage3Invoices, $chronologicalSort);
        usort($stage4Allocations, $chronologicalSort);
        usort($stage5Payments, $chronologicalSort);
        usort($stage5Refunds, $chronologicalSort);

        // Map sourceRecordId / paymentRef -> obligationRef / operationRef across the batch for deterministic linkage.
        $obligationRefBySourceId = $this->collectKnownObligationRefs($stage2Obligations);
        $chargeOpRefByLookup = $this->collectKnownChargeOperationRefs($stage5Payments);

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
                    $this->ensureInlinePaymentSettlementIntent(
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
                    $outcome = $this->queueObservationRow(
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
            $monetary = ($this->monetaryPort)();
            if ($touchedPartitionIds === []) {
                $pendingPartitions = $db->select(
                    "SELECT id FROM public.billium_feed_partition WHERE channel = 'payment.observation' "
                    ."AND seller_ref = ? AND environment = ? AND state = 'active' "
                    .'AND accounted_sequence < custody_sequence AND source_authority = ? ORDER BY id ASC',
                    [$caller->sellerRef, $caller->environment, $options->migrationSourceAuthority],
                );
                foreach ($pendingPartitions as $pRow) {
                    $touchedPartitionIds[self::intField($pRow, 'id')] = true;
                }
            }
            if ($touchedPartitionIds !== []) {
                foreach (array_keys($touchedPartitionIds) as $pid) {
                    $monetary->reduceObservationPartition($pid);
                }
            } else {
                $monetary->reduceObservationPartition(null);
            }
        }

        if ($options->adoptReceipts && ! $this->budgetReached($processedSteps, $maxSteps)) {
            foreach ([...$stage5Payments, ...$stage5Refunds] as $row) {
                if ($this->budgetReached($processedSteps, $maxSteps)) {
                    break;
                }
                $adoptOutcome = $this->adoptObservationRow(
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

        $remainingSteps = $this->countRemainingWork($batchPk, $options);
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
        $record = $this->hydrateInputFromStagedRow($row);
        $recordPk = self::intField($row, 'id');
        $identity = $record->identity;
        $revision = $record->sourceRecordRevision;
        $factRef = $record->sourceFactRef ?? $identity->stableSourceFactRef($revision, $this->codec);
        $isUnanchored = self::strField($row, 'validation_status') === ImportValidationStatus::UnanchoredEvidence->value
            || $identity->recordKind === ImportRecordKind::UnanchoredEvidence;

        $existingMapping = $this->store->findMapping($identity, $revision);
        if ($existingMapping !== null) {
            if ($existingMapping->payloadDigest !== $record->payloadDigest || $existingMapping->status === ImportMappingStatus::Tombstoned) {
                $quarantined = true;
                $this->markStagedRecordConflict($recordPk);

                return $this->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
            }
            if ($isUnanchored) {
                $existingReceipt = $this->findBatchRecordReceipt($batchPk, $recordPk, ImportReceiptStatus::UnanchoredEvidence);
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
                    $this->markStagedRecordAlreadyApplied($recordPk, $existingMapping->sourceFactRef);

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
            $this->markStagedRecordConflict($recordPk);

            return $this->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
        }

        $mapping = $reg->mapping;
        if ($isUnanchored) {
            $receiptRef = self::boundedRef('imp-rcpt:unanchored:'.$batchPk.':'.$recordPk, 'imp-rcpt');
            $existingRef = $this->findBatchRecordReceipt($batchPk, $recordPk, ImportReceiptStatus::UnanchoredEvidence);
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

        $targetRef = self::boundedRef('agreement-ref:'.$identity->sourceRecordId, 'agr-ref');
        $mapping = $this->store->assignMappingTarget($identity, $revision, 'nonfinancial_reference', $targetRef);
        $applied = $this->store->findAppliedReceipt($identity, $revision);
        if ($applied === null) {
            $applied = $this->store->recordReceipt(
                batchPk: $batchPk,
                receiptRef: self::boundedRef('imp-rcpt:applied:'.$mapping->sourceFactRef, 'imp-rcpt'),
                identity: $identity,
                sourceRecordRevision: $revision,
                payloadDigest: $record->payloadDigest,
                status: ImportReceiptStatus::Applied,
                recordPk: $recordPk,
                mappingPk: $mapping->id,
                sourceFactRef: $mapping->sourceFactRef,
                billiumReceiptRef: $targetRef,
            );
        }
        $this->markStagedRecordAlreadyApplied($recordPk, $mapping->sourceFactRef);

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
        $record = $this->hydrateInputFromStagedRow($row);
        $recordPk = self::intField($row, 'id');
        $identity = $record->identity;
        $revision = $record->sourceRecordRevision;
        $factRef = $record->sourceFactRef ?? $identity->stableSourceFactRef($revision, $this->codec);

        // Fast-path checkpoint check before opening a root transaction.
        $existingMapping = $this->store->findMapping($identity, $revision);
        if ($existingMapping !== null) {
            if ($existingMapping->payloadDigest !== $record->payloadDigest || $existingMapping->status === ImportMappingStatus::Tombstoned) {
                $quarantined = true;
                $this->markStagedRecordConflict($recordPk);

                return $this->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
            }
            $applied = $this->store->findAppliedReceipt($identity, $revision);
            if ($applied !== null && $existingMapping->billiumTargetRef !== null) {
                $this->markStagedRecordAlreadyApplied($recordPk, $existingMapping->sourceFactRef);

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
        $obligationRef = self::boundedRef(
            self::nonEmptyString($origRefs['obligationRef'] ?? null) ?? ('imp-obl:'.$identity->sourceRecordId),
            'imp-obl',
        );
        $customerRef = self::boundedRef(
            self::nonEmptyString($origRefs['customerRef'] ?? null) ?? ('imp-cust:'.$identity->sourceRecordId),
            'imp-cust',
        );
        $billingAccountRef = self::boundedRef(
            self::nonEmptyString($origRefs['billingAccountRef'] ?? null) ?? ('imp-acct:'.$customerRef),
            'imp-acct',
        );
        $componentRef = self::boundedRef(
            self::nonEmptyString($origRefs['componentRef'] ?? null)
                ?? self::nonEmptyString($frozen['termsRef'] ?? null)
                ?? self::nonEmptyString($origRefs['subscriptionRef'] ?? null)
                ?? 'imported-obligation',
            'imp-comp',
        );
        $priceVersionRef = self::nonEmptyString($frozen['priceVersionRef'] ?? null);
        $taxSnapshotRef = self::nonEmptyString($frozen['taxPolicyVersion'] ?? null)
            ?? self::nonEmptyString($frozen['taxSnapshotDigest'] ?? null);
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
            commandKey: self::boundedRef($factRef, 'imp-obl-key'),
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
                    commandKey: self::boundedRef($factRef, 'imp-obl-cmd'),
                    sellerRef: $caller->sellerRef,
                    environment: $caller->environment,
                    billingAccountRef: $billingAccountRef,
                    obligationRef: $obligationRef,
                    basisRef: self::boundedRef($reg->mapping->sourceFactRef, 'imp-basis'),
                    customerRef: $customerRef,
                    componentRef: $componentRef,
                    money: $money,
                    termsDigest: $termsDigest,
                    priceVersionRef: $priceVersionRef !== null ? self::boundedRef($priceVersionRef, 'pv') : null,
                    taxSnapshotRef: $taxSnapshotRef !== null ? self::boundedRef($taxSnapshotRef, 'tax') : null,
                    dueAt: $record->eventBusinessDate,
                ));

                $billiumReceiptRef = is_string($ledgerReceipt->body()['receipt_ref'] ?? null)
                    ? $ledgerReceipt->body()['receipt_ref']
                    : 'obligation:'.$obligationRef;

                $boundMapping = $this->store->assignMappingTarget($identity, $revision, 'obligation', $obligationRef);
                $importReceipt = $this->store->findAppliedReceipt($identity, $revision);
                if ($importReceipt === null) {
                    $importReceipt = $this->store->recordReceipt(
                        batchPk: $batchPk,
                        receiptRef: self::boundedRef('imp-rcpt:obl:'.$boundMapping->sourceFactRef, 'imp-rcpt'),
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
                }
                $this->markStagedRecordAlreadyApplied($recordPk, $boundMapping->sourceFactRef);

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
                $this->markStagedRecordConflict($recordPk);

                return $this->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
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
        $this->markStagedRecordAlreadyApplied($recordPk, $committedMapping->sourceFactRef);

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
        $record = $this->hydrateInputFromStagedRow($row);
        $recordPk = self::intField($row, 'id');
        $identity = $record->identity;
        $revision = $record->sourceRecordRevision;
        $factRef = $record->sourceFactRef ?? $identity->stableSourceFactRef($revision, $this->codec);

        $existingMapping = $this->store->findMapping($identity, $revision);
        if ($existingMapping !== null) {
            if ($existingMapping->payloadDigest !== $record->payloadDigest || $existingMapping->status === ImportMappingStatus::Tombstoned) {
                $quarantined = true;
                $this->markStagedRecordConflict($recordPk);

                return $this->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
            }
            $applied = $this->store->findAppliedReceipt($identity, $revision);
            if ($applied !== null && $existingMapping->billiumTargetRef !== null) {
                $this->markStagedRecordAlreadyApplied($recordPk, $existingMapping->sourceFactRef);

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
        $originalIssuer = self::nonEmptyString($legal['originalIssuer'] ?? null)
            ?? self::nonEmptyString($record->originalBusinessRefs['originalIssuer'] ?? null);
        $sourceNumber = self::nonEmptyString($legal['sourceNumber'] ?? null)
            ?? self::nonEmptyString($record->originalBusinessRefs['sourceNumber'] ?? null);
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
            originalIssuer: self::boundedRef($originalIssuer, 'issuer'),
            originalSourceNumber: self::boundedRef($sourceNumber, 'src-num'),
            importPolicyVersion: $record->importPolicyVersion,
        );
        if (in_array($reg->outcome, [ImportMappingRegistrationResult::CONFLICT, ImportMappingRegistrationResult::TOMBSTONED_CONFLICT], true)) {
            $quarantined = true;
            $this->markStagedRecordConflict($recordPk);

            return $this->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
        }

        $historicalTargetRef = self::boundedRef('historical-invoice:'.$originalIssuer.':'.$sourceNumber, 'hist-inv');
        $bound = $this->store->assignMappingTarget($identity, $revision, 'historical_invoice', $historicalTargetRef);
        $applied = $this->store->findAppliedReceipt($identity, $revision);
        if ($applied === null) {
            $applied = $this->store->recordReceipt(
                batchPk: $batchPk,
                receiptRef: self::boundedRef('imp-rcpt:hist-inv:'.$bound->sourceFactRef, 'imp-rcpt'),
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
        }
        $this->markStagedRecordAlreadyApplied($recordPk, $bound->sourceFactRef);

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
     * @param  array<string, string>  $obligationRefBySourceId
     * @param  array<string, string>  $chargeOpRefByLookup
     */
    private function applyProvenAllocationRow(
        int $batchPk,
        object $row,
        Caller $caller,
        array $obligationRefBySourceId,
        array $chargeOpRefByLookup,
        int &$processedSteps,
        bool &$quarantined,
    ): ImportRecordApplyOutcome {
        $record = $this->hydrateInputFromStagedRow($row);
        $recordPk = self::intField($row, 'id');
        $identity = $record->identity;
        $revision = $record->sourceRecordRevision;
        $factRef = $record->sourceFactRef ?? $identity->stableSourceFactRef($revision, $this->codec);

        $existingMapping = $this->store->findMapping($identity, $revision);
        if ($existingMapping !== null) {
            if ($existingMapping->payloadDigest !== $record->payloadDigest || $existingMapping->status === ImportMappingStatus::Tombstoned) {
                $quarantined = true;
                $this->markStagedRecordConflict($recordPk);

                return $this->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
            }
            $applied = $this->store->findAppliedReceipt($identity, $revision);
            if ($applied !== null && $existingMapping->billiumTargetRef !== null) {
                $this->markStagedRecordAlreadyApplied($recordPk, $existingMapping->sourceFactRef);

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
        $billingAccountRef = self::nonEmptyString($origRefs['billingAccountRef'] ?? null);
        $paymentLookup = self::nonEmptyString($origRefs['paymentRecordId'] ?? null)
            ?? self::nonEmptyString($origRefs['paymentRef'] ?? null)
            ?? self::nonEmptyString($origRefs['originalOperationRef'] ?? null);
        $obligationLookup = self::nonEmptyString($origRefs['obligationRecordId'] ?? null)
            ?? self::nonEmptyString($origRefs['obligationRef'] ?? null);
        $currency = $record->money?->currency->code ?? self::nonEmptyString($record->payload['currency'] ?? null);

        if ($billingAccountRef === null || $paymentLookup === null || $obligationLookup === null || $currency === null) {
            // Do not fabricate missing account/intent/target proof: record as unanchored evidence.
            return $this->applyStage1Row($batchPk, $row, $processedSteps, $quarantined);
        }

        $targetObligationRef = $obligationRefBySourceId[$obligationLookup] ?? $obligationLookup;
        $chargeOpRef = $chargeOpRefByLookup[$paymentLookup] ?? $paymentLookup;
        $businessIntentRef = self::nonEmptyString($origRefs['settlementIntentRef'] ?? null)
            ?? self::boundedRef('imp-intent:'.$chargeOpRef, 'imp-int');

        $inspectedObl = ($this->monetaryPort)()->inspectObligation($targetObligationRef);
        if (! $inspectedObl['exists']) {
            // Target obligation is not proven in ledger: keep parked/unanchored without fabricating targets.
            return $this->applyStage1Row($batchPk, $row, $processedSteps, $quarantined);
        }

        $processedSteps++;
        $spec = new OperationSpec(
            commandKind: 'platform.import.settlement_intent.v1',
            sellerRef: $caller->sellerRef,
            environment: $caller->environment,
            mode: FinancialMode::Active,
            operationClass: ModuleOperationClass::NewIntent,
            modules: ['ledger', 'platform'],
            commandKey: self::boundedRef($factRef, 'imp-sint-key'),
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
            $targetObligationRef,
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
                commandKey: self::boundedRef($factRef, 'imp-sint-cmd'),
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                billingAccountRef: $billingAccountRef,
                businessIntentRef: $businessIntentRef,
                currency: $currency,
                obligationRefs: [$targetObligationRef],
                requestedMinor: $record->money?->minor,
            ));

            $billiumReceiptRef = is_string($intentReceipt->body()['receipt_ref'] ?? null)
                ? $intentReceipt->body()['receipt_ref']
                : 'intent:'.$businessIntentRef;
            $bound = $this->store->assignMappingTarget($identity, $revision, 'settlement_intent', $businessIntentRef);
            $importReceipt = $this->store->findAppliedReceipt($identity, $revision);
            if ($importReceipt === null) {
                $importReceipt = $this->store->recordReceipt(
                    batchPk: $batchPk,
                    receiptRef: self::boundedRef('imp-rcpt:sint:'.$bound->sourceFactRef, 'imp-rcpt'),
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
                        'obligationRefs' => [$targetObligationRef],
                    ],
                );
            }
            $this->markStagedRecordAlreadyApplied($recordPk, $bound->sourceFactRef);

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
                $this->markStagedRecordConflict($recordPk);

                return $this->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
            }
            throw new ProblemException($storedOutcome->problem, $storedOutcome->detail);
        }

        if ($captured !== null) {
            return $captured;
        }

        $committed = $this->store->findMapping($identity, $revision)
            ?? throw new LogicException('Replayed settlement intent without mapping');
        $committedReceipt = $this->store->findAppliedReceipt($identity, $revision);
        $this->markStagedRecordAlreadyApplied($recordPk, $committed->sourceFactRef);

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
     * Declares a settlement intent when a proven payment record explicitly carries both billingAccountRef and proven target obligation ref(s).
     * Never fabricates missing billingAccountRef or missing target obligations.
     *
     * @param  array<string, string>  $obligationRefBySourceId
     */
    private function ensureInlinePaymentSettlementIntent(
        object $paymentRow,
        Caller $caller,
        array $obligationRefBySourceId,
        int &$processedSteps,
    ): void {
        $record = $this->hydrateInputFromStagedRow($paymentRow);
        if ($record->money === null) {
            return;
        }
        $origRefs = $record->originalBusinessRefs;
        $billingAccountRef = self::nonEmptyString($origRefs['billingAccountRef'] ?? null);
        if ($billingAccountRef === null) {
            return;
        }
        $targetRefs = [];
        foreach (['targetObligationRef', 'obligationRef'] as $key) {
            $candidate = self::nonEmptyString($origRefs[$key] ?? null);
            if ($candidate !== null) {
                $resolved = $obligationRefBySourceId[$candidate] ?? $candidate;
                if (($this->monetaryPort)()->inspectObligation($resolved)['exists'] === true) {
                    $targetRefs[] = $resolved;
                }
            }
        }
        if (is_array($record->payload['settlementTargetObligationRefs'] ?? null)) {
            foreach ($record->payload['settlementTargetObligationRefs'] as $rawTarget) {
                if (is_string($rawTarget) && trim($rawTarget) !== '') {
                    $resolved = $obligationRefBySourceId[trim($rawTarget)] ?? trim($rawTarget);
                    if (($this->monetaryPort)()->inspectObligation($resolved)['exists'] === true) {
                        $targetRefs[] = $resolved;
                    }
                }
            }
        }
        $targetRefs = array_values(array_unique($targetRefs));
        if ($targetRefs === []) {
            return;
        }

        $businessIntentRef = $this->envelopeBuilder->businessIntentRef($record);
        $factRef = $record->sourceFactRef ?? $record->identity->stableSourceFactRef($record->sourceRecordRevision, $this->codec);
        $intentKey = self::boundedRef('imp-pay-sint:'.$factRef, 'imp-psint');
        $digest = $this->codec->digest([
            'billingAccountRef' => $billingAccountRef,
            'businessIntentRef' => $businessIntentRef,
            'currency' => $record->money->currency->code,
            'obligationRefs' => $targetRefs,
        ]);

        // Skip step increment if idempotency receipt already exists.
        $alreadyClaimed = $this->connection()->selectOne(
            'SELECT 1 FROM public.billium_idempotency_receipt WHERE environment = ? AND seller_ref = ? AND command_kind = ? AND command_key = ?',
            [$caller->environment, $caller->sellerRef, 'platform.import.inline_settlement_intent.v1', $intentKey],
        ) !== null;
        if ($alreadyClaimed) {
            return;
        }

        $processedSteps++;
        $spec = new OperationSpec(
            commandKind: 'platform.import.inline_settlement_intent.v1',
            sellerRef: $caller->sellerRef,
            environment: $caller->environment,
            mode: FinancialMode::Active,
            operationClass: ModuleOperationClass::NewIntent,
            modules: ['ledger', 'platform'],
            commandKey: $intentKey,
            digest: $digest,
            commitKind: 'platform.import.settlement_intent',
        );

        ($this->runner)()->run($spec, function (TransactionContext $context) use (
            $caller,
            $intentKey,
            $billingAccountRef,
            $businessIntentRef,
            $record,
            $targetRefs,
        ): Receipt {
            ($this->runner)()->assertCurrent($context, 'platform');
            $money = $record->money ?? throw new LogicException('Missing payment money');
            ($this->monetaryPort)()->declareSettlementIntent($context, new ImportSettlementIntentWrite(
                commandKey: $intentKey,
                sellerRef: $caller->sellerRef,
                environment: $caller->environment,
                billingAccountRef: $billingAccountRef,
                businessIntentRef: $businessIntentRef,
                currency: $money->currency->code,
                obligationRefs: $targetRefs,
                requestedMinor: $money->minor,
            ));

            return new PlatformReceipt('platform.import.settlement_intent', [
                'business_intent_ref' => $businessIntentRef,
            ]);
        });
    }

    /**
     * @param  array<string, string>  $chargeOpRefByLookup
     * @param  array<int, true>  $touchedPartitionIds
     */
    private function queueObservationRow(
        int $batchPk,
        object $row,
        Caller $caller,
        string $migrationSourceAuthority,
        array $chargeOpRefByLookup,
        array &$touchedPartitionIds,
        int &$processedSteps,
        bool &$quarantined,
    ): ImportRecordApplyOutcome {
        $record = $this->hydrateInputFromStagedRow($row);
        $recordPk = self::intField($row, 'id');
        $identity = $record->identity;
        $revision = $record->sourceRecordRevision;
        $factRef = $record->sourceFactRef ?? $identity->stableSourceFactRef($revision, $this->codec);

        $existingMapping = $this->store->findMapping($identity, $revision);
        if ($existingMapping !== null) {
            if ($existingMapping->payloadDigest !== $record->payloadDigest || $existingMapping->status === ImportMappingStatus::Tombstoned) {
                $quarantined = true;
                $this->markStagedRecordConflict($recordPk);

                return $this->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
            }
            $existingReceipt = $this->store->findAppliedReceipt($identity, $revision);
            if ($existingReceipt !== null) {
                if ($existingMapping->billiumTargetRef !== null) {
                    $this->markStagedRecordAlreadyApplied($recordPk, $existingMapping->sourceFactRef);

                    return new ImportRecordApplyOutcome(
                        identity: $identity,
                        sourceRecordRevision: $revision,
                        sourceFactRef: $existingMapping->sourceFactRef,
                        status: ImportRecordApplyStatus::AlreadyApplied,
                        mappingRef: $existingMapping->mappingRef,
                        importReceiptRef: $existingReceipt->receiptRef,
                        billiumTargetKind: $existingMapping->billiumTargetKind,
                        billiumTargetRef: $existingMapping->billiumTargetRef,
                    );
                }

                return new ImportRecordApplyOutcome(
                    identity: $identity,
                    sourceRecordRevision: $revision,
                    sourceFactRef: $existingMapping->sourceFactRef,
                    status: ImportRecordApplyStatus::ObservationQueued,
                    mappingRef: $existingMapping->mappingRef,
                    importReceiptRef: $existingReceipt->receiptRef,
                );
            }
        }

        // For proven refunds: verify original charge currency/capacity when already projected (IM-10).
        $resolvedOriginalPaymentRef = null;
        if ($identity->recordKind === ImportRecordKind::ProvenRefundEvidence) {
            $rawOrigRef = self::nonEmptyString($record->fundingRefs['originalOperationRef'] ?? null);
            if ($rawOrigRef === null || $record->money === null) {
                throw new LogicException('Unanchored refund reached observation queue stage');
            }
            $resolvedOriginalPaymentRef = $chargeOpRefByLookup[$rawOrigRef] ?? $rawOrigRef;
            $chargeCheck = ($this->monetaryPort)()->inspectOriginalChargeForRefund(
                $migrationSourceAuthority,
                $resolvedOriginalPaymentRef,
                $record->money,
            );
            if ($chargeCheck['exists']) {
                if (($chargeCheck['currency'] ?? '') !== $record->money->currency->code) {
                    $quarantined = true;
                    $this->markStagedRecordConflict($recordPk);

                    return $this->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
                }
                $hasExplicitDiscrepancy = is_array($record->fundingRefs['discrepancy'] ?? null);
                if (($chargeCheck['allowed'] ?? false) !== true && ! $hasExplicitDiscrepancy) {
                    $quarantined = true;
                    $this->markStagedRecordConflict($recordPk);

                    return $this->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
                }
                $chargeAccount = is_string($chargeCheck['providerAccountRef'] ?? null) ? $chargeCheck['providerAccountRef'] : '';
                $refundAccount = self::nonEmptyString($record->fundingRefs['providerAccountRef'] ?? null) ?? '';
                if ($chargeAccount !== '' && $refundAccount !== '' && $chargeAccount !== $refundAccount) {
                    $quarantined = true;
                    $this->markStagedRecordConflict($recordPk);

                    return $this->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
                }
            }
        }

        $processedSteps++;
        $scope = new FinancialScope(
            $caller->installationRef,
            $caller->sellerRef,
            $caller->environment,
            null,
            $migrationSourceAuthority,
        );
        $spec = new OperationSpec(
            commandKind: 'platform.import.queue_observation.v1',
            sellerRef: $caller->sellerRef,
            environment: $caller->environment,
            mode: FinancialMode::Active,
            operationClass: ModuleOperationClass::ObservedFactRecording,
            modules: ['platform'],
            commandKey: self::boundedRef($factRef, 'imp-obs-key'),
            digest: $record->payloadDigest,
            commitKind: 'platform.import.queue_observation',
            financialScope: $scope,
            inputDigest: $record->payloadDigest,
        );

        $noopCustodyHandler = new class implements CustodyHandler
        {
            public function handle(TransactionContext $context, InboxRow $row, stdClass $payload): HandlerOutcome
            {
                throw new LogicException('Import root Tx must never invoke ObservationApplier/CustodyHandler inline');
            }
        };

        /** @var ImportRecordApplyOutcome|null $captured */
        $captured = null;
        /** @var int|null $queuedPartitionId */
        $queuedPartitionId = null;

        try {
            $storedOutcome = ($this->runner)()->run($spec, function (TransactionContext $context) use (
                $batchPk,
                $recordPk,
                $record,
                $identity,
                $revision,
                $factRef,
                $migrationSourceAuthority,
                $resolvedOriginalPaymentRef,
                $noopCustodyHandler,
                &$captured,
                &$queuedPartitionId,
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
                    throw new ProblemException(ProblemCode::IdempotencyConflict, 'Observation mapping conflict');
                }

                $db = $this->partitions->connection($context);
                $partitionRef = $this->envelopeBuilder->partitionRef(
                    $identity->sourceInstallation,
                    $identity->sourceBook,
                    $identity->sellerRef,
                    $identity->environment,
                );
                $sourceLineage = $this->envelopeBuilder->sourceLineage(
                    $identity->installationRef,
                    $identity->sellerRef,
                    $identity->environment,
                    $identity->sourceInstallation,
                    $identity->sourceBook,
                );
                $eventId = $this->envelopeBuilder->deterministicEventId($reg->mapping->sourceFactRef);

                $this->partitions->lockNamespace(
                    $db,
                    'payment.observation',
                    $migrationSourceAuthority,
                    $identity->sellerRef,
                    $identity->environment,
                    $partitionRef,
                );
                $livePartition = $this->partitions->live(
                    $db,
                    'payment.observation',
                    $migrationSourceAuthority,
                    $identity->sellerRef,
                    $identity->environment,
                    $partitionRef,
                );
                if ($livePartition !== null) {
                    $livePartition = $this->partitions->lock($context, $livePartition);
                }

                $existingInbox = $db->selectOne(
                    'SELECT source_sequence, payload FROM public.billium_inbox_message '
                    .'WHERE channel = ? AND source_authority = ? AND source_lineage = ? '
                    .'AND seller_ref = ? AND environment = ? AND external_event_id = ?',
                    [
                        'payment.observation',
                        $migrationSourceAuthority,
                        $sourceLineage,
                        $identity->sellerRef,
                        $identity->environment,
                        $eventId,
                    ],
                );
                if (is_object($existingInbox)) {
                    $envelopeObj = json_decode(self::strField($existingInbox, 'payload'), false, 512, JSON_THROW_ON_ERROR);
                    if (! $envelopeObj instanceof stdClass) {
                        throw new LogicException('Corrupted stored inbox payload');
                    }
                    $sequence = self::intField($existingInbox, 'source_sequence');
                } else {
                    $maxInboxSeq = self::scalarInt($db->scalar(
                        'SELECT COALESCE(MAX(source_sequence), 0) FROM public.billium_inbox_message '
                        .'WHERE channel = ? AND source_authority = ? AND source_lineage = ? '
                        .'AND seller_ref = ? AND environment = ? AND partition_ref = ?',
                        [
                            'payment.observation',
                            $migrationSourceAuthority,
                            $sourceLineage,
                            $identity->sellerRef,
                            $identity->environment,
                            $partitionRef,
                        ],
                    ));
                    $sequence = max($livePartition === null ? 0 : $livePartition->custodySequence, $maxInboxSeq) + 1;
                    $envelopeObj = $this->envelopeBuilder->build(
                        record: $record,
                        sourceAuthority: $migrationSourceAuthority,
                        sourceFactRef: $reg->mapping->sourceFactRef,
                        sourceSequence: $sequence,
                        resolvedOriginalPaymentRef: $resolvedOriginalPaymentRef,
                    );
                }

                $custodyResult = $this->custody->enqueueCustody(
                    $context,
                    'payment.observation',
                    $envelopeObj,
                    $noopCustodyHandler,
                );
                if ($custodyResult->disposition === CustodyDisposition::Conflict) {
                    throw new ProblemException(ProblemCode::ObservationConflict, 'Conflicting observation delivery in custody');
                }
                $queuedPartitionId = $custodyResult->drainPartitionId;

                $operationRef = $this->envelopeBuilder->operationRef($record);
                $commandPersistence = new CommandPersistence($this->databases);
                $commandPersistence->record($context, new PreparedInternalCommand(
                    commandRef: self::boundedRef('imp-obs-cmd:'.$reg->mapping->sourceFactRef, 'imp-ocmd'),
                    businessRef: $operationRef,
                    ownerModuleId: 'platform',
                    commandType: 'import.observation.drain',
                    commandSchema: 'billium.command.v1',
                    payload: [
                        'channel' => 'payment.observation',
                        'eventId' => $eventId,
                        'operationRef' => $operationRef,
                        'partitionRef' => $partitionRef,
                        'sourceAuthority' => $migrationSourceAuthority,
                        'sourceFactRef' => $reg->mapping->sourceFactRef,
                        'sourceLineage' => $sourceLineage,
                        'sourceSequence' => $sequence,
                    ],
                    destinations: ['ledger.inbox.payment_observation'],
                ));

                $importReceipt = $this->store->findAppliedReceipt($identity, $revision);
                if ($importReceipt === null) {
                    $importReceipt = $this->store->recordReceipt(
                        batchPk: $batchPk,
                        receiptRef: self::boundedRef('imp-rcpt:obs:'.$reg->mapping->sourceFactRef, 'imp-rcpt'),
                        identity: $identity,
                        sourceRecordRevision: $revision,
                        payloadDigest: $record->payloadDigest,
                        status: ImportReceiptStatus::ObservationQueued,
                        recordPk: $recordPk,
                        mappingPk: $reg->mapping->id,
                        sourceFactRef: $reg->mapping->sourceFactRef,
                        billiumReceiptRef: null,
                        details: [
                            'channel' => 'payment.observation',
                            'eventId' => $eventId,
                            'inboxId' => $custodyResult->inboxId,
                            'operationKind' => $identity->recordKind === ImportRecordKind::ProvenRefundEvidence ? 'refund' : 'charge',
                            'operationRef' => $operationRef,
                            'operationRevision' => max(1, $revision),
                            'partitionRef' => $partitionRef,
                            'sourceAuthority' => $migrationSourceAuthority,
                            'sourceSequence' => $sequence,
                        ],
                    );
                }

                $captured = new ImportRecordApplyOutcome(
                    identity: $identity,
                    sourceRecordRevision: $revision,
                    sourceFactRef: $reg->mapping->sourceFactRef,
                    status: ImportRecordApplyStatus::ObservationQueued,
                    mappingRef: $reg->mapping->mappingRef,
                    importReceiptRef: $importReceipt->receiptRef,
                );

                return new PlatformReceipt('platform.import.queue_observation', [
                    'event_id' => $eventId,
                    'inbox_id' => $custodyResult->inboxId,
                    'mapping_ref' => $reg->mapping->mappingRef,
                    'operation_ref' => $operationRef,
                    'partition_ref' => $partitionRef,
                    'status' => ImportReceiptStatus::ObservationQueued->value,
                ]);
            });
            if ($storedOutcome->problem !== null) {
                throw new ProblemException($storedOutcome->problem, $storedOutcome->detail);
            }
        } catch (ProblemException $problem) {
            if (in_array($problem->problem, [ProblemCode::IdempotencyConflict, ProblemCode::ObservationConflict], true)) {
                $quarantined = true;
                $this->markStagedRecordConflict($recordPk);

                return $this->recordTerminalValidationReceipt($batchPk, $row, ImportReceiptStatus::Conflict, ImportRecordApplyStatus::Conflict);
            }
            throw $problem;
        }

        if ($queuedPartitionId !== null) {
            $touchedPartitionIds[$queuedPartitionId] = true;
        }
        if ($captured !== null) {
            return $captured;
        }

        $committedMapping = $this->store->findMapping($identity, $revision)
            ?? throw new LogicException('Replayed observation queue without committed mapping');
        $committedReceipt = $this->store->findAppliedReceipt($identity, $revision);
        if ($committedMapping->billiumTargetRef !== null) {
            $this->markStagedRecordAlreadyApplied($recordPk, $committedMapping->sourceFactRef);

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

        return new ImportRecordApplyOutcome(
            identity: $identity,
            sourceRecordRevision: $revision,
            sourceFactRef: $committedMapping->sourceFactRef,
            status: ImportRecordApplyStatus::ObservationQueued,
            mappingRef: $committedMapping->mappingRef,
            importReceiptRef: $committedReceipt?->receiptRef,
        );
    }

    /**
     * Pure-query receipt adoption: recovers or binds the observation owner's AllocationReceipt without creating a new economic entity.
     */
    private function adoptObservationRow(
        object $row,
        string $migrationSourceAuthority,
        int &$processedSteps,
        bool &$quarantined,
    ): ?ImportRecordApplyOutcome {
        $record = $this->hydrateInputFromStagedRow($row);
        $recordPk = self::intField($row, 'id');
        $identity = $record->identity;
        $revision = $record->sourceRecordRevision;

        $mapping = $this->store->findMapping($identity, $revision);
        if ($mapping === null) {
            return null;
        }
        $queuedReceipt = $this->store->findAppliedReceipt($identity, $revision);
        if ($mapping->billiumTargetRef !== null) {
            $this->markStagedRecordAlreadyApplied($recordPk, $mapping->sourceFactRef);

            return new ImportRecordApplyOutcome(
                identity: $identity,
                sourceRecordRevision: $revision,
                sourceFactRef: $mapping->sourceFactRef,
                status: ImportRecordApplyStatus::AlreadyApplied,
                mappingRef: $mapping->mappingRef,
                importReceiptRef: $queuedReceipt?->receiptRef,
                billiumTargetKind: $mapping->billiumTargetKind,
                billiumTargetRef: $mapping->billiumTargetRef,
            );
        }

        $operationKind = $identity->recordKind === ImportRecordKind::ProvenRefundEvidence ? 'refund' : 'charge';
        $operationRef = $this->envelopeBuilder->operationRef($record);
        $obsOutcome = ($this->monetaryPort)()->findObservationOutcome(
            $migrationSourceAuthority,
            $operationRef,
            $operationKind,
            max(1, $revision),
        );

        if ($obsOutcome->status === ImportObservationOutcome::STATUS_APPLIED && $obsOutcome->receiptRef !== null) {
            $processedSteps++;
            $targetKind = $obsOutcome->receiptKind ?? 'allocation_receipt';
            $bound = $this->store->assignMappingTarget($identity, $revision, $targetKind, $obsOutcome->receiptRef);
            $this->markStagedRecordAlreadyApplied($recordPk, $bound->sourceFactRef);

            return new ImportRecordApplyOutcome(
                identity: $identity,
                sourceRecordRevision: $revision,
                sourceFactRef: $bound->sourceFactRef,
                status: ImportRecordApplyStatus::Applied,
                mappingRef: $bound->mappingRef,
                importReceiptRef: $queuedReceipt?->receiptRef,
                billiumTargetKind: $bound->billiumTargetKind,
                billiumTargetRef: $bound->billiumTargetRef,
            );
        }

        if ($obsOutcome->status === ImportObservationOutcome::STATUS_HELD_DISCREPANCY) {
            $quarantined = true;
            if ($obsOutcome->receiptRef !== null) {
                $this->store->assignMappingTarget($identity, $revision, $obsOutcome->receiptKind ?? 'held_discrepancy', $obsOutcome->receiptRef);
            }

            return new ImportRecordApplyOutcome(
                identity: $identity,
                sourceRecordRevision: $revision,
                sourceFactRef: $mapping->sourceFactRef,
                status: ImportRecordApplyStatus::Conflict,
                mappingRef: $mapping->mappingRef,
                importReceiptRef: $queuedReceipt?->receiptRef,
                billiumTargetKind: $obsOutcome->receiptKind,
                billiumTargetRef: $obsOutcome->receiptRef,
                detail: 'observation_held_discrepancy',
            );
        }

        if ($obsOutcome->status === ImportObservationOutcome::STATUS_PARKED) {
            return new ImportRecordApplyOutcome(
                identity: $identity,
                sourceRecordRevision: $revision,
                sourceFactRef: $mapping->sourceFactRef,
                status: ImportRecordApplyStatus::ObservationParked,
                mappingRef: $mapping->mappingRef,
                importReceiptRef: $queuedReceipt?->receiptRef,
                detail: 'parked:'.($obsOutcome->projectionStatus ?? 'dependency'),
            );
        }

        return null;
    }

    private function countRemainingWork(int $batchPk, ImportApplyOptions $options): int
    {
        $rows = self::objectRows($this->connection()->select('SELECT * FROM public.billium_import_record WHERE batch_pk = ? ORDER BY id ASC', [$batchPk]));
        $remaining = 0;
        foreach ($rows as $row) {
            $valStatus = self::strField($row, 'validation_status');
            if (in_array($valStatus, ['already_applied', 'rejected', 'conflict'], true)) {
                continue;
            }
            $record = $this->hydrateInputFromStagedRow($row);
            $recordPk = self::intField($row, 'id');
            $identity = $record->identity;
            $revision = $record->sourceRecordRevision;

            if ($valStatus === ImportValidationStatus::UnanchoredEvidence->value || $identity->recordKind === ImportRecordKind::UnanchoredEvidence) {
                if ($this->findBatchRecordReceipt($batchPk, $recordPk, ImportReceiptStatus::UnanchoredEvidence) === null) {
                    $remaining++;
                }

                continue;
            }

            $mapping = $this->store->findMapping($identity, $revision);
            if ($mapping === null) {
                $remaining++;

                continue;
            }
            if (in_array($identity->recordKind, [ImportRecordKind::ProvenPaymentEvidence, ImportRecordKind::ProvenRefundEvidence], true)) {
                $receipt = $this->store->findAppliedReceipt($identity, $revision);
                if ($receipt === null) {
                    $remaining++;
                } elseif ($mapping->billiumTargetRef === null && $options->adoptReceipts) {
                    $opKind = $identity->recordKind === ImportRecordKind::ProvenRefundEvidence ? 'refund' : 'charge';
                    $opRef = $this->envelopeBuilder->operationRef($record);
                    $obs = ($this->monetaryPort)()->findObservationOutcome($options->migrationSourceAuthority, $opRef, $opKind, max(1, $revision));
                    if ($obs->status === ImportObservationOutcome::STATUS_APPLIED || $obs->status === ImportObservationOutcome::STATUS_ABSENT) {
                        $remaining++;
                    }
                }
            } elseif ($mapping->billiumTargetRef === null) {
                $remaining++;
            }
        }

        return $remaining;
    }

    /**
     * @param  list<object>  $obligationRows
     * @return array<string, string>
     */
    private function collectKnownObligationRefs(array $obligationRows): array
    {
        $map = [];
        foreach ($obligationRows as $row) {
            $record = $this->hydrateInputFromStagedRow($row);
            $oblRef = self::boundedRef(
                self::nonEmptyString($record->originalBusinessRefs['obligationRef'] ?? null) ?? ('imp-obl:'.$record->identity->sourceRecordId),
                'imp-obl',
            );
            $map[$record->identity->sourceRecordId] = $oblRef;
            $map[$oblRef] = $oblRef;
        }

        return $map;
    }

    /**
     * @param  list<object>  $paymentRows
     * @return array<string, string>
     */
    private function collectKnownChargeOperationRefs(array $paymentRows): array
    {
        $map = [];
        foreach ($paymentRows as $row) {
            $record = $this->hydrateInputFromStagedRow($row);
            $opRef = $this->envelopeBuilder->operationRef($record);
            $map[$record->identity->sourceRecordId] = $opRef;
            $map[$opRef] = $opRef;
            $payRef = self::nonEmptyString($record->originalBusinessRefs['paymentRef'] ?? null);
            if ($payRef !== null) {
                $map[$payRef] = $opRef;
            }
            $pspRef = self::nonEmptyString($record->fundingRefs['providerOperationRef'] ?? null);
            if ($pspRef !== null) {
                $map[$pspRef] = $opRef;
            }
        }

        return $map;
    }

    private function recordTerminalValidationReceipt(
        int $batchPk,
        object $row,
        ImportReceiptStatus $receiptStatus,
        ImportRecordApplyStatus $applyStatus,
    ): ImportRecordApplyOutcome {
        $record = $this->hydrateInputFromStagedRow($row);
        $recordPk = self::intField($row, 'id');
        $factRef = $record->sourceFactRef ?? $record->identity->stableSourceFactRef($record->sourceRecordRevision, $this->codec);
        $receiptRef = self::boundedRef('imp-rcpt:'.$receiptStatus->value.':'.$batchPk.':'.$recordPk, 'imp-rcpt');

        $existingRef = $this->findBatchRecordReceipt($batchPk, $recordPk, $receiptStatus);
        if ($existingRef === null) {
            $this->store->recordReceipt(
                batchPk: $batchPk,
                receiptRef: $receiptRef,
                identity: $record->identity,
                sourceRecordRevision: $record->sourceRecordRevision,
                payloadDigest: $record->payloadDigest,
                status: $receiptStatus,
                recordPk: $recordPk,
                sourceFactRef: $factRef,
                details: [
                    'rejectionCode' => self::optStrField($row, 'rejection_code'),
                ],
            );
        } else {
            $receiptRef = $existingRef;
        }

        return new ImportRecordApplyOutcome(
            identity: $record->identity,
            sourceRecordRevision: $record->sourceRecordRevision,
            sourceFactRef: $factRef,
            status: $applyStatus,
            importReceiptRef: $receiptRef,
            detail: self::optStrField($row, 'rejection_code'),
        );
    }

    private function findBatchRecordReceipt(int $batchPk, int $recordPk, ImportReceiptStatus $status): ?string
    {
        $row = $this->connection()->selectOne(
            'SELECT receipt_ref FROM public.billium_import_receipt WHERE batch_pk = ? AND record_pk = ? AND status = ? LIMIT 1',
            [$batchPk, $recordPk, $status->value],
        );

        return is_object($row) ? self::strField($row, 'receipt_ref') : null;
    }

    private function markStagedRecordAlreadyApplied(int $recordPk, string $sourceFactRef): void
    {
        $this->connection()->update(
            "UPDATE public.billium_import_record SET validation_status = 'already_applied', source_fact_ref = ? WHERE id = ?",
            [$sourceFactRef, $recordPk],
        );
    }

    private function markStagedRecordConflict(int $recordPk): void
    {
        $this->connection()->update(
            "UPDATE public.billium_import_record SET validation_status = 'conflict' WHERE id = ?",
            [$recordPk],
        );
    }

    private function hydrateInputFromStagedRow(object $row): ImportRecordInput
    {
        $payload = self::decodeArray(self::strField($row, 'payload'));
        $currency = self::optStrField($row, 'currency');
        $amountMinor = self::optIntField($row, 'amount_minor');
        $money = $currency !== null && $amountMinor !== null ? new Money($amountMinor, Currency::of($currency)) : null;

        /** @var array<string, string> $origRefs */
        $origRefs = [];
        if (is_array($payload['originalBusinessRefs'] ?? null)) {
            foreach ($payload['originalBusinessRefs'] as $k => $v) {
                if (is_string($v)) {
                    $origRefs[(string) $k] = $v;
                }
            }
        }

        $evidenceRefs = [];
        if (is_array($payload['evidenceRefs'] ?? null)) {
            foreach ($payload['evidenceRefs'] as $ev) {
                if (is_array($ev) && is_string($ev['evidenceRef'] ?? null) && is_string($ev['kind'] ?? null) && is_string($ev['digest'] ?? null)) {
                    $evidenceRefs[] = new ImportEvidenceRef(
                        evidenceRef: $ev['evidenceRef'],
                        kind: $ev['kind'],
                        digest: $ev['digest'],
                        sanitized: ($ev['sanitized'] ?? true) === true,
                    );
                }
            }
        }

        $missingEvidence = [];
        foreach (self::decodeList(self::strField($row, 'missing_evidence')) as $item) {
            if (is_string($item)) {
                $missingEvidence[] = $item;
            }
        }

        $batchRow = $this->connection()->selectOne(
            'SELECT batch_id, source_export_revision, export_cutoff, manifest_digest FROM public.billium_import_batch WHERE id = ?',
            [self::intField($row, 'batch_pk')],
        ) ?? throw new LogicException('Staged record batch row missing');

        return new ImportRecordInput(
            identity: new ImportSourceIdentity(
                installationRef: self::strField($row, 'installation_ref'),
                environment: self::strField($row, 'environment'),
                sellerRef: self::strField($row, 'seller_ref'),
                sourceSystem: self::strField($row, 'source_system'),
                sourceInstallation: self::strField($row, 'source_installation'),
                sourceBook: self::strField($row, 'source_book'),
                sourceRecordId: self::strField($row, 'source_record_id'),
                recordKind: ImportRecordKind::from(self::strField($row, 'record_kind')),
            ),
            batchId: self::strField($batchRow, 'batch_id'),
            sourceExportRevision: self::intField($batchRow, 'source_export_revision'),
            exportCutoff: new DateTimeImmutable(self::strField($batchRow, 'export_cutoff')),
            manifestDigest: self::strField($batchRow, 'manifest_digest'),
            sourceRecordRevision: self::intField($row, 'source_record_revision'),
            eventBusinessDate: new DateTimeImmutable(self::strField($row, 'event_business_date')),
            payloadDigest: self::strField($row, 'payload_digest'),
            originalBusinessRefs: $origRefs,
            evidenceRefs: $evidenceRefs,
            money: $money,
            frozenPriceSnapshot: is_array($payload['frozenPriceSnapshot'] ?? null) ? self::stringKeyed($payload['frozenPriceSnapshot']) : null,
            fundingRefs: is_array($payload['fundingRefs'] ?? null) ? self::stringKeyed($payload['fundingRefs']) : null,
            legalDocument: is_array($payload['legalDocument'] ?? null) ? self::stringKeyed($payload['legalDocument']) : null,
            importPolicyVersion: self::optStrField($row, 'import_policy_version'),
            sourceFactRef: self::optStrField($row, 'source_fact_ref'),
            missingEvidence: $missingEvidence,
            payload: $payload,
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
        return $this->databases->connection($this->options->connection);
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function decodeArray(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? self::stringKeyed($decoded) : [];
    }

    /** @return list<mixed> */
    private static function decodeList(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' || str_contains($trimmed, "\0") ? null : $trimmed;
    }

    private static function boundedRef(string $value, string $prefix): string
    {
        if (strlen($value) <= 200) {
            return $value;
        }

        return $prefix.':'.hash('sha256', $value);
    }

    private static function strField(mixed $row, string $column): string
    {
        $value = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;
        if (! is_string($value)) {
            throw new LogicException("Expected string column {$column}");
        }

        return $value;
    }

    private static function optStrField(mixed $row, string $column): ?string
    {
        $value = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new LogicException("Expected nullable string column {$column}");
        }

        return $value;
    }

    private static function intField(mixed $row, string $column): int
    {
        $value = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        throw new LogicException("Expected integer column {$column}");
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<object>
     */
    private static function objectRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_object($row)) {
                throw new LogicException('Expected stdClass row from database select');
            }
            $out[] = $row;
        }

        return $out;
    }

    private static function scalarInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }

    private static function optIntField(mixed $row, string $column): ?int
    {
        $value = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;
        if ($value === null) {
            return null;
        }

        return self::intField($row, $column);
    }
}
