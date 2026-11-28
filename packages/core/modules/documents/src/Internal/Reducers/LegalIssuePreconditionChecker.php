<?php

declare(strict_types=1);

namespace Billium\Documents\Internal\Reducers;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Documents\Data\DocumentFinalizationView;
use Billium\Documents\Data\DocumentProfile;
use Billium\Documents\Data\IssueLegalDocument;
use Billium\Documents\Data\LegalDocumentView;
use Billium\Documents\Data\UpdateLegalDocumentDisplayMetadata;
use Billium\Documents\Data\VoidLegalDocument;
use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\FinalizationState;
use Billium\Documents\Enums\LegalDocumentStatus;
use Billium\Documents\Internal\Canonical\CanonicalJson;
use Billium\Documents\Internal\Support\RowCast;
use Packman\Attributes\Internal;

#[Internal]
final readonly class LegalIssuePreconditionChecker
{
    /** @var list<string> */
    private const array ALLOWED_DISPLAY_METADATA_KEYS = [
        'displayLabel',
        'deliveryNote',
        'locale',
        'customNotes',
        'externalReference',
    ];

    public function deterministicLegalDocumentRef(
        string $sellerRef,
        string $environment,
        DocumentType $type,
        string $economicRef,
        int $revision,
    ): string {
        return 'ldoc-'.substr(hash('sha256', CanonicalJson::encode([
            $sellerRef,
            $environment,
            $type->value,
            $economicRef,
            $revision,
        ])), 0, 24);
    }

    public function computeIssueDigest(IssueLegalDocument $command, string $legalDocumentRef): string
    {
        return CanonicalJson::digest([
            'scope' => [
                'sellerRef' => $command->scope->sellerRef,
                'environment' => $command->scope->environment,
                'billingAccountRef' => $command->scope->billingAccountRef,
            ],
            'invoiceRef' => $command->invoiceRef,
            'economicRef' => $command->economicRef,
            'documentRevision' => $command->documentRevision,
            'finalizationRef' => $command->finalizationRef,
            'approvedSnapshotDigest' => $command->approvedSnapshotDigest,
            'seriesRef' => $command->seriesRef,
            'periodKey' => $command->periodKey,
            'displayMetadata' => $command->displayMetadata,
            'legalDocumentRef' => $legalDocumentRef,
        ]);
    }

    public function computeVoidDigest(string $sellerRef, string $environment, VoidLegalDocument $command): string
    {
        return CanonicalJson::digest([
            'sellerRef' => $sellerRef,
            'environment' => $environment,
            'legalDocumentRef' => $command->legalDocumentRef,
            'reason' => $command->reason,
        ]);
    }

    public function computeDisplayMetadataDigest(string $sellerRef, string $environment, UpdateLegalDocumentDisplayMetadata $command): string
    {
        return CanonicalJson::digest([
            'sellerRef' => $sellerRef,
            'environment' => $environment,
            'legalDocumentRef' => $command->legalDocumentRef,
            'displayMetadata' => $command->displayMetadata,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    public function assertDisplayMetadataAllowList(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (! in_array($key, self::ALLOWED_DISPLAY_METADATA_KEYS, true)) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.display_metadata_key_forbidden: '.$key);
            }
            if (! is_string($value) && ! is_int($value) && ! is_bool($value)) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.display_metadata_scalar_required');
            }
        }
    }

    public function assertFinalizationReadyForIssue(
        ?DocumentFinalizationView $finalization,
        DocumentType $expectedType,
        string $expectedEconomicRef,
        string $expectedApprovedSnapshotDigest,
    ): DocumentFinalizationView {
        if ($finalization === null || $finalization->state !== FinalizationState::SnapshotReady) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.snapshot_not_ready');
        }

        if (
            $finalization->documentType !== $expectedType
            || $finalization->economicRef !== $expectedEconomicRef
            || $finalization->approvedSnapshotDigest !== $expectedApprovedSnapshotDigest
        ) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.snapshot_mismatch');
        }

        return $finalization;
    }

    /** @param array<string, mixed> $buyerSnapshot */
    public function assertRequiredBuyerFields(DocumentProfile $profile, array $buyerSnapshot): void
    {
        foreach ($profile->requiredFields as $field) {
            if (! str_starts_with($field, 'buyer.')) {
                continue;
            }
            $key = substr($field, 6);
            $val = $buyerSnapshot[$key] ?? null;
            if (! is_string($val) || trim($val) === '') {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.missing_required_buyer_field: '.$key);
            }
        }
    }

    public function assertSeriesEligible(bool $active, DocumentType $seriesType, DocumentType $expectedType): void
    {
        if (! $active) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.series_inactive');
        }
        if ($seriesType !== $expectedType) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.series_document_type_mismatch');
        }
    }

    public function assertExistingLegalMatchesOrThrow(
        LegalDocumentView $existing,
        string $approvedSnapshotDigest,
        string $seriesRef,
        string $periodKey,
        string $legalDocumentRef,
    ): void {
        if (
            $existing->approvedSnapshotDigest !== $approvedSnapshotDigest
            || $existing->seriesRef !== $seriesRef
            || $existing->periodKey !== $periodKey
            || $existing->legalDocumentRef !== $legalDocumentRef
        ) {
            throw new ProblemException(ProblemCode::IdempotencyConflict, 'billium.documents.basis_already_issued');
        }
    }

    /**
     * @param  array{currency: string, subtotalMinor: int, taxMinor: int, totalMinor: int, lines: list<array{obligationRef: string, subtotalMinor: int, taxMinor: int, totalMinor: int}>}  $lockedInvoice
     * @param  array<string, mixed>  $lockedReceiptBody
     */
    public function assertLockedInvoiceMatchesFinalization(
        array $lockedInvoice,
        array $lockedReceiptBody,
        DocumentFinalizationView $finalization,
        string $legalDocumentRef,
    ): void {
        if (
            ($lockedReceiptBody['legal_document_ref'] ?? null) !== $legalDocumentRef
            || ($lockedReceiptBody['currency'] ?? null) !== $finalization->currency
            || $lockedInvoice['currency'] !== $finalization->currency
        ) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.basis_changed_under_lock');
        }

        $lockedTotalMinor = RowCast::integer($lockedReceiptBody['total_minor'] ?? null, 'total_minor');
        if (
            $finalization->subtotalMinor === null
            || $finalization->taxMinor === null
            || $finalization->totalMinor === null
            || $lockedTotalMinor !== $finalization->totalMinor
            || $lockedInvoice['subtotalMinor'] !== $finalization->subtotalMinor
            || $lockedInvoice['taxMinor'] !== $finalization->taxMinor
            || $lockedInvoice['totalMinor'] !== $finalization->totalMinor
        ) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.basis_changed_under_lock');
        }

        if (count($lockedInvoice['lines']) !== count($finalization->lines)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.basis_changed_under_lock');
        }

        $ledgerByObligation = [];
        foreach ($lockedInvoice['lines'] as $invLine) {
            $obRef = $invLine['obligationRef'];
            if (isset($ledgerByObligation[$obRef])) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.basis_changed_under_lock');
            }
            $ledgerByObligation[$obRef] = [
                'sub' => $invLine['subtotalMinor'],
                'tax' => $invLine['taxMinor'],
                'tot' => $invLine['totalMinor'],
            ];
        }

        $taxByLineRef = [];
        if ($finalization->taxSnapshot !== null) {
            foreach ($finalization->taxSnapshot->lines as $tLine) {
                $taxByLineRef[$tLine->lineRef] = $tLine;
            }
        }
        if (count($taxByLineRef) !== count($finalization->lines)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.basis_changed_under_lock');
        }

        $matchedObligations = [];
        foreach ($finalization->lines as $basisLine) {
            if (
                isset($matchedObligations[$basisLine->obligationRef])
                || ! isset($ledgerByObligation[$basisLine->obligationRef], $taxByLineRef[$basisLine->lineRef])
            ) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.basis_changed_under_lock');
            }
            $matchedObligations[$basisLine->obligationRef] = true;
            $lLine = $ledgerByObligation[$basisLine->obligationRef];
            $tLine = $taxByLineRef[$basisLine->lineRef];

            if (
                $lLine['sub'] !== $tLine->netMinor
                || $lLine['tax'] !== $tLine->taxMinor
                || $lLine['tot'] !== $tLine->grossMinor
            ) {
                throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.basis_changed_under_lock');
            }
        }

        if (count($matchedObligations) !== count($ledgerByObligation)) {
            throw new ProblemException(ProblemCode::ValidationInvalid, 'billium.documents.basis_changed_under_lock');
        }
    }

    /**
     * Returns true if void transition should be persisted, false if idempotent replay.
     *
     * @throws ProblemException billium.invoice.transition_invalid when already voided with a different reason
     */
    public function decideVoidTransition(
        LegalDocumentStatus $status,
        ?string $existingVoidReason,
        string $requestedReason,
    ): bool {
        if ($status === LegalDocumentStatus::Voided) {
            if ($existingVoidReason === $requestedReason) {
                return false;
            }

            throw new ProblemException(ProblemCode::InvoiceTransitionInvalid, 'billium.documents.already_voided');
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public function mergeDisplayMetadata(array $existing, array $incoming): array
    {
        return array_merge($existing, $incoming);
    }

    /** @return array<string, mixed> */
    public function viewToReceiptBody(LegalDocumentView $view): array
    {
        return [
            'id' => $view->id,
            'legalDocumentRef' => $view->legalDocumentRef,
            'documentType' => $view->documentType->value,
            'economicRef' => $view->economicRef,
            'documentRevision' => $view->documentRevision,
            'seriesRef' => $view->seriesRef,
            'periodKey' => $view->periodKey,
            'sequenceNumber' => $view->sequenceNumber,
            'legalNumber' => $view->legalNumber,
            'status' => $view->status->value,
            'approvedSnapshotDigest' => $view->approvedSnapshotDigest,
            'currency' => $view->currency,
            'subtotalMinor' => $view->subtotalMinor,
            'taxMinor' => $view->taxMinor,
            'totalMinor' => $view->totalMinor,
            'voidReason' => $view->voidReason,
        ];
    }
}
