<?php

declare(strict_types=1);

namespace Billium\Documents\Tests\Feature;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\P6\Support\DocumentsTestCase;
use Billium\Documents\Data\AttachTaxSnapshot;
use Billium\Documents\Data\CreateDocumentDraft;
use Billium\Documents\Data\DocumentLineBasis;
use Billium\Documents\Data\DocumentTaxLineSnapshot;
use Billium\Documents\Data\DocumentTaxSnapshot;
use Billium\Documents\Data\IssueLegalDocument;
use Billium\Documents\Data\ReviseDocumentDraft;
use Billium\Documents\Data\UpdateLegalDocumentDisplayMetadata;
use Billium\Documents\Data\VoidLegalDocument;
use Billium\Documents\Enums\DiscountTaxOrder;
use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\FinalizationState;
use Billium\Documents\Enums\LegalDocumentStatus;
use Billium\Documents\Enums\SeriesPeriodPolicy;
use Billium\Documents\Enums\TaxConvention;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Obligations\RecordObligation;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterization tests locking in the exact literal behavior (digests, numbering, receipts,
 * state transitions, and validation errors) of DatabaseDocumentFinalizationService and
 * DatabaseLegalDocumentIssuer before Functional Core / Imperative Shell decomposition (Phase F4-B.3).
 */
