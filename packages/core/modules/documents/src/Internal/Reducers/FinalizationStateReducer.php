<?php

declare(strict_types=1);

namespace Billium\Documents\Internal\Reducers;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Documents\Data\AttachTaxSnapshot;
use Billium\Documents\Data\DocumentFinalizationView;
use Billium\Documents\Data\DocumentTaxSnapshot;
use Billium\Documents\Enums\FinalizationState;
use Billium\Documents\Enums\TaxConvention;
use Packman\Attributes\Internal;

#[Internal]
final readonly class FinalizationStateReducer
{
    public function __construct(
        private DocumentSnapshotAssembler $assembler = new DocumentSnapshotAssembler,
    ) {}

    public function assertDraftIdempotentOrThrow(string $existingInputDigest, string $expectedInputDigest): void
    {
        if ($existingInputDigest !== $expectedInputDigest) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.finalization_conflict');
        }
    }

    /**
     * Returns true if CAS Draft -> Finalizing should be executed, false if already Finalizing (idempotent).
     *
     * @throws ProblemException billium.invoice.frozen when already SnapshotReady
     * @throws ProblemException billium.validation.invalid when in Superseded state
     */
    public function decideStartFinalization(FinalizationState $currentState): bool
    {
        return match ($currentState) {
            FinalizationState::SnapshotReady => throw new ProblemException(
                ProblemCode::InvoiceFrozen,
                'billium.documents.finalization_already_ready',
            ),
            FinalizationState::Finalizing => false,
            FinalizationState::Draft => true,
            FinalizationState::Superseded => throw new ProblemException(
                ProblemCode::ValidationInvalid,
                'billium.documents.finalization_state_conflict',
            ),
        };
    }

    /**
     * @throws ProblemException billium.invoice.frozen when already SnapshotReady
     */
    public function assertRevisable(FinalizationState $currentState): void
    {
        if ($currentState === FinalizationState::SnapshotReady) {
            throw new ProblemException(ProblemCode::InvoiceFrozen, 'billium.documents.finalization_frozen');
        }
    }

    public function decideAttachTaxSnapshot(
        DocumentFinalizationView $target,
        TaxConvention $targetConvention,
        int $latestId,
        int $latestRevision,
        string $latestInputDigest,
        AttachTaxSnapshot $command,
        string $taxSnapshotDigest,
    ): AttachSnapshotDecision {
        // Idempotent retry of identical snapshot on an already snapshot_ready revision.
        if (
            $target->state === FinalizationState::SnapshotReady
            && $target->inputDigest === $command->inputDigest
            && $command->snapshot->inputDigest === $command->inputDigest
            && $target->taxSnapshotDigest === $taxSnapshotDigest
        ) {
            return new AttachSnapshotDecision(
                action: AttachSnapshotDecision::ACTION_IDEMPOTENT,
                viewId: $target->id,
                approvedSnapshotDigest: null,
                staleDetails: null,
            );
        }

        // Detect superseded / stale / digest-mismatched late result before snapshot conflict validation.
        $isStale = $target->state === FinalizationState::Superseded
            || $latestRevision !== $command->finalizationRevision
            || $target->inputDigest !== $command->inputDigest
            || $command->snapshot->inputDigest !== $command->inputDigest;

        if ($isStale) {
            return new AttachSnapshotDecision(
                action: AttachSnapshotDecision::ACTION_STALE,
                viewId: $latestId,
                approvedSnapshotDigest: null,
                staleDetails: [
                    'expectedInputDigest' => $latestInputDigest,
                    'latestRevision' => $latestRevision,
                    'taxSnapshotRef' => $command->snapshot->taxSnapshotRef,
                ],
            );
        }

        if ($target->state !== FinalizationState::Finalizing) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.finalization_not_finalizing');
        }

        $this->validateSnapshotAgainstDraft($target, $targetConvention, $command->snapshot);

        $approvedSnapshotDigest = $this->assembler->computeApprovedSnapshotDigest(
            finalizationRef: $command->finalizationRef,
            finalizationRevision: $command->finalizationRevision,
            basisRevision: $target->basisRevision,
            basisDigest: $target->basisDigest,
            inputDigest: $target->inputDigest,
            taxSnapshotDigest: $taxSnapshotDigest,
            profileId: $target->profileId,
            profileVersion: $target->profileVersion,
            snapshot: $command->snapshot,
        );

        return new AttachSnapshotDecision(
            action: AttachSnapshotDecision::ACTION_ATTACH,
            viewId: $target->id,
            approvedSnapshotDigest: $approvedSnapshotDigest,
            staleDetails: null,
        );
    }

    public function validateSnapshotAgainstDraft(
        DocumentFinalizationView $target,
        TaxConvention $draftConvention,
        DocumentTaxSnapshot $snapshot,
    ): void {
        if ($snapshot->currency !== $target->currency || $snapshot->currencyExponent !== $target->currencyExponent) {
            throw new ProblemException(ProblemCode::MoneyCurrencyMismatch, 'billium.documents.tax_snapshot_currency_conflict');
        }
        if ($snapshot->convention !== $draftConvention) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.tax_snapshot_convention_conflict');
        }
        if ($draftConvention === TaxConvention::NoneDeclared && ($snapshot->taxMinor !== 0 || $snapshot->noTaxReason === null || trim($snapshot->noTaxReason) === '')) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.none_declared_requires_zero_tax_and_reason');
        }
        if ($snapshot->subtotalMinor + $snapshot->taxMinor !== $snapshot->totalMinor) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.tax_snapshot_total_conflict');
        }

        $draftByLine = [];
        $draftNetSum = 0;
        foreach ($target->lines as $line) {
            $draftByLine[$line->lineRef] = $line;
            $draftNetSum += $line->netMinor;
        }

        if (count($snapshot->lines) !== count($draftByLine)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.tax_snapshot_line_count_conflict');
        }

        $sumNet = 0;
        $sumTax = 0;
        $sumGross = 0;
        $seen = [];
        foreach ($snapshot->lines as $snapLine) {
            if (isset($seen[$snapLine->lineRef]) || ! isset($draftByLine[$snapLine->lineRef])) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.tax_snapshot_line_ref_conflict');
            }
            $seen[$snapLine->lineRef] = true;
            if ($snapLine->netMinor + $snapLine->taxMinor !== $snapLine->grossMinor || $snapLine->netMinor < 0 || $snapLine->taxMinor < 0) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.tax_snapshot_line_math_conflict');
            }
            if ($draftConvention !== TaxConvention::Inclusive && $snapLine->netMinor !== $draftByLine[$snapLine->lineRef]->netMinor) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.tax_snapshot_line_amount_conflict');
            }
            if ($draftConvention === TaxConvention::Inclusive && $snapLine->grossMinor !== $draftByLine[$snapLine->lineRef]->netMinor && $snapLine->netMinor !== $draftByLine[$snapLine->lineRef]->netMinor) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.tax_snapshot_inclusive_amount_conflict');
            }
            $sumNet += $snapLine->netMinor;
            $sumTax += $snapLine->taxMinor;
            $sumGross += $snapLine->grossMinor;
        }

        if ($sumNet !== $snapshot->subtotalMinor || $sumTax !== $snapshot->taxMinor || $sumGross !== $snapshot->totalMinor) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.tax_snapshot_sum_conflict');
        }
        if ($draftConvention !== TaxConvention::Inclusive && $snapshot->subtotalMinor !== $draftNetSum) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.tax_snapshot_subtotal_conflict');
        }
    }
}
