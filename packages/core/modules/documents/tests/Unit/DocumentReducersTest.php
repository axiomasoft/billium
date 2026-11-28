<?php

declare(strict_types=1);

namespace Billium\Documents\Tests\Unit;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Documents\Data\AttachTaxSnapshot;
use Billium\Documents\Data\DocumentFinalizationView;
use Billium\Documents\Data\DocumentLineBasis;
use Billium\Documents\Data\DocumentProfile;
use Billium\Documents\Data\DocumentScope;
use Billium\Documents\Data\DocumentTaxLineSnapshot;
use Billium\Documents\Data\DocumentTaxSnapshot;
use Billium\Documents\Data\IssueLegalDocument;
use Billium\Documents\Data\LegalDocumentView;
use Billium\Documents\Data\UpdateLegalDocumentDisplayMetadata;
use Billium\Documents\Data\VoidLegalDocument;
use Billium\Documents\Enums\DiscountTaxOrder;
use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\FinalizationState;
use Billium\Documents\Enums\LegalDocumentStatus;
use Billium\Documents\Enums\TaxConvention;
use Billium\Documents\Internal\Reducers\AttachSnapshotDecision;
use Billium\Documents\Internal\Reducers\DocumentSnapshotAssembler;
use Billium\Documents\Internal\Reducers\FinalizationStateReducer;
use Billium\Documents\Internal\Reducers\LegalIssuePreconditionChecker;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use LogicException;
use PHPUnit\Framework\TestCase;

final class DocumentReducersTest extends TestCase
{
    public function test_assembler_validates_draft_inputs_and_rejects_invalid_cases(): void
    {
        $assembler = new DocumentSnapshotAssembler;
        $line = $this->makeLine('line-1', 10000);

        $currency = $assembler->validateDraftInputs(1, 'RUB', 2, 'half_up', [$line]);
        self::assertSame('RUB', $currency->code);

        // basisRevision < 1
        try {
            $assembler->validateDraftInputs(0, 'RUB', 2, 'half_up', [$line]);
            self::fail('Expected ProblemException for basisRevision < 1');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            self::assertSame('basisRevision must be positive', $e->detail);
        }

        // exponent mismatch
        try {
            $assembler->validateDraftInputs(1, 'RUB', 3, 'half_up', [$line]);
            self::fail('Expected ProblemException for currency exponent mismatch');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::MoneyScaleInvalid, $e->problem);
        }

        // unsupported rounding mode
        try {
            $assembler->validateDraftInputs(1, 'RUB', 2, 'ceiling', [$line]);
            self::fail('Expected ProblemException for unsupported rounding mode');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }

        // empty lines
        try {
            $assembler->validateDraftInputs(1, 'RUB', 2, 'half_up', []);
            self::fail('Expected ProblemException for empty lines');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }

        // duplicate lineRef
        try {
            $assembler->validateDraftInputs(1, 'RUB', 2, 'half_up', [$line, $line]);
            self::fail('Expected ProblemException for duplicate lineRef');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }

        // negative netMinor
        try {
            $assembler->validateDraftInputs(1, 'RUB', 2, 'half_up', [$this->makeLine('line-1', -1)]);
            self::fail('Expected ProblemException for negative netMinor');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }

        // negative discountMinor
        try {
            $assembler->validateDraftInputs(1, 'RUB', 2, 'half_up', [$this->makeLine('line-1', 100, -1)]);
            self::fail('Expected ProblemException for negative discountMinor');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }
    }

    public function test_assembler_serializes_hydrates_and_computes_deterministic_digests(): void
    {
        $assembler = new DocumentSnapshotAssembler;
        $lines = $assembler->serializeLines([$this->makeLine('line-1', 10000)]);
        $digest1 = $assembler->computeInputDigest(
            sellerRef: 'seller-a',
            environment: 'test',
            finalizationRef: 'fin-1',
            documentType: 'invoice',
            economicRef: 'inv-1',
            basisRevision: 1,
            basisDigest: 'sha256:basis',
            profileId: 10,
            profileVersion: 1,
            currency: 'RUB',
            currencyExponent: 2,
            convention: 'exclusive',
            roundingMode: 'half_up',
            discountTaxOrder: 'discount_before_tax',
            lines: $lines,
            buyerSnapshot: ['legalName' => 'Buyer'],
            termsSnapshot: ['paymentTerm' => 'NET30'],
        );
        self::assertStringStartsWith('sha256:', $digest1);

        $snapshot = $this->makeTaxSnapshot($digest1);
        $serialized = $assembler->serializeTaxSnapshot($snapshot);
        $hydrated = $assembler->hydrateTaxSnapshot($serialized);
        self::assertEquals($snapshot, $hydrated);

        $snapDigest = $assembler->computeTaxSnapshotDigest($serialized);
        $approvedDigest = $assembler->computeApprovedSnapshotDigest('fin-1', 1, 1, 'sha256:basis', $digest1, $snapDigest, 10, 1, $snapshot);
        self::assertStringStartsWith('sha256:', $approvedDigest);

        $finView = $this->makeFinalizationView(FinalizationState::SnapshotReady, $digest1, $snapDigest, $approvedDigest, $snapshot);
        $request = $assembler->buildTaxSnapshotRequest($finView, $this->makeProfile(), TaxConvention::Exclusive, 'half_up', DiscountTaxOrder::DiscountBeforeTax);
        self::assertSame('fin-1', $request->finalizationRef);
        self::assertSame('US-NY', $request->jurisdictionRef);

        $finalLines = $assembler->serializeFinalizationLines($finView, 'half_up/1');
        self::assertCount(1, $finalLines);
        self::assertSame(10000, $finalLines[0]['netMinor']);
        self::assertSame(2000, $finalLines[0]['taxMinor']);
        self::assertSame(12000, $finalLines[0]['grossMinor']);
        self::assertSame('half_up/1', $finalLines[0]['roundingPolicyVersion']);

        // Invalid payload throws LogicException
        $this->expectException(LogicException::class);
        $assembler->hydrateTaxSnapshot(['lines' => 'not-an-array']);
    }

    public function test_finalization_state_reducer_start_revise_and_draft_conflict(): void
    {
        $reducer = new FinalizationStateReducer;

        $reducer->assertDraftIdempotentOrThrow('sha256:a', 'sha256:a');
        try {
            $reducer->assertDraftIdempotentOrThrow('sha256:a', 'sha256:b');
            self::fail('Expected ProblemException on draft digest conflict');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            self::assertSame('billium.documents.finalization_conflict', $e->detail);
        }

        self::assertTrue($reducer->decideStartFinalization(FinalizationState::Draft));
        self::assertFalse($reducer->decideStartFinalization(FinalizationState::Finalizing));

        try {
            $reducer->decideStartFinalization(FinalizationState::SnapshotReady);
            self::fail('Expected InvoiceFrozen');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::InvoiceFrozen, $e->problem);
            self::assertSame('billium.documents.finalization_already_ready', $e->detail);
        }

        try {
            $reducer->decideStartFinalization(FinalizationState::Superseded);
            self::fail('Expected ValidationInvalid for Superseded');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            self::assertSame('billium.documents.finalization_state_conflict', $e->detail);
        }

        $reducer->assertRevisable(FinalizationState::Draft);
        $reducer->assertRevisable(FinalizationState::Finalizing);
        try {
            $reducer->assertRevisable(FinalizationState::SnapshotReady);
            self::fail('Expected InvoiceFrozen on revise');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::InvoiceFrozen, $e->problem);
            self::assertSame('billium.documents.finalization_frozen', $e->detail);
        }
    }

    public function test_finalization_state_reducer_attach_snapshot_decisions_and_validation(): void
    {
        $assembler = new DocumentSnapshotAssembler;
        $reducer = new FinalizationStateReducer($assembler);

        $inputDigest = 'sha256:input-1';
        $snapshot = $this->makeTaxSnapshot($inputDigest);
        $snapDigest = $assembler->computeTaxSnapshotDigest($assembler->serializeTaxSnapshot($snapshot));
        $scope = new DocumentScope('seller-a', 'test');
        $command = new AttachTaxSnapshot($scope, 'fin-1', 1, $inputDigest, $snapshot);

        // 1. Idempotent when already SnapshotReady with matching digests
        $readyView = $this->makeFinalizationView(FinalizationState::SnapshotReady, $inputDigest, $snapDigest, 'sha256:app', $snapshot);
        $idempotent = $reducer->decideAttachTaxSnapshot($readyView, TaxConvention::Exclusive, 1, 1, $inputDigest, $command, $snapDigest);
        self::assertSame(AttachSnapshotDecision::ACTION_IDEMPOTENT, $idempotent->action);
        self::assertSame(1, $idempotent->viewId);

        // 2. Stale when superseded or revision moved ahead or digest mismatch
        $supersededView = $this->makeFinalizationView(FinalizationState::Superseded, $inputDigest, null, null, null);
        $stale = $reducer->decideAttachTaxSnapshot($supersededView, TaxConvention::Exclusive, 2, 2, 'sha256:input-2', $command, $snapDigest);
        self::assertSame(AttachSnapshotDecision::ACTION_STALE, $stale->action);
        self::assertSame(2, $stale->viewId);
        self::assertSame([
            'expectedInputDigest' => 'sha256:input-2',
            'latestRevision' => 2,
            'taxSnapshotRef' => 'snap-1',
        ], $stale->staleDetails);

        // 3. Draft state throws finalization_not_finalizing
        $draftView = $this->makeFinalizationView(FinalizationState::Draft, $inputDigest, null, null, null);
        try {
            $reducer->decideAttachTaxSnapshot($draftView, TaxConvention::Exclusive, 1, 1, $inputDigest, $command, $snapDigest);
            self::fail('Expected finalization_not_finalizing');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            self::assertSame('billium.documents.finalization_not_finalizing', $e->detail);
        }

        // 4. Happy attach when Finalizing
        $finalizingView = $this->makeFinalizationView(FinalizationState::Finalizing, $inputDigest, null, null, null);
        $attach = $reducer->decideAttachTaxSnapshot($finalizingView, TaxConvention::Exclusive, 1, 1, $inputDigest, $command, $snapDigest);
        self::assertSame(AttachSnapshotDecision::ACTION_ATTACH, $attach->action);
        self::assertNotNull($attach->approvedSnapshotDigest);
        self::assertStringStartsWith('sha256:', $attach->approvedSnapshotDigest);

        // 5. Currency conflict
        $badCurrencySnap = $this->makeTaxSnapshot($inputDigest, currency: 'USD');
        try {
            $reducer->validateSnapshotAgainstDraft($finalizingView, TaxConvention::Exclusive, $badCurrencySnap);
            self::fail('Expected currency conflict');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::MoneyCurrencyMismatch, $e->problem);
        }

        // 6. NoneDeclared requires 0 tax and non-empty noTaxReason
        $badNoneDeclared = $this->makeTaxSnapshot($inputDigest, convention: TaxConvention::NoneDeclared, taxMinor: 0, totalMinor: 10000, lineTax: 0, lineGross: 10000, noTaxReason: '  ');
        try {
            $reducer->validateSnapshotAgainstDraft($finalizingView, TaxConvention::NoneDeclared, $badNoneDeclared);
            self::fail('Expected none_declared_requires_zero_tax_and_reason');
        } catch (ProblemException $e) {
            self::assertSame('billium.documents.none_declared_requires_zero_tax_and_reason', $e->detail);
        }
    }

    public function test_legal_issue_precondition_checker_covers_refs_metadata_buyer_series_lock_and_void(): void
    {
        $checker = new LegalIssuePreconditionChecker;

        $ref1 = $checker->deterministicLegalDocumentRef('seller-a', 'test', DocumentType::Invoice, 'inv-1', 1);
        $ref2 = $checker->deterministicLegalDocumentRef('seller-a', 'test', DocumentType::Invoice, 'inv-1', 1);
        self::assertSame($ref1, $ref2);
        self::assertStringStartsWith('ldoc-', $ref1);
        self::assertSame(29, strlen($ref1));

        // Display metadata allowlist
        $checker->assertDisplayMetadataAllowList(['displayLabel' => 'Inv', 'locale' => 'en', 'customNotes' => 1, 'deliveryNote' => true]);
        try {
            $checker->assertDisplayMetadataAllowList(['forbiddenKey' => 'x']);
            self::fail('Expected display_metadata_key_forbidden');
        } catch (ProblemException $e) {
            self::assertSame('billium.documents.display_metadata_key_forbidden: forbiddenKey', $e->detail);
        }
        try {
            $checker->assertDisplayMetadataAllowList(['displayLabel' => ['nested']]);
            self::fail('Expected display_metadata_scalar_required');
        } catch (ProblemException $e) {
            self::assertSame('billium.documents.display_metadata_scalar_required', $e->detail);
        }

        // Digests
        $issueCmd = new IssueLegalDocument(
            commandKey: 'ck-1',
            scope: new BillingAccountScope('seller-a', 'test', 'acc-1'),
            invoiceRef: 'inv-1',
            economicRef: 'inv-1',
            documentRevision: 1,
            finalizationRef: 'fin-1',
            approvedSnapshotDigest: 'sha256:app',
            seriesRef: 'INV',
            periodKey: '2026',
        );
        self::assertStringStartsWith('sha256:', $checker->computeIssueDigest($issueCmd, $ref1));
        self::assertStringStartsWith('sha256:', $checker->computeVoidDigest('seller-a', 'test', new VoidLegalDocument('ck-2', new DocumentScope('seller-a', 'test'), $ref1, 'err')));
        self::assertStringStartsWith('sha256:', $checker->computeDisplayMetadataDigest('seller-a', 'test', new UpdateLegalDocumentDisplayMetadata('ck-3', new DocumentScope('seller-a', 'test'), $ref1, ['locale' => 'ru'])));

        // Finalization readiness & buyer fields & series
        $snap = $this->makeTaxSnapshot('sha256:in');
        $fin = $this->makeFinalizationView(FinalizationState::SnapshotReady, 'sha256:in', 'sha256:ts', 'sha256:app', $snap);
        $checker->assertFinalizationReadyForIssue($fin, DocumentType::Invoice, 'inv-1', 'sha256:app');
        $checker->assertRequiredBuyerFields($this->makeProfile(), ['legalName' => 'Buyer Corp']);
        $checker->assertSeriesEligible(true, DocumentType::Invoice, DocumentType::Invoice);

        try {
            $checker->assertRequiredBuyerFields($this->makeProfile(), ['legalName' => '  ']);
            self::fail('Expected missing_required_buyer_field');
        } catch (ProblemException $e) {
            self::assertSame('billium.documents.missing_required_buyer_field: legalName', $e->detail);
        }

        try {
            $checker->assertSeriesEligible(false, DocumentType::Invoice, DocumentType::Invoice);
            self::fail('Expected series_inactive');
        } catch (ProblemException $e) {
            self::assertSame('billium.documents.series_inactive', $e->detail);
        }

        // Locked invoice matches finalization
        $checker->assertLockedInvoiceMatchesFinalization(
            lockedInvoice: [
                'currency' => 'RUB',
                'subtotalMinor' => 10000,
                'taxMinor' => 2000,
                'totalMinor' => 12000,
                'lines' => [
                    ['obligationRef' => 'ob-1', 'subtotalMinor' => 10000, 'taxMinor' => 2000, 'totalMinor' => 12000],
                ],
            ],
            lockedReceiptBody: [
                'legal_document_ref' => $ref1,
                'currency' => 'RUB',
                'total_minor' => 12000,
            ],
            finalization: $fin,
            legalDocumentRef: $ref1,
        );

        try {
            $checker->assertLockedInvoiceMatchesFinalization(
                lockedInvoice: [
                    'currency' => 'RUB',
                    'subtotalMinor' => 10000,
                    'taxMinor' => 1500,
                    'totalMinor' => 11500,
                    'lines' => [
                        ['obligationRef' => 'ob-1', 'subtotalMinor' => 10000, 'taxMinor' => 1500, 'totalMinor' => 11500],
                    ],
                ],
                lockedReceiptBody: ['legal_document_ref' => $ref1, 'currency' => 'RUB', 'total_minor' => 11500],
                finalization: $fin,
                legalDocumentRef: $ref1,
            );
            self::fail('Expected basis_changed_under_lock');
        } catch (ProblemException $e) {
            self::assertSame('billium.documents.basis_changed_under_lock', $e->detail);
        }

        // Void transition & metadata merge & receipt body
        self::assertTrue($checker->decideVoidTransition(LegalDocumentStatus::Issued, null, 'reason-a'));
        self::assertFalse($checker->decideVoidTransition(LegalDocumentStatus::Voided, 'reason-a', 'reason-a'));
        try {
            $checker->decideVoidTransition(LegalDocumentStatus::Voided, 'reason-a', 'reason-b');
            self::fail('Expected already_voided');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::InvoiceTransitionInvalid, $e->problem);
            self::assertSame('billium.documents.already_voided', $e->detail);
        }

        self::assertSame(['a' => 1, 'b' => 2], $checker->mergeDisplayMetadata(['a' => 1], ['b' => 2]));

        $legalView = $this->makeLegalDocumentView($ref1);
        $receiptBody = $checker->viewToReceiptBody($legalView);
        self::assertSame($ref1, $receiptBody['legalDocumentRef']);
        self::assertSame('INV-2026-000001', $receiptBody['legalNumber']);
    }

    private function makeLine(string $lineRef, int $netMinor, int $discountMinor = 0): DocumentLineBasis
    {
        return new DocumentLineBasis(
            lineRef: $lineRef,
            obligationRef: 'ob-1',
            componentRef: 'comp-1',
            quantity: '1',
            unitPriceDecimal: '100.00',
            netMinor: $netMinor,
            discountMinor: $discountMinor,
            taxCode: 'VAT20',
            taxRate: '0.20',
            description: 'Service',
        );
    }

    private function makeTaxSnapshot(
        string $inputDigest,
        string $currency = 'RUB',
        TaxConvention $convention = TaxConvention::Exclusive,
        int $subtotalMinor = 10000,
        int $taxMinor = 2000,
        int $totalMinor = 12000,
        int $lineTax = 2000,
        int $lineGross = 12000,
        ?string $noTaxReason = null,
    ): DocumentTaxSnapshot {
        return new DocumentTaxSnapshot(
            taxSnapshotRef: 'snap-1',
            taxPolicyVersion: 'v1',
            taxPoint: '2026-11-19',
            convention: $convention,
            currency: $currency,
            currencyExponent: 2,
            subtotalMinor: $subtotalMinor,
            taxMinor: $taxMinor,
            totalMinor: $totalMinor,
            lines: [
                new DocumentTaxLineSnapshot('line-1', 'VAT20', '0.20', $subtotalMinor, $lineTax, $lineGross),
            ],
            inputDigest: $inputDigest,
            evidenceDigest: 'sha256:ev',
            resolver: 'test',
            resolverVersion: '1.0',
            noTaxReason: $noTaxReason,
        );
    }

    private function makeFinalizationView(
        FinalizationState $state,
        string $inputDigest,
        ?string $taxSnapshotDigest,
        ?string $approvedSnapshotDigest,
        ?DocumentTaxSnapshot $taxSnapshot,
    ): DocumentFinalizationView {
        return new DocumentFinalizationView(
            id: 1,
            sellerRef: 'seller-a',
            environment: 'test',
            finalizationRef: 'fin-1',
            documentType: DocumentType::Invoice,
            economicRef: 'inv-1',
            basisRevision: 1,
            basisDigest: 'sha256:basis',
            profileId: 10,
            profileVersion: 1,
            finalizationRevision: 1,
            state: $state,
            currency: 'RUB',
            currencyExponent: 2,
            inputDigest: $inputDigest,
            taxSnapshotRef: $taxSnapshot?->taxSnapshotRef,
            taxSnapshotDigest: $taxSnapshotDigest,
            subtotalMinor: $taxSnapshot?->subtotalMinor,
            taxMinor: $taxSnapshot?->taxMinor,
            totalMinor: $taxSnapshot?->totalMinor,
            approvedSnapshotDigest: $approvedSnapshotDigest,
            taxSnapshot: $taxSnapshot,
            buyerSnapshot: ['legalName' => 'Buyer Corp'],
            termsSnapshot: ['paymentTerm' => 'NET30'],
            lines: [$this->makeLine('line-1', 10000)],
        );
    }

    private function makeProfile(): DocumentProfile
    {
        return new DocumentProfile(
            id: 10,
            sellerRef: 'seller-a',
            environment: 'test',
            profileRef: 'prof-1',
            version: 1,
            jurisdictionRef: 'US-NY',
            supportedTypes: [DocumentType::Invoice],
            numberingPolicy: ['seriesRef' => 'INV'],
            taxSnapshotSchema: 'v1',
            correctionPolicy: ['taxTreatment' => 'original_rate'],
            requiredFields: ['seller.legalName', 'buyer.legalName'],
            retentionPolicyRef: 'ret-10y',
            sellerSnapshot: ['legalName' => 'Seller LLC'],
            profileDigest: 'sha256:prof',
            isComplete: true,
        );
    }

    private function makeLegalDocumentView(string $legalDocRef): LegalDocumentView
    {
        return new LegalDocumentView(
            id: 1,
            legalDocumentRef: $legalDocRef,
            sellerRef: 'seller-a',
            environment: 'test',
            documentType: DocumentType::Invoice,
            economicRef: 'inv-1',
            documentRevision: 1,
            seriesRef: 'INV',
            periodKey: '2026',
            sequenceNumber: 1,
            legalNumber: 'INV-2026-000001',
            status: LegalDocumentStatus::Issued,
            profileVersion: 1,
            finalizationId: 1,
            basisRevision: 1,
            basisDigest: 'sha256:basis',
            approvedSnapshotDigest: 'sha256:app',
            currency: 'RUB',
            subtotalMinor: 10000,
            taxMinor: 2000,
            totalMinor: 12000,
            sellerSnapshot: ['legalName' => 'Seller LLC'],
            buyerSnapshot: ['legalName' => 'Buyer Corp'],
            taxSnapshot: [],
            termsSnapshot: [],
            linesSnapshot: [],
            displayMetadata: [],
            originalLegalDocumentRef: null,
            voidReason: null,
            voidedAt: null,
            receiptRef: 'legal-issue:'.$legalDocRef,
            issuedAt: '2026-11-20T00:00:00.000000Z',
        );
    }
}
