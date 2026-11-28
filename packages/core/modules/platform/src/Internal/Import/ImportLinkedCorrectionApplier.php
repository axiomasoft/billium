<?php

declare(strict_types=1);

namespace Billium\Platform\Internal\Import;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Contracts\MoneyMath;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Platform\Contracts\Import\ImportIdentityStore;
use Billium\Platform\Contracts\Import\ImportMonetaryPort;
use Billium\Platform\Data\Import\ImportCorrectionResult;
use Billium\Platform\Data\Import\ImportCreditNoteWrite;
use Billium\Platform\Data\Import\ImportLinkedCorrection;
use Billium\Platform\Data\Import\ImportMappingStatus;
use Billium\Platform\Data\Import\ImportObligationWrite;
use Billium\Platform\Data\Installation\PlatformReceipt;
use Closure;
use LogicException;
use Packman\Attributes\Internal;

/**
 * Post-apply linked corrections over imported records using positive obligations, credit notes, draft adjustments, or tombstones.
 */
#[Internal]
final readonly class ImportLinkedCorrectionApplier
{
    /**
     * @param  Closure(): OperationRunner  $runner
     * @param  Closure(): ImportMonetaryPort  $monetaryPort
     */
    public function __construct(
        private ImportIdentityStore $store,
        private Closure $runner,
        private Closure $monetaryPort,
        private Codec $codec,
        private MoneyMath $math,
    ) {}

    public function apply(ImportLinkedCorrection $correction, Caller $caller): ImportCorrectionResult
    {
        $identity = $correction->originalIdentity;
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

        $normalizedWeights = array_map(
            static fn (array $w): array => [
                'componentRef' => $w['componentRef'],
                'weightMinor' => $w['weightMinor'],
            ],
            $correction->componentWeights,
        );
        $correctionFactRef = $identity->stableSourceFactRef($correction->newRevision, $this->codec);
        $correctionPayloadDigest = $this->codec->digest([
            'billingAccountRef' => $correction->billingAccountRef,
            'componentWeights' => $normalizedWeights,
            'correctionRef' => $correction->correctionRef,
            'creditNoteMode' => $correction->creditNoteMode,
            'customerRef' => $correction->customerRef,
            'deltaMoney' => $correction->deltaMoney->toWire(),
            'invoiceRef' => $correction->invoiceRef,
            'kind' => $correction->kind,
            'newRevision' => $correction->newRevision,
            'originalRevision' => $correction->originalRevision,
            'originalSourceFactRef' => $existingMapping->sourceFactRef,
            'priceVersionRef' => $correction->priceVersionRef,
            'reason' => $correction->reason,
            'taxSnapshotRef' => $correction->taxSnapshotRef,
            'termsDigest' => $correction->termsDigest,
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

        if ($existingMapping->billiumTargetKind !== 'obligation') {
            for ($rev = $correction->originalRevision - 1; $rev >= 1; $rev--) {
                $ancestor = $this->store->findMapping($identity, $rev);
                if ($ancestor !== null && $ancestor->billiumTargetKind === 'obligation' && is_string($ancestor->billiumTargetRef) && $ancestor->billiumTargetRef !== '') {
                    $originalTargetRef = $ancestor->billiumTargetRef;
                    break;
                }
            }
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
                        $newOblRef = ImportStagedRecordHydrator::boundedRef('imp-corr-obl:'.$correction->correctionRef.':'.$comp['componentRef'], 'imp-cobl');
                        $monetary->recordObligation($context, new ImportObligationWrite(
                            commandKey: ImportStagedRecordHydrator::boundedRef('imp-corr-cmd:'.$compCorrectionRef, 'imp-ccmd'),
                            sellerRef: $caller->sellerRef,
                            environment: $caller->environment,
                            billingAccountRef: $billingAccountRef,
                            obligationRef: $newOblRef,
                            basisRef: ImportStagedRecordHydrator::boundedRef($derivedBasis, 'corr-basis'),
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
                    $targetRef = ImportStagedRecordHydrator::boundedRef('imp-corr-obl:'.$correction->correctionRef, 'imp-cobl');
                    $componentRefVal = $inspected['componentRef'] ?? 'imported-component';
                    $receipt = $monetary->recordObligation($context, new ImportObligationWrite(
                        commandKey: ImportStagedRecordHydrator::boundedRef('imp-corr-cmd:'.$correction->correctionRef, 'imp-ccmd'),
                        sellerRef: $caller->sellerRef,
                        environment: $caller->environment,
                        billingAccountRef: $billingAccountRef,
                        obligationRef: $targetRef,
                        basisRef: ImportStagedRecordHydrator::boundedRef($derivedBasis, 'corr-basis'),
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
                $creditNoteRef = ImportStagedRecordHydrator::boundedRef('imp-cn:'.$correction->correctionRef, 'imp-cn');
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
                    commandKey: ImportStagedRecordHydrator::boundedRef('imp-cn-cmd:'.$correction->correctionRef, 'imp-cncmd'),
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
            $adjustmentRef = ImportStagedRecordHydrator::boundedRef('imp-adj:'.$correction->correctionRef, 'imp-adj');
            $receipt = $monetary->adjustDraftObligation(
                context: $context,
                commandKey: ImportStagedRecordHydrator::boundedRef('imp-adj-cmd:'.$correction->correctionRef, 'imp-adjcmd'),
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
}
