<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Tests\P6\Support\DocumentsTestCase;
use Billium\Core\Tests\TestEnvironment;
use Billium\Documents\Contracts\DocumentTaxArithmetic;
use Billium\Documents\Data\AttachTaxSnapshot;
use Billium\Documents\Data\CreateDocumentDraft;
use Billium\Documents\Data\CreditNoteLineRequest;
use Billium\Documents\Data\DocumentLineBasis;
use Billium\Documents\Data\DocumentTaxCorrectionPolicy;
use Billium\Documents\Data\DocumentTaxLineSnapshot;
use Billium\Documents\Data\DocumentTaxSnapshot;
use Billium\Documents\Data\IssueDocumentCreditNote;
use Billium\Documents\Data\IssueLegalDocument;
use Billium\Documents\Data\IssuePositiveDocumentCorrection;
use Billium\Documents\Data\RecordDocumentWriteOff;
use Billium\Documents\Data\VoidAndReissueLegalDocument;
use Billium\Documents\Enums\CorrectionKind;
use Billium\Documents\Enums\DiscountTaxOrder;
use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\LegalDocumentStatus;
use Billium\Documents\Enums\SeriesPeriodPolicy;
use Billium\Documents\Enums\TaxConvention;
use Billium\Documents\Enums\TaxCorrectionTreatment;
use Billium\Documents\Internal\Tax\PartialCreditAllocator;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\CreditNoteMode;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Tests\Commands\AllocationFixtures;
use PDOException;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
final class DocumentCorrectionTest extends DocumentsTestCase
{
    use AllocationFixtures;