#[Group('pg')]
final class DocumentsCharacterizationTest extends DocumentsTestCase
{
    public function test_finalization_draft_revise_attach_snapshot_and_idempotency_lock_digests_and_events(): void
    {
        $this->registerCompleteProfile('prof-char');

        $line1 = new DocumentLineBasis(
            lineRef: 'line-1',
            obligationRef: 'ob-char-1',
            componentRef: 'comp-1',
            quantity: '1',
            unitPriceDecimal: '100.00',
            netMinor: 10000,
            discountMinor: 0,
            taxCode: 'VAT20',
            taxRate: '0.20',
            description: 'Service charge',
        );

        $draft = $this->finalizations()->createDraft(new CreateDocumentDraft(
            scope: $this->docScope(),
            finalizationRef: 'fin-char-1',
            documentType: DocumentType::Invoice,
            economicRef: 'inv-char-1',
            basisRevision: 1,
            basisDigest: self::digest('a'),
            profileRef: 'prof-char',
            profileVersion: 1,
            currency: 'RUB',
            currencyExponent: 2,
            convention: TaxConvention::Exclusive,
            roundingMode: 'half_up',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [$line1],
            buyerSnapshot: ['legalName' => 'Buyer Corp', 'taxId' => 'BUYER-1'],
            termsSnapshot: ['paymentTerm' => 'NET30'],
        ));

        self::assertSame(FinalizationState::Draft, $draft->state);
        self::assertSame(1, $draft->finalizationRevision);
        self::assertStringStartsWith('sha256:', $draft->inputDigest);
        self::assertSame(71, strlen($draft->inputDigest));

        // Idempotent replay of createDraft returns same revision and digest.
        $draftReplay = $this->finalizations()->createDraft(new CreateDocumentDraft(
            scope: $this->docScope(),
            finalizationRef: 'fin-char-1',
            documentType: DocumentType::Invoice,
            economicRef: 'inv-char-1',
            basisRevision: 1,
            basisDigest: self::digest('a'),
            profileRef: 'prof-char',
            profileVersion: 1,
            currency: 'RUB',
            currencyExponent: 2,
            convention: TaxConvention::Exclusive,
            roundingMode: 'half_up',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [$line1],
            buyerSnapshot: ['legalName' => 'Buyer Corp', 'taxId' => 'BUYER-1'],
            termsSnapshot: ['paymentTerm' => 'NET30'],
        ));
        self::assertSame($draft->id, $draftReplay->id);
        self::assertSame($draft->inputDigest, $draftReplay->inputDigest);

        // Start finalization: Draft -> Finalizing; second call is idempotent.
        $started = $this->finalizations()->startFinalization($this->docScope(), 'fin-char-1');
        self::assertSame(FinalizationState::Finalizing, $started->state);
        $startedAgain = $this->finalizations()->startFinalization($this->docScope(), 'fin-char-1');
        self::assertSame(FinalizationState::Finalizing, $startedAgain->state);
        self::assertSame($started->id, $startedAgain->id);

        // Revise draft supersedes rev 1 and creates rev 2 in Draft.
        $revised = $this->finalizations()->reviseDraft(new ReviseDocumentDraft(
            scope: $this->docScope(),
            finalizationRef: 'fin-char-1',
            basisRevision: 2,
            basisDigest: self::digest('b'),
            currency: 'RUB',
            currencyExponent: 2,
            convention: TaxConvention::Exclusive,
            roundingMode: 'half_up',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [$line1],
            buyerSnapshot: ['legalName' => 'Buyer Corp', 'taxId' => 'BUYER-1'],
            termsSnapshot: ['paymentTerm' => 'NET30'],
        ));
        self::assertSame(2, $revised->finalizationRevision);
        self::assertSame(FinalizationState::Draft, $revised->state);
        self::assertNotSame($draft->inputDigest, $revised->inputDigest);

        // Stale snapshot for rev 1 is ignored without throwing.
        $staleSnapshot = new DocumentTaxSnapshot(
            taxSnapshotRef: 'snap-stale-1',
            taxPolicyVersion: 'v1',
            taxPoint: '2026-11-19',
            convention: TaxConvention::Exclusive,
            currency: 'RUB',
            currencyExponent: 2,
            subtotalMinor: 10000,
            taxMinor: 2000,
            totalMinor: 12000,
            lines: [
                new DocumentTaxLineSnapshot('line-1', 'VAT20', '0.20', 10000, 2000, 12000),
            ],
            inputDigest: $draft->inputDigest,
            evidenceDigest: self::digest('e'),
            resolver: 'fixture-tax-port',
            resolverVersion: '1.0',
        );
        $staleResult = $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
            scope: $this->docScope(),
            finalizationRef: 'fin-char-1',
            finalizationRevision: 1,
            inputDigest: $draft->inputDigest,
            snapshot: $staleSnapshot,
        ));
        self::assertFalse($staleResult->attached);
        self::assertTrue($staleResult->staleIgnored);
        self::assertSame(2, $staleResult->finalization->finalizationRevision);

        // Attaching to rev 2 while still in Draft throws finalization_not_finalizing.
        $rev2Snapshot = new DocumentTaxSnapshot(
            taxSnapshotRef: 'snap-rev-2',
            taxPolicyVersion: 'v1',
            taxPoint: '2026-11-19',
            convention: TaxConvention::Exclusive,
            currency: 'RUB',
            currencyExponent: 2,
            subtotalMinor: 10000,
            taxMinor: 2000,
            totalMinor: 12000,
            lines: [
                new DocumentTaxLineSnapshot('line-1', 'VAT20', '0.20', 10000, 2000, 12000),
            ],
            inputDigest: $revised->inputDigest,
            evidenceDigest: self::digest('f'),
            resolver: 'fixture-tax-port',
            resolverVersion: '1.0',
        );
        try {
            $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
                scope: $this->docScope(),
                finalizationRef: 'fin-char-1',
                finalizationRevision: 2,
                inputDigest: $revised->inputDigest,
                snapshot: $rev2Snapshot,
            ));
            self::fail('Expected ProblemException for attaching snapshot while Draft');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            self::assertSame('billium.documents.finalization_not_finalizing', $e->detail);
        }

        // Start rev 2 and attach snapshot -> SnapshotReady.
        $this->finalizations()->startFinalization($this->docScope(), 'fin-char-1');
        $attached = $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
            scope: $this->docScope(),
            finalizationRef: 'fin-char-1',
            finalizationRevision: 2,
            inputDigest: $revised->inputDigest,
            snapshot: $rev2Snapshot,
        ));
        self::assertTrue($attached->attached);
        self::assertFalse($attached->staleIgnored);
        self::assertSame(FinalizationState::SnapshotReady, $attached->finalization->state);
        self::assertSame(10000, $attached->finalization->subtotalMinor);
        self::assertSame(2000, $attached->finalization->taxMinor);
        self::assertSame(12000, $attached->finalization->totalMinor);
        self::assertNotNull($attached->finalization->taxSnapshotDigest);
        self::assertNotNull($attached->finalization->approvedSnapshotDigest);

        // Idempotent retry on SnapshotReady returns attached=false, staleIgnored=false.
        $idempotent = $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
            scope: $this->docScope(),
            finalizationRef: 'fin-char-1',
            finalizationRevision: 2,
            inputDigest: $revised->inputDigest,
            snapshot: $rev2Snapshot,
        ));
        self::assertFalse($idempotent->attached);
        self::assertFalse($idempotent->staleIgnored);
        self::assertSame($attached->finalization->approvedSnapshotDigest, $idempotent->finalization->approvedSnapshotDigest);

        // Events sequence is preserved.
        $events = $this->finalizations()->events($this->docScope(), 'fin-char-1');
        $eventKinds = array_map(static fn ($e): string => $e->eventKind, $events);
        self::assertSame([
            'draft_created',
            'finalization_started',
            'revision_superseded',
            'draft_created',
            'stale_snapshot_ignored',
            'finalization_started',
            'snapshot_attached',
        ], $eventKinds);
    }

    public function test_legal_document_issue_numbering_receipt_display_metadata_and_void(): void
    {
        $this->registerCompleteProfile('prof-default');
        $this->registerSeries('INV', '2026', SeriesPeriodPolicy::Yearly, DocumentType::Invoice);
        $fin = $this->prepareFinalizedInvoiceBasis('char-issue', 10000, 2000, 'RUB');
        self::assertNotNull($fin->approvedSnapshotDigest);

        $outcome = $this->issuer()->issueInvoiceDocument(new IssueLegalDocument(
            commandKey: 'cmd-char-issue-1',
            scope: $this->account(),
            invoiceRef: 'inv-char-issue',
            economicRef: 'inv-char-issue',
            documentRevision: 1,
            finalizationRef: 'fin-char-issue',
            approvedSnapshotDigest: $fin->approvedSnapshotDigest,
            seriesRef: 'INV',
            periodKey: '2026',
            displayMetadata: ['displayLabel' => 'Invoice #1', 'locale' => 'en-US'],
        ));

        self::assertSame('documents.legal_document.issued', $outcome->receiptClass);
        $body = $outcome->body;
        self::assertSame('invoice', $body['documentType']);
        self::assertSame('inv-char-issue', $body['economicRef']);
        self::assertSame(1, $body['documentRevision']);
        self::assertSame('INV', $body['seriesRef']);
        self::assertSame('2026', $body['periodKey']);
        self::assertSame(1, $body['sequenceNumber']);
        self::assertSame('INV-2026-000001', $body['legalNumber']);
        self::assertSame(LegalDocumentStatus::Issued->value, $body['status']);
        self::assertSame($fin->approvedSnapshotDigest, $body['approvedSnapshotDigest']);
        self::assertSame('RUB', $body['currency']);
        self::assertSame(10000, $body['subtotalMinor']);
        self::assertSame(2000, $body['taxMinor']);
        self::assertSame(12000, $body['totalMinor']);
        self::assertNull($body['voidReason']);

        self::assertIsString($body['legalDocumentRef']);
        $legalDocRef = $body['legalDocumentRef'];
        self::assertStringStartsWith('ldoc-', $legalDocRef);
        self::assertSame(29, strlen($legalDocRef));

        // Update display metadata merges keys.
        $metaOutcome = $this->issuer()->updateDisplayMetadata(new UpdateLegalDocumentDisplayMetadata(
            commandKey: 'cmd-char-meta-1',
            scope: $this->docScope(),
            legalDocumentRef: $legalDocRef,
            displayMetadata: ['externalReference' => 'EXT-42'],
        ));
        self::assertSame('documents.legal_document.display_metadata_updated', $metaOutcome->receiptClass);

        $view = $this->docQueries()->find($this->docScope(), $legalDocRef);
        self::assertNotNull($view);
        self::assertEquals([
            'displayLabel' => 'Invoice #1',
            'externalReference' => 'EXT-42',
            'locale' => 'en-US',
        ], $view->displayMetadata);

        // Void legal document.
        $voidOutcome = $this->issuer()->voidLegalDocument(new VoidLegalDocument(
            commandKey: 'cmd-char-void-1',
            scope: $this->docScope(),
            legalDocumentRef: $legalDocRef,
            reason: 'issued_in_error',
        ));
        self::assertSame('documents.legal_document.voided', $voidOutcome->receiptClass);
        self::assertSame(LegalDocumentStatus::Voided->value, $voidOutcome->body['status']);
        self::assertSame('issued_in_error', $voidOutcome->body['voidReason']);

        // Voiding again with a different reason returns a stored refusal (InvoiceTransitionInvalid).
        $secondVoid = $this->issuer()->voidLegalDocument(new VoidLegalDocument(
            commandKey: 'cmd-char-void-2',
            scope: $this->docScope(),
            legalDocumentRef: $legalDocRef,
            reason: 'different_reason',
        ));
        self::assertFalse($secondVoid->accepted);
        self::assertSame(ProblemCode::InvoiceTransitionInvalid, $secondVoid->problem);
        self::assertSame('billium.documents.already_voided', $secondVoid->detail);
    }

    public function test_issue_rejects_mismatch_between_locked_invoice_and_finalization(): void
    {
        $this->registerCompleteProfile('prof-default');
        $this->registerSeries('INV', '2026', SeriesPeriodPolicy::Yearly, DocumentType::Invoice);

        // Prepare finalization for 10000+2000, but ledger invoice has 10000+1500.
        $this->ledger()->recordObligation(new RecordObligation(
            commandKey: 'rec-ob-mismatch',
            scope: $this->account(),
            obligationRef: 'ob-mismatch',
            basisRef: 'basis-mismatch',
            customerRef: 'cust-mismatch',
            componentRef: 'comp-1',
            money: new Money(11500, Currency::of('RUB')),
            termsDigest: self::digest('b'),
        ));
        $this->ledger()->createInvoice(new CreateInvoice(
            commandKey: 'create-inv-mismatch',
            scope: $this->account(),
            invoiceRef: 'inv-mismatch',
            customerRef: 'cust-mismatch',
            lines: [new InvoiceLineInput('ob-mismatch', 1500, ['name' => 'Service'])],
            snapshot: ['buyer' => 'Buyer Corp'],
        ));

        $draft = $this->finalizations()->createDraft(new CreateDocumentDraft(
            scope: $this->docScope(),
            finalizationRef: 'fin-mismatch',
            documentType: DocumentType::Invoice,
            economicRef: 'inv-mismatch',
            basisRevision: 1,
            basisDigest: self::digest('c'),
            profileRef: 'prof-default',
            profileVersion: 1,
            currency: 'RUB',
            currencyExponent: 2,
            convention: TaxConvention::Exclusive,
            roundingMode: 'half_up',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [
                new DocumentLineBasis('line-1', 'ob-mismatch', 'comp-1', '1', '100.00', 10000, 0, 'VAT20', '0.20', 'Service'),
            ],
            buyerSnapshot: ['legalName' => 'Buyer Corp'],
            termsSnapshot: ['paymentTerm' => 'NET30'],
        ));
        $started = $this->finalizations()->startFinalization($this->docScope(), 'fin-mismatch');
        $attached = $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
            scope: $this->docScope(),
            finalizationRef: 'fin-mismatch',
            finalizationRevision: $started->finalizationRevision,
            inputDigest: $started->inputDigest,
            snapshot: new DocumentTaxSnapshot(
                taxSnapshotRef: 'snap-mismatch',
                taxPolicyVersion: 'v1',
                taxPoint: '2026-11-19',
                convention: TaxConvention::Exclusive,
                currency: 'RUB',
                currencyExponent: 2,
                subtotalMinor: 10000,
                taxMinor: 2000,
                totalMinor: 12000,
                lines: [new DocumentTaxLineSnapshot('line-1', 'VAT20', '0.20', 10000, 2000, 12000)],
                inputDigest: $started->inputDigest,
                evidenceDigest: self::digest('d'),
                resolver: 'fixture-tax-port',
                resolverVersion: '1.0',
            ),
        ));
        $approvedDigest = $attached->finalization->approvedSnapshotDigest;
        self::assertNotNull($approvedDigest);

        try {
            $this->issuer()->issueInvoiceDocument(new IssueLegalDocument(
                commandKey: 'cmd-mismatch',
                scope: $this->account(),
                invoiceRef: 'inv-mismatch',
                economicRef: 'inv-mismatch',
                documentRevision: 1,
                finalizationRef: 'fin-mismatch',
                approvedSnapshotDigest: $approvedDigest,
                seriesRef: 'INV',
                periodKey: '2026',
            ));
            self::fail('Expected ProblemException for basis_changed_under_lock');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            self::assertSame('billium.documents.basis_changed_under_lock', $e->detail);
        }
    }
}