    public function test_partial_bundle_credit_golden_lineage_and_overcredit_db_backstops(): void
    {
        // 1. Verify golden vector cases with PartialCreditAllocator across heterogeneous rounding policies.
        $vecPath = dirname(__DIR__, 4).'/fixtures/vectors/documents/correction-partial-credit.json';
        self::assertFileExists($vecPath);
        /** @var array{given: array{example: array{currency: string, currencyExponent: int, bundleLines: list<array<string, mixed>>, sequentialCredits: list<array<string, mixed>>}}} $vec */
        $vec = json_decode((string) file_get_contents($vecPath), true, 64, JSON_THROW_ON_ERROR);
        $allocator = new PartialCreditAllocator($this->application()->make(DocumentTaxArithmetic::class));
        $policy = new DocumentTaxCorrectionPolicy(
            policyRef: 'pol-orig',
            policyVersion: 'v1',
            treatment: TaxCorrectionTreatment::OriginalRate,
            selectedRateDecimal: '0.25',
            taxPoint: '2026-11-19',
            roundingPolicyVersion: 'half_up/1',
            inputDigest: self::digest('1'),
            outputDigest: self::digest('2'),
            resolverVersion: '1.0',
        );

        $bundleMeta = [];
        $running = [];
        foreach ($vec['given']['example']['bundleLines'] as $bl) {
            $id = (string) $bl['originalInvoiceLineId'];
            $bundleMeta[$id] = $bl;
            $running[$id] = ['net' => 0, 'tax' => 0, 'gross' => 0];
        }
        foreach ($vec['given']['example']['sequentialCredits'] as $step) {
            /** @var list<array{originalInvoiceLineId: string, requestedGrossMinor: int}> $reqs */
            $reqs = $step['requests'];
            /** @var list<array{originalInvoiceLineId: string, netMinor: int, taxMinor: int, grossMinor: int, cumNet: int, cumTax: int, cumGross: int}> $exps */
            $exps = $step['expected'];
            foreach ($reqs as $idx => $reqData) {
                $id = $reqData['originalInvoiceLineId'];
                $bl = $bundleMeta[$id];
                $res = $allocator->allocateLine(
                    request: new CreditNoteLineRequest($id, '0.5', $reqData['requestedGrossMinor']),
                    origNetMinor: (int) $bl['origNetMinor'],
                    origTaxMinor: (int) $bl['origTaxMinor'],
                    origGrossMinor: (int) $bl['origGrossMinor'],
                    priorNetMinor: $running[$id]['net'],
                    priorTaxMinor: $running[$id]['tax'],
                    priorGrossMinor: $running[$id]['gross'],
                    originalRoundingPolicyVersion: (string) $bl['originalRoundingPolicyVersion'],
                    currency: $vec['given']['example']['currency'],
                    currencyExponent: $vec['given']['example']['currencyExponent'],
                    policy: $policy,
                );
                $running[$id]['net'] += $res['netMinor'];
                $running[$id]['tax'] += $res['taxMinor'];
                $running[$id]['gross'] += $res['grossMinor'];
                $exp = $exps[$idx];
                self::assertSame($exp['netMinor'], $res['netMinor']);
                self::assertSame($exp['taxMinor'], $res['taxMinor']);
                self::assertSame($exp['grossMinor'], $res['grossMinor']);
                self::assertSame($exp['cumNet'], $running[$id]['net']);
                self::assertSame($exp['cumTax'], $running[$id]['tax']);
                self::assertSame($exp['cumGross'], $running[$id]['gross']);
            }
        }

        // 2. End-to-end DB two-line bundle invoice (1191 + 358 = 1549 EUR) with 2 sequential partial credits.
        $this->registerCompleteProfile();
        $this->registerSeries('INV', '2026', SeriesPeriodPolicy::Yearly, DocumentType::Invoice);
        $this->registerSeries('CN', '2026', SeriesPeriodPolicy::Yearly, DocumentType::CreditNote);

        $this->ledger()->recordObligation(new RecordObligation('rec-b1', $this->account(), 'ob-b1', 'basis-b1', 'cust-b', 'comp-a', new Money(1191, Currency::of('EUR')), self::digest('a')));
        $this->ledger()->recordObligation(new RecordObligation('rec-b2', $this->account(), 'ob-b2', 'basis-b2', 'cust-b', 'comp-b', new Money(358, Currency::of('EUR')), self::digest('a')));
        $this->ledger()->createInvoice(new CreateInvoice(
            commandKey: 'create-inv-bundle',
            scope: $this->account(),
            invoiceRef: 'inv-bundle',
            customerRef: 'cust-b',
            lines: [
                new InvoiceLineInput('ob-b1', 190, ['line' => 'line-1']),
                new InvoiceLineInput('ob-b2', 25, ['line' => 'line-2']),
            ],
        ));

        $draft = $this->finalizations()->createDraft(new CreateDocumentDraft(
            scope: $this->docScope(),
            finalizationRef: 'fin-bundle',
            documentType: DocumentType::Invoice,
            economicRef: 'inv-bundle',
            basisRevision: 1,
            basisDigest: self::digest('b'),
            profileRef: 'prof-default',
            profileVersion: 1,
            currency: 'EUR',
            currencyExponent: 2,
            convention: TaxConvention::Exclusive,
            roundingMode: 'half_up',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [
                new DocumentLineBasis('line-1', 'ob-b1', 'comp-a', '1', '10.01', 1001, 0, 'VAT19', '0.19'),
                new DocumentLineBasis('line-2', 'ob-b2', 'comp-b', '1', '3.33', 333, 0, 'VAT75', '0.075'),
            ],
            buyerSnapshot: ['legalName' => 'Bundle Buyer'],
            termsSnapshot: ['roundingPolicyVersion' => 'half_up/1'],
        ));
        $started = $this->finalizations()->startFinalization($this->docScope(), 'fin-bundle');
        $ready = $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
            scope: $this->docScope(),
            finalizationRef: 'fin-bundle',
            finalizationRevision: $started->finalizationRevision,
            inputDigest: $started->inputDigest,
            snapshot: new DocumentTaxSnapshot(
                taxSnapshotRef: 'tsnap-bundle',
                taxPolicyVersion: 'v1',
                taxPoint: '2026-11-19',
                convention: TaxConvention::Exclusive,
                currency: 'EUR',
                currencyExponent: 2,
                subtotalMinor: 1334,
                taxMinor: 215,
                totalMinor: 1549,
                lines: [
                    new DocumentTaxLineSnapshot('line-1', 'VAT19', '0.19', 1001, 190, 1191),
                    new DocumentTaxLineSnapshot('line-2', 'VAT75', '0.075', 333, 25, 358),
                ],
                inputDigest: $started->inputDigest,
                evidenceDigest: self::digest('e'),
                resolver: 'bundle-resolver',
                resolverVersion: '1.0',
            ),
        ))->finalization;

        $origIssued = $this->issuer()->issueInvoiceDocument(new IssueLegalDocument(
            commandKey: 'issue-bundle',
            scope: $this->account(),
            invoiceRef: 'inv-bundle',
            economicRef: 'inv-bundle',
            documentRevision: 1,
            finalizationRef: 'fin-bundle',
            approvedSnapshotDigest: $ready->approvedSnapshotDigest ?? '',
            seriesRef: 'INV',
            periodKey: '2026',
        ));
        $origLegalRef = (string) ($origIssued->body['legalDocumentRef'] ?? '');
        $origViewBefore = $this->docQueries()->find($this->docScope(), $origLegalRef);
        self::assertNotNull($origViewBefore);

        // Partial Credit #1 (no legal number, financial credit note only).
        $cn1 = $this->correctionService()->issueCreditNote(new IssueDocumentCreditNote(
            commandKey: 'cmd-cn-1',
            scope: $this->account(),
            correctionRef: 'corr-cn-1',
            creditNoteRef: 'cn-1',
            originalLegalDocumentRef: $origLegalRef,
            invoiceRef: 'inv-bundle',
            mode: CreditNoteMode::OpenOnly,
            lines: [
                new CreditNoteLineRequest('line-1', '0.5', 600),
                new CreditNoteLineRequest('line-2', '0.5', 179),
            ],
            taxPolicy: $policy,
            reason: 'first_partial_credit',
            issueLegalDocument: false,
        ));
        self::assertTrue($cn1->accepted);
        self::assertNull($cn1->body['resultingLegalDocumentRef'] ?? null);
        self::assertSame(1, $this->rows('billium_document_legal'));

        // Partial Credit #2 (exhausts remaining balance with separate CN legal number; original snapshot remains immutable).
        $cn2 = $this->correctionService()->issueCreditNote(new IssueDocumentCreditNote(
            commandKey: 'cmd-cn-2',
            scope: $this->account(),
            correctionRef: 'corr-cn-2',
            creditNoteRef: 'cn-2',
            originalLegalDocumentRef: $origLegalRef,
            invoiceRef: 'inv-bundle',
            mode: CreditNoteMode::OpenOnly,
            lines: [
                new CreditNoteLineRequest('line-1', '0.5', 591),
                new CreditNoteLineRequest('line-2', '0.5', 179),
            ],
            taxPolicy: $policy,
            reason: 'final_partial_credit',
            issueLegalDocument: true,
            seriesRef: 'CN',
            periodKey: '2026',
        ));
        self::assertTrue($cn2->accepted);
        self::assertSame('CN-2026-000001', $cn2->body['resultingLegalNumber'] ?? null);

        $lines2 = $this->correctionService()->creditNoteLines($this->docScope(), 'corr-cn-2');
        self::assertCount(2, $lines2);
        self::assertSame(1001, $lines2[0]->cumulativeNetMinor);
        self::assertSame(190, $lines2[0]->cumulativeTaxMinor);
        self::assertSame(1191, $lines2[0]->cumulativeGrossMinor);
        self::assertSame('comp-a', $lines2[0]->originalComponentRef);
        self::assertSame('tsnap-bundle', $lines2[0]->originalTaxSnapshotRef);
        self::assertSame(333, $lines2[1]->cumulativeNetMinor);
        self::assertSame(25, $lines2[1]->cumulativeTaxMinor);
        self::assertSame(358, $lines2[1]->cumulativeGrossMinor);

        // Original invoice snapshot & legal number remain byte-identical; zero PSP refund commands emitted.
        self::assertEquals($origViewBefore, $this->docQueries()->find($this->docScope(), $origLegalRef));
        self::assertSame(0, $this->value("SELECT open_minor FROM billium_invoice WHERE invoice_ref='inv-bundle'"));
        self::assertSame(0, $this->rows('billium_outbox_event', "type LIKE 'payment.%'"));
        self::assertSame(0, $this->rows('billium_outbox_command'));

        // Over-credit is refused both by service and DB constraint trigger.
        $over = $this->correctionService()->issueCreditNote(new IssueDocumentCreditNote(
            commandKey: 'cmd-cn-over',
            scope: $this->account(),
            correctionRef: 'corr-cn-over',
            creditNoteRef: 'cn-over',
            originalLegalDocumentRef: $origLegalRef,
            invoiceRef: 'inv-bundle',
            mode: CreditNoteMode::OpenOnly,
            lines: [new CreditNoteLineRequest('line-1', '0.01', 1)],
            taxPolicy: $policy,
            reason: 'overcredit_attempt',
        ));
        self::assertFalse($over->accepted);
        self::assertSame(ProblemCode::RefundCapacityExceeded, $over->problem);
        self::assertSame(2, $this->rows('billium_document_correction'));

        // Cross-seller & unknown line refused before writes.
        $this->callers->as('seller-b');
        try {
            $this->correctionService()->issueCreditNote(new IssueDocumentCreditNote(
                commandKey: 'cmd-cn-cross',
                scope: $this->account(),
                correctionRef: 'corr-cn-cross',
                creditNoteRef: 'cn-cross',
                originalLegalDocumentRef: $origLegalRef,
                invoiceRef: 'inv-bundle',
                mode: CreditNoteMode::OpenOnly,
                lines: [new CreditNoteLineRequest('line-1', '0.1', 10)],
                taxPolicy: $policy,
                reason: 'cross_seller_attempt',
            ));
            self::fail('Cross-seller credit note must fail');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::AuthDenied, $error->problem);
        }
        $this->callers->as('seller-a');

        try {
            $this->correctionService()->issueCreditNote(new IssueDocumentCreditNote(
                commandKey: 'cmd-cn-badline',
                scope: $this->account(),
                correctionRef: 'corr-cn-badline',
                creditNoteRef: 'cn-badline',
                originalLegalDocumentRef: $origLegalRef,
                invoiceRef: 'inv-bundle',
                mode: CreditNoteMode::OpenOnly,
                lines: [new CreditNoteLineRequest('nonexistent-line', '1', 10)],
                taxPolicy: $policy,
                reason: 'bad_line',
            ));
            self::fail('Unknown original line must fail');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
        }

        // Direct SQL DB backstop prevents appending credit line exceeding original_basis_gross_minor.
        $ownerPdo = TestEnvironment::pdo(owner: true);
        $corrRowId = (int) $this->value("SELECT id FROM billium_document_correction WHERE correction_ref='corr-cn-2'");
        try {
            $ownerPdo->exec(sprintf(
                "INSERT INTO public.billium_document_credit_note_line (seller_ref, environment, credit_note_id, original_legal_document_id, original_invoice_line_id, original_obligation_ref, original_component_ref, original_tax_snapshot_ref, original_rounding_policy_version, credited_quantity, net_minor, tax_minor, gross_minor, currency, cumulative_net_minor, cumulative_tax_minor, cumulative_gross_minor, original_basis_net_minor, original_basis_tax_minor, original_basis_gross_minor, created_at) VALUES ('seller-a','test',%d,%d,'line-1','ob-b1','comp-a','tsnap-bundle','half_up/1','1',1,0,1,'EUR',1002,190,1192,1001,190,1191,now())",
                $corrRowId,
                $origViewBefore->id,
            ));
            self::fail('DB check constraint must reject cumulative credit exceeding original_basis_gross_minor');
        } catch (PDOException $exception) {
            self::assertSame('23514', $exception->errorInfo[0] ?? null);
        }
    }

    public function test_paid_invoice_reverse_allocations_positive_correction_void_reissue_and_write_off(): void
    {
        $this->registerCompleteProfile();
        $this->registerSeries('INV', '2026', SeriesPeriodPolicy::Yearly, DocumentType::Invoice);
        $this->registerSeries('CORR', '2026', SeriesPeriodPolicy::Yearly, DocumentType::Correction);

        // Issue a paid invoice (10000 RUB = 8333 net + 1667 tax) and allocate a captured payment of 10000 RUB.
        $finPaid = $this->prepareFinalizedInvoiceBasis(suffix: 'paid1', netMinor: 8333, taxMinor: 1667, currency: 'RUB');
        $issued = $this->issuer()->issueInvoiceDocument($this->issueCommand('cmd-paid1', 'paid1', $finPaid->approvedSnapshotDigest ?? ''));
        $origRef = (string) ($issued->body['legalDocumentRef'] ?? '');
        $this->payWith('payment-paid1', 10000);
        $this->allocateFrom('alloc-paid1', 'ob-paid1', '100.00', 'payment-paid1');
        self::assertSame('paid', $this->invoiceStatus('inv-paid1'));

        // Current-rate tax correction policy (reverse_allocations) releases live allocation without triggering PSP refund.
        $currentRatePolicy = new DocumentTaxCorrectionPolicy(
            policyRef: 'pol-current',
            policyVersion: 'v2',
            treatment: TaxCorrectionTreatment::CurrentLegalRate,
            selectedRateDecimal: '0.20',
            taxPoint: '2026-11-19',
            roundingPolicyVersion: 'half_up/1',
            inputDigest: self::digest('x'),
            outputDigest: self::digest('y'),
            resolverVersion: '2.0',
        );
        $cnPaid = $this->correctionService()->issueCreditNote(new IssueDocumentCreditNote(
            commandKey: 'cmd-cn-paid1',
            scope: $this->account(),
            correctionRef: 'corr-cn-paid1',
            creditNoteRef: 'cn-paid1',
            originalLegalDocumentRef: $origRef,
            invoiceRef: 'inv-paid1',
            mode: CreditNoteMode::ReverseAllocations,
            lines: [new CreditNoteLineRequest('line-1', '1', 5000)],
            taxPolicy: $currentRatePolicy,
            reason: 'overpayment_after_service_reduction',
            issueLegalDocument: false,
        ));
        self::assertTrue($cnPaid->accepted);
        self::assertSame(5000, $this->value("SELECT credited_minor FROM billium_invoice WHERE invoice_ref='inv-paid1'"));
        self::assertSame(5000, $this->value("SELECT allocated_minor FROM billium_payment WHERE payment_ref='payment-paid1'"));
        self::assertSame(0, $this->value("SELECT refunded_minor FROM billium_payment WHERE payment_ref='payment-paid1'"));
        self::assertSame(0, $this->rows('billium_outbox_event', "type LIKE 'payment.%'"));
        self::assertSame(0, $this->rows('billium_outbox_command'));

        // Unsupported / missing tax correction policy is rejected before booking.
        try {
            $this->correctionService()->issueCreditNote(new IssueDocumentCreditNote(
                commandKey: 'cmd-cn-badpol',
                scope: $this->account(),
                correctionRef: 'corr-cn-badpol',
                creditNoteRef: 'cn-badpol',
                originalLegalDocumentRef: $origRef,
                invoiceRef: 'inv-paid1',
                mode: CreditNoteMode::OpenOnly,
                lines: [new CreditNoteLineRequest('line-1', '0.1', 100)],
                taxPolicy: new DocumentTaxCorrectionPolicy(
                    policyRef: '',
                    policyVersion: 'v1',
                    treatment: TaxCorrectionTreatment::OriginalRate,
                    selectedRateDecimal: '0.20',
                    taxPoint: '2026-11-19',
                    roundingPolicyVersion: 'half_up/1',
                    inputDigest: self::digest('x'),
                    outputDigest: self::digest('y'),
                    resolverVersion: '1.0',
                ),
                reason: 'missing_policy_ref',
            ));
            self::fail('Missing tax correction policy must be refused');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
        }

        // Positive correction creates a NEW linked obligation and optional correction document without touching frozen original.
        $finCorr = $this->finalizations()->createDraft(new CreateDocumentDraft(
            scope: $this->docScope(),
            finalizationRef: 'fin-corr-pos',
            documentType: DocumentType::Correction,
            economicRef: 'ob-corr-pos',
            basisRevision: 1,
            basisDigest: self::digest('p'),
            profileRef: 'prof-default',
            profileVersion: 1,
            currency: 'RUB',
            currencyExponent: 2,
            convention: TaxConvention::Exclusive,
            roundingMode: 'half_up',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [new DocumentLineBasis('c1', 'ob-corr-pos', 'comp-1', '1', '10.00', 1000, 0, 'VAT20', '0.20')],
            buyerSnapshot: ['legalName' => 'Buyer Corp'],
            termsSnapshot: [],
        ));
        $stCorr = $this->finalizations()->startFinalization($this->docScope(), 'fin-corr-pos');
        $rdCorr = $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
            scope: $this->docScope(),
            finalizationRef: 'fin-corr-pos',
            finalizationRevision: $stCorr->finalizationRevision,
            inputDigest: $stCorr->inputDigest,
            snapshot: new DocumentTaxSnapshot(
                taxSnapshotRef: 'tsnap-corr',
                taxPolicyVersion: 'v1',
                taxPoint: '2026-11-19',
                convention: TaxConvention::Exclusive,
                currency: 'RUB',
                currencyExponent: 2,
                subtotalMinor: 1000,
                taxMinor: 200,
                totalMinor: 1200,
                lines: [new DocumentTaxLineSnapshot('c1', 'VAT20', '0.20', 1000, 200, 1200)],
                inputDigest: $stCorr->inputDigest,
                evidenceDigest: self::digest('z'),
                resolver: 'corr-port',
                resolverVersion: '1.0',
            ),
        ))->finalization;

        $posOutcome = $this->correctionService()->issuePositiveCorrection(new IssuePositiveDocumentCorrection(
            commandKey: 'cmd-pos-1',
            scope: $this->account(),
            correctionRef: 'corr-pos-1',
            originalLegalDocumentRef: $origRef,
            obligationRef: 'ob-corr-pos',
            customerRef: 'cust-paid1',
            componentRef: 'comp-1',
            parentOccurrenceRef: 'occ-1',
            correctionRevision: 1,
            deltaNetMinor: 1000,
            deltaTaxMinor: 200,
            currency: 'RUB',
            taxPolicy: $currentRatePolicy,
            reason: 'late_usage_positive_delta',
            issueLegalDocument: true,
            seriesRef: 'CORR',
            periodKey: '2026',
            finalizationRef: 'fin-corr-pos',
            approvedSnapshotDigest: $rdCorr->approvedSnapshotDigest,
        ));
        self::assertTrue($posOutcome->accepted);
        self::assertSame('CORR-2026-000001', $posOutcome->body['resultingLegalNumber'] ?? null);
        self::assertSame(1200, $this->value("SELECT amount_due_minor FROM billium_obligation WHERE obligation_ref='ob-corr-pos'"));
        self::assertSame(10000, $this->value("SELECT amount_due_minor FROM billium_obligation WHERE obligation_ref='ob-paid1'"));

        // Write-off is distinct and leaves original legal snapshot untouched.
        $writeOff = $this->correctionService()->recordWriteOff(new RecordDocumentWriteOff(
            commandKey: 'cmd-wo-1',
            scope: $this->docScope(),
            correctionRef: 'corr-wo-1',
            originalLegalDocumentRef: $origRef,
            amountMinor: 500,
            currency: 'RUB',
            reason: 'small_balance_write_off',
        ));
        self::assertTrue($writeOff->accepted);
        self::assertSame(CorrectionKind::WriteOff, $this->correctionService()->findCorrection($this->docScope(), 'corr-wo-1')?->correctionKind);

        // Void & Reissue voids original (#1), never reuses number #1, and allocates #2 linked to original.
        $finReissue = $this->finalizations()->createDraft(new CreateDocumentDraft(
            scope: $this->docScope(),
            finalizationRef: 'fin-reissue',
            documentType: DocumentType::Invoice,
            economicRef: 'inv-paid1',
            basisRevision: 2,
            basisDigest: self::digest('r'),
            profileRef: 'prof-default',
            profileVersion: 1,
            currency: 'RUB',
            currencyExponent: 2,
            convention: TaxConvention::Exclusive,
            roundingMode: 'half_up',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [new DocumentLineBasis('line-1', 'ob-paid1', 'comp-1', '1', '83.33', 8333, 0, 'VAT20', '0.20')],
            buyerSnapshot: ['legalName' => 'Corrected Buyer Legal Name'],
            termsSnapshot: [],
        ));
        $stRe = $this->finalizations()->startFinalization($this->docScope(), 'fin-reissue');
        $rdRe = $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
            scope: $this->docScope(),
            finalizationRef: 'fin-reissue',
            finalizationRevision: $stRe->finalizationRevision,
            inputDigest: $stRe->inputDigest,
            snapshot: new DocumentTaxSnapshot(
                taxSnapshotRef: 'tsnap-re',
                taxPolicyVersion: 'v1',
                taxPoint: '2026-11-19',
                convention: TaxConvention::Exclusive,
                currency: 'RUB',
                currencyExponent: 2,
                subtotalMinor: 8333,
                taxMinor: 1667,
                totalMinor: 10000,
                lines: [new DocumentTaxLineSnapshot('line-1', 'VAT20', '0.20', 8333, 1667, 10000)],
                inputDigest: $stRe->inputDigest,
                evidenceDigest: self::digest('r'),
                resolver: 'reissue-port',
                resolverVersion: '1.0',
            ),
        ))->finalization;

        $reissued = $this->correctionService()->voidAndReissue(new VoidAndReissueLegalDocument(
            commandKey: 'cmd-vr-1',
            scope: $this->docScope(),
            correctionRef: 'corr-vr-1',
            originalLegalDocumentRef: $origRef,
            newFinalizationRef: 'fin-reissue',
            approvedSnapshotDigest: $rdRe->approvedSnapshotDigest ?? '',
            seriesRef: 'INV',
            periodKey: '2026',
            reason: 'buyer_legal_name_fix',
        ));
        self::assertTrue($reissued->accepted);
        self::assertSame('INV-2026-000002', $reissued->body['resultingLegalNumber'] ?? null);
        self::assertSame(LegalDocumentStatus::Voided, $this->docQueries()->find($this->docScope(), $origRef)?->status);
        $reissuedRef = (string) ($reissued->body['resultingLegalDocumentRef'] ?? '');
        $reissuedView = $this->docQueries()->find($this->docScope(), $reissuedRef);
        self::assertNotNull($reissuedView);
        self::assertSame($origRef, $reissuedView->originalLegalDocumentRef);
        self::assertSame(2, $reissuedView->documentRevision);
    }
}
