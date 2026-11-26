<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\P6\Support\DocumentsTestCase;
use Billium\Core\Tests\TestEnvironment;
use Billium\Documents\Contracts\DocumentsNumberingParticipant;
use Billium\Documents\Contracts\DocumentTaxArithmetic;
use Billium\Documents\Data\AllocateLegalNumberCommand;
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
use Billium\Documents\Internal\DocumentReceipt;
use Billium\Documents\Internal\Tax\PartialCreditAllocator;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\CreditNoteMode;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Invoices\IssueInvoice;
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
        $posLegalRef = (string) ($posOutcome->body['resultingLegalDocumentRef'] ?? '');
        $posLegalView = $this->docQueries()->find($this->docScope(), $posLegalRef);
        self::assertNotNull($posLegalView);
        self::assertSame('Buyer Corp', $posLegalView->buyerSnapshot['legalName'] ?? null);
        self::assertSame('tsnap-corr', $posLegalView->taxSnapshot['taxSnapshotRef'] ?? null);
        self::assertSame('c1', $posLegalView->linesSnapshot[0]['lineRef'] ?? null);
        self::assertSame(1200, $posLegalView->linesSnapshot[0]['grossMinor'] ?? null);
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
            lines: [new DocumentLineBasis('line-1', 'ob-paid1', 'comp-1', '1', '83.33', 8333, 0, 'VAT20', '0.20', 'Corrected service description')],
            buyerSnapshot: ['legalName' => 'Corrected Buyer Legal Name'],
            termsSnapshot: ['paymentTerm' => 'NET45'],
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
        self::assertSame('Corrected Buyer Legal Name', $reissuedView->buyerSnapshot['legalName'] ?? null);
        self::assertSame('NET45', $reissuedView->termsSnapshot['paymentTerm'] ?? null);
        self::assertSame('Corrected service description', $reissuedView->linesSnapshot[0]['description'] ?? null);
        self::assertSame('tsnap-re', $reissuedView->taxSnapshot['taxSnapshotRef'] ?? null);
    }

    public function test_credit_note_honors_frozen_half_even_and_per_line_heterogeneous_rounding_without_fallback(): void
    {
        $profile = $this->registerCompleteProfile();
        $invSeries = $this->registerSeries('INV', '2026', SeriesPeriodPolicy::Yearly, DocumentType::Invoice);
        $originalRatePolicy = new DocumentTaxCorrectionPolicy(
            policyRef: 'pol-orig-f5',
            policyVersion: 'v1',
            treatment: TaxCorrectionTreatment::OriginalRate,
            selectedRateDecimal: '0.075',
            taxPoint: '2026-11-19',
            roundingPolicyVersion: 'half_up/1',
            inputDigest: self::digest('1'),
            outputDigest: self::digest('2'),
            resolverVersion: '1.0',
        );

        // 1. Service-level half_even persisted document with NO termsSnapshot.roundingPolicyVersion (333/25/358, credit 179 -> tax 12, NOT 13).
        $this->ledger()->recordObligation(new RecordObligation('rec-he1', $this->account(), 'ob-he1', 'basis-he1', 'cust-he', 'comp-he', new Money(358, Currency::of('EUR')), self::digest('a')));
        $this->ledger()->createInvoice(new CreateInvoice(
            commandKey: 'create-inv-he1',
            scope: $this->account(),
            invoiceRef: 'inv-he1',
            customerRef: 'cust-he',
            lines: [new InvoiceLineInput('ob-he1', 25, ['line' => 'line-1'])],
        ));
        $this->finalizations()->createDraft(new CreateDocumentDraft(
            scope: $this->docScope(),
            finalizationRef: 'fin-he1',
            documentType: DocumentType::Invoice,
            economicRef: 'inv-he1',
            basisRevision: 1,
            basisDigest: self::digest('b'),
            profileRef: 'prof-default',
            profileVersion: 1,
            currency: 'EUR',
            currencyExponent: 2,
            convention: TaxConvention::Exclusive,
            roundingMode: 'half_even',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [new DocumentLineBasis('line-1', 'ob-he1', 'comp-he', '1', '3.33', 333, 0, 'VAT75', '0.075')],
            buyerSnapshot: ['legalName' => 'Half Even Buyer'],
            termsSnapshot: [],
        ));
        $startedHe = $this->finalizations()->startFinalization($this->docScope(), 'fin-he1');
        $readyHe = $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
            scope: $this->docScope(),
            finalizationRef: 'fin-he1',
            finalizationRevision: $startedHe->finalizationRevision,
            inputDigest: $startedHe->inputDigest,
            snapshot: new DocumentTaxSnapshot(
                taxSnapshotRef: 'tsnap-he1',
                taxPolicyVersion: 'v1',
                taxPoint: '2026-11-19',
                convention: TaxConvention::Exclusive,
                currency: 'EUR',
                currencyExponent: 2,
                subtotalMinor: 333,
                taxMinor: 25,
                totalMinor: 358,
                lines: [new DocumentTaxLineSnapshot('line-1', 'VAT75', '0.075', 333, 25, 358)],
                inputDigest: $startedHe->inputDigest,
                evidenceDigest: self::digest('c'),
                resolver: 'he-resolver',
                resolverVersion: '1.0',
            ),
        ))->finalization;

        $issuedHe = $this->issuer()->issueInvoiceDocument(new IssueLegalDocument(
            commandKey: 'issue-he1',
            scope: $this->account(),
            invoiceRef: 'inv-he1',
            economicRef: 'inv-he1',
            documentRevision: 1,
            finalizationRef: 'fin-he1',
            approvedSnapshotDigest: $readyHe->approvedSnapshotDigest ?? '',
            seriesRef: 'INV',
            periodKey: '2026',
        ));
        $heLegalRef = (string) ($issuedHe->body['legalDocumentRef'] ?? '');

        $cnHe = $this->correctionService()->issueCreditNote(new IssueDocumentCreditNote(
            commandKey: 'cmd-cn-he1',
            scope: $this->account(),
            correctionRef: 'corr-cn-he1',
            creditNoteRef: 'cn-he1',
            originalLegalDocumentRef: $heLegalRef,
            invoiceRef: 'inv-he1',
            mode: CreditNoteMode::OpenOnly,
            lines: [new CreditNoteLineRequest('line-1', '0.5', 179)],
            taxPolicy: $originalRatePolicy,
            reason: 'half_even_credit',
            issueLegalDocument: false,
        ));
        self::assertTrue($cnHe->accepted);
        $heLines = $this->correctionService()->creditNoteLines($this->docScope(), 'corr-cn-he1');
        self::assertCount(1, $heLines);
        self::assertSame(167, $heLines[0]->netMinor);
        self::assertSame(12, $heLines[0]->taxMinor);
        self::assertSame(179, $heLines[0]->grossMinor);
        self::assertSame('half_even/1', $heLines[0]->originalRoundingPolicyVersion);

        // 2. End-to-end heterogeneous rounding through LegalDocumentIssuer::issueInvoiceDocument() + two sequential partial credits.
        $this->ledger()->recordObligation(new RecordObligation('rec-het1', $this->account(), 'ob-het1', 'basis-het1', 'cust-het', 'comp-a', new Money(1191, Currency::of('EUR')), self::digest('d')));
        $this->ledger()->recordObligation(new RecordObligation('rec-het2', $this->account(), 'ob-het2', 'basis-het2', 'cust-het', 'comp-b', new Money(358, Currency::of('EUR')), self::digest('d')));
        $this->ledger()->createInvoice(new CreateInvoice(
            commandKey: 'create-inv-het',
            scope: $this->account(),
            invoiceRef: 'inv-het',
            customerRef: 'cust-het',
            lines: [
                new InvoiceLineInput('ob-het1', 190, ['line' => 'line-1']),
                new InvoiceLineInput('ob-het2', 25, ['line' => 'line-2']),
            ],
        ));
        $this->finalizations()->createDraft(new CreateDocumentDraft(
            scope: $this->docScope(),
            finalizationRef: 'fin-het',
            documentType: DocumentType::Invoice,
            economicRef: 'inv-het',
            basisRevision: 1,
            basisDigest: self::digest('e'),
            profileRef: 'prof-default',
            profileVersion: 1,
            currency: 'EUR',
            currencyExponent: 2,
            convention: TaxConvention::Exclusive,
            roundingMode: 'half_up',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [
                new DocumentLineBasis('line-1', 'ob-het1', 'comp-a', '1', '10.01', 1001, 0, 'VAT19', '0.19'),
                new DocumentLineBasis('line-2', 'ob-het2', 'comp-b', '1', '3.33', 333, 0, 'VAT75', '0.075'),
            ],
            buyerSnapshot: ['legalName' => 'Heterogeneous Buyer'],
            termsSnapshot: [
                'lineRoundingPolicies' => [
                    'line-1' => 'half_up/1',
                    'line-2' => 'half_even/1',
                ],
            ],
        ));
        $startedHet = $this->finalizations()->startFinalization($this->docScope(), 'fin-het');
        $readyHet = $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
            scope: $this->docScope(),
            finalizationRef: 'fin-het',
            finalizationRevision: $startedHet->finalizationRevision,
            inputDigest: $startedHet->inputDigest,
            snapshot: new DocumentTaxSnapshot(
                taxSnapshotRef: 'tsnap-het',
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
                inputDigest: $startedHet->inputDigest,
                evidenceDigest: self::digest('f'),
                resolver: 'het-resolver',
                resolverVersion: '1.0',
            ),
        ))->finalization;

        $issuedHet = $this->issuer()->issueInvoiceDocument(new IssueLegalDocument(
            commandKey: 'issue-het',
            scope: $this->account(),
            invoiceRef: 'inv-het',
            economicRef: 'inv-het',
            documentRevision: 1,
            finalizationRef: 'fin-het',
            approvedSnapshotDigest: $readyHet->approvedSnapshotDigest ?? '',
            seriesRef: 'INV',
            periodKey: '2026',
        ));
        $hetLegalRef = (string) ($issuedHet->body['legalDocumentRef'] ?? '');
        $hetDocView = $this->docQueries()->find($this->docScope(), $hetLegalRef);
        self::assertNotNull($hetDocView);
        self::assertSame('half_up/1', $hetDocView->linesSnapshot[0]['roundingPolicyVersion'] ?? null);
        self::assertSame('half_even/1', $hetDocView->linesSnapshot[1]['roundingPolicyVersion'] ?? null);

        // Step 1 partial credit: line-1 (600) -> 504/96/600 (half_up/1); line-2 (179) -> 167/12/179 (strictly half_even/1).
        $cnHet1 = $this->correctionService()->issueCreditNote(new IssueDocumentCreditNote(
            commandKey: 'cmd-cn-het-1',
            scope: $this->account(),
            correctionRef: 'corr-cn-het-1',
            creditNoteRef: 'cn-het-1',
            originalLegalDocumentRef: $hetLegalRef,
            invoiceRef: 'inv-het',
            mode: CreditNoteMode::OpenOnly,
            lines: [
                new CreditNoteLineRequest('line-1', '0.5', 600),
                new CreditNoteLineRequest('line-2', '0.5', 179),
            ],
            taxPolicy: $originalRatePolicy,
            reason: 'heterogeneous_line_rounding_step_1',
            issueLegalDocument: false,
        ));
        self::assertTrue($cnHet1->accepted);
        $hetLines1 = $this->correctionService()->creditNoteLines($this->docScope(), 'corr-cn-het-1');
        self::assertCount(2, $hetLines1);
        self::assertSame('line-1', $hetLines1[0]->originalInvoiceLineId);
        self::assertSame(504, $hetLines1[0]->netMinor);
        self::assertSame(96, $hetLines1[0]->taxMinor);
        self::assertSame(600, $hetLines1[0]->grossMinor);
        self::assertSame('half_up/1', $hetLines1[0]->originalRoundingPolicyVersion);
        self::assertSame('line-2', $hetLines1[1]->originalInvoiceLineId);
        self::assertSame(167, $hetLines1[1]->netMinor);
        self::assertSame(12, $hetLines1[1]->taxMinor);
        self::assertSame(179, $hetLines1[1]->grossMinor);
        self::assertSame('half_even/1', $hetLines1[1]->originalRoundingPolicyVersion);

        // Step 2 partial credit: line-1 (591) -> 497/94/591; line-2 (179) -> 166/13/179; exact full-basis conservation and open_minor === 0.
        $cnHet2 = $this->correctionService()->issueCreditNote(new IssueDocumentCreditNote(
            commandKey: 'cmd-cn-het-2',
            scope: $this->account(),
            correctionRef: 'corr-cn-het-2',
            creditNoteRef: 'cn-het-2',
            originalLegalDocumentRef: $hetLegalRef,
            invoiceRef: 'inv-het',
            mode: CreditNoteMode::OpenOnly,
            lines: [
                new CreditNoteLineRequest('line-1', '0.5', 591),
                new CreditNoteLineRequest('line-2', '0.5', 179),
            ],
            taxPolicy: $originalRatePolicy,
            reason: 'heterogeneous_line_rounding_step_2',
            issueLegalDocument: false,
        ));
        self::assertTrue($cnHet2->accepted);
        $hetLines2 = $this->correctionService()->creditNoteLines($this->docScope(), 'corr-cn-het-2');
        self::assertCount(2, $hetLines2);
        self::assertSame('line-1', $hetLines2[0]->originalInvoiceLineId);
        self::assertSame(497, $hetLines2[0]->netMinor);
        self::assertSame(94, $hetLines2[0]->taxMinor);
        self::assertSame(591, $hetLines2[0]->grossMinor);
        self::assertSame(1001, $hetLines2[0]->cumulativeNetMinor);
        self::assertSame(190, $hetLines2[0]->cumulativeTaxMinor);
        self::assertSame(1191, $hetLines2[0]->cumulativeGrossMinor);
        self::assertSame('half_up/1', $hetLines2[0]->originalRoundingPolicyVersion);
        self::assertSame('line-2', $hetLines2[1]->originalInvoiceLineId);
        self::assertSame(166, $hetLines2[1]->netMinor);
        self::assertSame(13, $hetLines2[1]->taxMinor);
        self::assertSame(179, $hetLines2[1]->grossMinor);
        self::assertSame(333, $hetLines2[1]->cumulativeNetMinor);
        self::assertSame(25, $hetLines2[1]->cumulativeTaxMinor);
        self::assertSame(358, $hetLines2[1]->cumulativeGrossMinor);
        self::assertSame('half_even/1', $hetLines2[1]->originalRoundingPolicyVersion);
        self::assertSame(0, $this->value("SELECT open_minor FROM billium_invoice WHERE invoice_ref='inv-het'"));

        $runner = $this->application()->make(OperationRunner::class);
        $ledgerParticipants = $this->application()->make(LedgerParticipants::class);
        $numbering = $this->application()->make(DocumentsNumberingParticipant::class);

        // 3. Missing frozen rounding policy on line, taxSnapshot, and termsSnapshot MUST fail (never fallback to half_up).
        $this->ledger()->recordObligation(new RecordObligation('rec-nr1', $this->account(), 'ob-nr1', 'basis-nr1', 'cust-nr', 'comp-nr', new Money(358, Currency::of('EUR')), self::digest('3')));
        $this->ledger()->createInvoice(new CreateInvoice(
            commandKey: 'create-inv-nr1',
            scope: $this->account(),
            invoiceRef: 'inv-nr1',
            customerRef: 'cust-nr',
            lines: [new InvoiceLineInput('ob-nr1', 25, ['line' => 'line-1'])],
        ));
        $runner->run(
            spec: new OperationSpec(
                commandKind: 'documents.test.persist_missing_rounding.v1',
                sellerRef: $this->docScope()->sellerRef,
                environment: $this->docScope()->environment,
                mode: FinancialMode::Active,
                operationClass: ModuleOperationClass::NewIntent,
                modules: ['platform', 'ledger', 'documents'],
                commitKind: 'documents.legal_document.issued',
            ),
            body: function (TransactionContext $context) use ($ledgerParticipants, $numbering, $invSeries, $profile, $readyHe): DocumentReceipt {
                $ledgerParticipants->issueInvoice($context, new IssueInvoice('issue-nr1-ledger', $this->account(), 'inv-nr1'), 'ldoc-nr-1');
                $numbering->allocateAndRecord($context, new AllocateLegalNumberCommand(
                    legalDocumentRef: 'ldoc-nr-1',
                    documentType: DocumentType::Invoice,
                    economicRef: 'inv-nr1',
                    documentRevision: 1,
                    seriesId: $invSeries->id,
                    profileId: $profile->id,
                    profileVersion: $profile->version,
                    finalizationId: $readyHe->id,
                    basisRevision: 1,
                    basisDigest: $readyHe->basisDigest,
                    approvedSnapshotDigest: $readyHe->approvedSnapshotDigest ?? '',
                    currency: 'EUR',
                    subtotalMinor: 333,
                    taxMinor: 25,
                    totalMinor: 358,
                    sellerSnapshot: $profile->sellerSnapshot,
                    buyerSnapshot: ['legalName' => 'No Rounding Buyer'],
                    taxSnapshot: ['taxSnapshotRef' => 'tsnap-nr'],
                    termsSnapshot: [],
                    linesSnapshot: [[
                        'lineRef' => 'line-1',
                        'obligationRef' => 'ob-nr1',
                        'componentRef' => 'comp-nr',
                        'netMinor' => 333,
                        'taxMinor' => 25,
                        'grossMinor' => 358,
                    ]],
                    displayMetadata: [],
                    receiptRef: 'legal-nr-1',
                ));

                return new DocumentReceipt('documents.test.persisted', ['legalDocumentRef' => 'ldoc-nr-1']);
            },
        );

        try {
            $this->correctionService()->issueCreditNote(new IssueDocumentCreditNote(
                commandKey: 'cmd-cn-nr1',
                scope: $this->account(),
                correctionRef: 'corr-cn-nr1',
                creditNoteRef: 'cn-nr1',
                originalLegalDocumentRef: 'ldoc-nr-1',
                invoiceRef: 'inv-nr1',
                mode: CreditNoteMode::OpenOnly,
                lines: [new CreditNoteLineRequest('line-1', '0.5', 179)],
                taxPolicy: $originalRatePolicy,
                reason: 'missing_rounding_must_fail',
                issueLegalDocument: false,
            ));
            self::fail('Credit note on document missing roundingPolicyVersion must fail');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            self::assertStringContainsString('billium.documents.rounding_policy_missing', $error->getMessage());
        }
    }

    public function test_void_and_reissue_rejects_mismatched_basis_or_totals_before_voiding(): void
    {
        $this->registerCompleteProfile();
        $this->registerSeries('INV', '2026', SeriesPeriodPolicy::Yearly, DocumentType::Invoice);

        // Original 10000 RUB invoice (8333 net + 1667 tax).
        $finOrig = $this->prepareFinalizedInvoiceBasis(suffix: 'f6orig', netMinor: 8333, taxMinor: 1667, currency: 'RUB');
        $issued = $this->issuer()->issueInvoiceDocument($this->issueCommand('cmd-f6-orig', 'f6orig', $finOrig->approvedSnapshotDigest ?? ''));
        $origRef = (string) ($issued->body['legalDocumentRef'] ?? '');

        // Ready finalization totaling 12000 RUB (10000 net + 2000 tax) for the same seller and economicRef.
        $this->finalizations()->createDraft(new CreateDocumentDraft(
            scope: $this->docScope(),
            finalizationRef: 'fin-f6-12000',
            documentType: DocumentType::Invoice,
            economicRef: 'inv-f6orig',
            basisRevision: 2,
            basisDigest: self::digest('4'),
            profileRef: 'prof-default',
            profileVersion: 1,
            currency: 'RUB',
            currencyExponent: 2,
            convention: TaxConvention::Exclusive,
            roundingMode: 'half_up',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [new DocumentLineBasis('line-1', 'ob-f6orig', 'comp-1', '1', '100.00', 10000, 0, 'VAT20', '0.20')],
            buyerSnapshot: ['legalName' => 'Buyer Corp Fixed'],
            termsSnapshot: [],
        ));
        $st12000 = $this->finalizations()->startFinalization($this->docScope(), 'fin-f6-12000');
        $rd12000 = $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
            scope: $this->docScope(),
            finalizationRef: 'fin-f6-12000',
            finalizationRevision: $st12000->finalizationRevision,
            inputDigest: $st12000->inputDigest,
            snapshot: new DocumentTaxSnapshot(
                taxSnapshotRef: 'tsnap-f6-12000',
                taxPolicyVersion: 'v1',
                taxPoint: '2026-11-19',
                convention: TaxConvention::Exclusive,
                currency: 'RUB',
                currencyExponent: 2,
                subtotalMinor: 10000,
                taxMinor: 2000,
                totalMinor: 12000,
                lines: [new DocumentTaxLineSnapshot('line-1', 'VAT20', '0.20', 10000, 2000, 12000)],
                inputDigest: $st12000->inputDigest,
                evidenceDigest: self::digest('5'),
                resolver: 'f6-port',
                resolverVersion: '1.0',
            ),
        ))->finalization;

        $legalCountBefore = $this->rows('billium_document_legal');
        $journalCountBefore = $this->rows('billium_document_number_journal');
        $nextNumberBefore = $this->value("SELECT next_number FROM billium_document_series WHERE series_ref='INV' AND period_key='2026'");

        try {
            $this->correctionService()->voidAndReissue(new VoidAndReissueLegalDocument(
                commandKey: 'cmd-f6-mismatch-total',
                scope: $this->docScope(),
                correctionRef: 'corr-f6-mismatch-total',
                originalLegalDocumentRef: $origRef,
                newFinalizationRef: 'fin-f6-12000',
                approvedSnapshotDigest: $rd12000->approvedSnapshotDigest ?? '',
                seriesRef: 'INV',
                periodKey: '2026',
                reason: 'attempt_reissue_with_higher_total',
            ));
            self::fail('Void and reissue with different total (12000 vs 10000) must be refused');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
        }

        // Original remains Issued (NOT Voided) and 0 numbers were allocated.
        self::assertSame(LegalDocumentStatus::Issued, $this->docQueries()->find($this->docScope(), $origRef)?->status);
        self::assertSame($legalCountBefore, $this->rows('billium_document_legal'));
        self::assertSame($journalCountBefore, $this->rows('billium_document_number_journal'));
        self::assertSame($nextNumberBefore, $this->value("SELECT next_number FROM billium_document_series WHERE series_ref='INV' AND period_key='2026'"));

        // Ready finalization with matching 10000 total but different line obligationRef MUST also be refused.
        $this->finalizations()->createDraft(new CreateDocumentDraft(
            scope: $this->docScope(),
            finalizationRef: 'fin-f6-badline',
            documentType: DocumentType::Invoice,
            economicRef: 'inv-f6orig',
            basisRevision: 3,
            basisDigest: self::digest('6'),
            profileRef: 'prof-default',
            profileVersion: 1,
            currency: 'RUB',
            currencyExponent: 2,
            convention: TaxConvention::Exclusive,
            roundingMode: 'half_up',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [new DocumentLineBasis('line-1', 'ob-other-obligation', 'comp-1', '1', '83.33', 8333, 0, 'VAT20', '0.20')],
            buyerSnapshot: ['legalName' => 'Buyer Corp Fixed'],
            termsSnapshot: [],
        ));
        $stBadLine = $this->finalizations()->startFinalization($this->docScope(), 'fin-f6-badline');
        $rdBadLine = $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
            scope: $this->docScope(),
            finalizationRef: 'fin-f6-badline',
            finalizationRevision: $stBadLine->finalizationRevision,
            inputDigest: $stBadLine->inputDigest,
            snapshot: new DocumentTaxSnapshot(
                taxSnapshotRef: 'tsnap-f6-badline',
                taxPolicyVersion: 'v1',
                taxPoint: '2026-11-19',
                convention: TaxConvention::Exclusive,
                currency: 'RUB',
                currencyExponent: 2,
                subtotalMinor: 8333,
                taxMinor: 1667,
                totalMinor: 10000,
                lines: [new DocumentTaxLineSnapshot('line-1', 'VAT20', '0.20', 8333, 1667, 10000)],
                inputDigest: $stBadLine->inputDigest,
                evidenceDigest: self::digest('7'),
                resolver: 'f6-port',
                resolverVersion: '1.0',
            ),
        ))->finalization;

        try {
            $this->correctionService()->voidAndReissue(new VoidAndReissueLegalDocument(
                commandKey: 'cmd-f6-mismatch-line',
                scope: $this->docScope(),
                correctionRef: 'corr-f6-mismatch-line',
                originalLegalDocumentRef: $origRef,
                newFinalizationRef: 'fin-f6-badline',
                approvedSnapshotDigest: $rdBadLine->approvedSnapshotDigest ?? '',
                seriesRef: 'INV',
                periodKey: '2026',
                reason: 'attempt_reissue_with_different_obligation',
            ));
            self::fail('Void and reissue with different line obligationRef must be refused');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
        }

        self::assertSame(LegalDocumentStatus::Issued, $this->docQueries()->find($this->docScope(), $origRef)?->status);
        self::assertSame($legalCountBefore, $this->rows('billium_document_legal'));
        self::assertSame($nextNumberBefore, $this->value("SELECT next_number FROM billium_document_series WHERE series_ref='INV' AND period_key='2026'"));
    }

    public function test_positive_correction_binds_finalization_and_enforces_complete_idempotency_digest(): void
    {
        $this->registerCompleteProfile();
        $this->registerSeries('INV', '2026', SeriesPeriodPolicy::Yearly, DocumentType::Invoice);
        $this->registerSeries('CORR', '2026', SeriesPeriodPolicy::Yearly, DocumentType::Correction);

        $finOrig = $this->prepareFinalizedInvoiceBasis(suffix: 'f7orig', netMinor: 8333, taxMinor: 1667, currency: 'RUB');
        $issued = $this->issuer()->issueInvoiceDocument($this->issueCommand('cmd-f7-orig', 'f7orig', $finOrig->approvedSnapshotDigest ?? ''));
        $origRef = (string) ($issued->body['legalDocumentRef'] ?? '');

        $policy = new DocumentTaxCorrectionPolicy(
            policyRef: 'pol-f7',
            policyVersion: 'v1',
            treatment: TaxCorrectionTreatment::CurrentLegalRate,
            selectedRateDecimal: '0.20',
            taxPoint: '2026-11-19',
            roundingPolicyVersion: 'half_up/1',
            inputDigest: self::digest('8'),
            outputDigest: self::digest('9'),
            resolverVersion: '1.0',
        );

        // F7: Supplying an unrelated same-seller ready finalization totaling 10000 for a 1200 positive correction MUST refuse.
        $finUnrelated = $this->prepareFinalizedInvoiceBasis(suffix: 'f7other', netMinor: 8333, taxMinor: 1667, currency: 'RUB');
        $corrCountBefore = $this->rows('billium_document_correction');
        $legalCountBefore = $this->rows('billium_document_legal');

        try {
            $this->correctionService()->issuePositiveCorrection(new IssuePositiveDocumentCorrection(
                commandKey: 'cmd-f7-unrelated',
                scope: $this->account(),
                correctionRef: 'corr-f7-unrelated',
                originalLegalDocumentRef: $origRef,
                obligationRef: 'ob-f7-pos-bad',
                customerRef: 'cust-f7orig',
                componentRef: 'comp-1',
                parentOccurrenceRef: 'occ-f7',
                correctionRevision: 1,
                deltaNetMinor: 1000,
                deltaTaxMinor: 200,
                currency: 'RUB',
                taxPolicy: $policy,
                reason: 'unrelated_finalization_attempt',
                issueLegalDocument: true,
                seriesRef: 'CORR',
                periodKey: '2026',
                finalizationRef: $finUnrelated->finalizationRef,
                approvedSnapshotDigest: $finUnrelated->approvedSnapshotDigest,
            ));
            self::fail('Positive correction with unrelated 10000 finalization for 1200 delta must be refused');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
        }

        self::assertSame(0, $this->rows('billium_obligation', "obligation_ref = 'ob-f7-pos-bad'"));
        self::assertSame($corrCountBefore, $this->rows('billium_document_correction'));
        self::assertSame($legalCountBefore, $this->rows('billium_document_legal'));

        // F12: Ready Correction finalization with matching obligation/amounts (1000/200/1200) but mismatched componentRef ('component-B' vs 'component-A') MUST refuse.
        $this->finalizations()->createDraft(new CreateDocumentDraft(
            scope: $this->docScope(),
            finalizationRef: 'fin-f12-comp',
            documentType: DocumentType::Correction,
            economicRef: 'ob-f12-corr',
            basisRevision: 1,
            basisDigest: self::digest('c'),
            profileRef: 'prof-default',
            profileVersion: 1,
            currency: 'RUB',
            currencyExponent: 2,
            convention: TaxConvention::Exclusive,
            roundingMode: 'half_up',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [new DocumentLineBasis('c1', 'ob-f12-corr', 'component-B', '1', '10.00', 1000, 0, 'VAT20', '0.20')],
            buyerSnapshot: ['legalName' => 'Buyer Corp'],
            termsSnapshot: [],
        ));
        $stF12 = $this->finalizations()->startFinalization($this->docScope(), 'fin-f12-comp');
        $rdF12 = $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
            scope: $this->docScope(),
            finalizationRef: 'fin-f12-comp',
            finalizationRevision: $stF12->finalizationRevision,
            inputDigest: $stF12->inputDigest,
            snapshot: new DocumentTaxSnapshot(
                taxSnapshotRef: 'tsnap-f12-comp',
                taxPolicyVersion: 'v1',
                taxPoint: '2026-11-19',
                convention: TaxConvention::Exclusive,
                currency: 'RUB',
                currencyExponent: 2,
                subtotalMinor: 1000,
                taxMinor: 200,
                totalMinor: 1200,
                lines: [new DocumentTaxLineSnapshot('c1', 'VAT20', '0.20', 1000, 200, 1200)],
                inputDigest: $stF12->inputDigest,
                evidenceDigest: self::digest('d'),
                resolver: 'f12-port',
                resolverVersion: '1.0',
            ),
        ))->finalization;

        $corrNextBefore = $this->value("SELECT next_number FROM billium_document_series WHERE series_ref='CORR' AND period_key='2026'");

        try {
            $this->correctionService()->issuePositiveCorrection(new IssuePositiveDocumentCorrection(
                commandKey: 'cmd-f12-comp-mismatch',
                scope: $this->account(),
                correctionRef: 'corr-f12-comp-mismatch',
                originalLegalDocumentRef: $origRef,
                obligationRef: 'ob-f12-corr',
                customerRef: 'cust-f7orig',
                componentRef: 'component-A',
                parentOccurrenceRef: 'occ-f12',
                correctionRevision: 1,
                deltaNetMinor: 1000,
                deltaTaxMinor: 200,
                currency: 'RUB',
                taxPolicy: $policy,
                reason: 'mismatched_component_finalization',
                issueLegalDocument: true,
                seriesRef: 'CORR',
                periodKey: '2026',
                finalizationRef: 'fin-f12-comp',
                approvedSnapshotDigest: $rdF12->approvedSnapshotDigest,
            ));
            self::fail('Positive correction with mismatched componentRef (component-A vs component-B) must be refused');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
        }

        self::assertSame(0, $this->rows('billium_obligation', "obligation_ref = 'ob-f12-corr'"));
        self::assertSame($corrCountBefore, $this->rows('billium_document_correction'));
        self::assertSame($legalCountBefore, $this->rows('billium_document_legal'));
        self::assertSame($corrNextBefore, $this->value("SELECT next_number FROM billium_document_series WHERE series_ref='CORR' AND period_key='2026'"));

        // F8: Succeed with issueLegalDocument=false, then replay same commandKey with issueLegalDocument=true or mutated customerRef/componentRef -> IdempotencyConflict.
        $this->finalizations()->createDraft(new CreateDocumentDraft(
            scope: $this->docScope(),
            finalizationRef: 'fin-f8-pos',
            documentType: DocumentType::Correction,
            economicRef: 'ob-f8-pos',
            basisRevision: 1,
            basisDigest: self::digest('a'),
            profileRef: 'prof-default',
            profileVersion: 1,
            currency: 'RUB',
            currencyExponent: 2,
            convention: TaxConvention::Exclusive,
            roundingMode: 'half_up',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [new DocumentLineBasis('c1', 'ob-f8-pos', 'comp-1', '1', '10.00', 1000, 0, 'VAT20', '0.20')],
            buyerSnapshot: ['legalName' => 'Buyer Corp'],
            termsSnapshot: [],
        ));
        $stF8 = $this->finalizations()->startFinalization($this->docScope(), 'fin-f8-pos');
        $rdF8 = $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
            scope: $this->docScope(),
            finalizationRef: 'fin-f8-pos',
            finalizationRevision: $stF8->finalizationRevision,
            inputDigest: $stF8->inputDigest,
            snapshot: new DocumentTaxSnapshot(
                taxSnapshotRef: 'tsnap-f8-pos',
                taxPolicyVersion: 'v1',
                taxPoint: '2026-11-19',
                convention: TaxConvention::Exclusive,
                currency: 'RUB',
                currencyExponent: 2,
                subtotalMinor: 1000,
                taxMinor: 200,
                totalMinor: 1200,
                lines: [new DocumentTaxLineSnapshot('c1', 'VAT20', '0.20', 1000, 200, 1200)],
                inputDigest: $stF8->inputDigest,
                evidenceDigest: self::digest('b'),
                resolver: 'f8-port',
                resolverVersion: '1.0',
            ),
        ))->finalization;

        $firstOutcome = $this->correctionService()->issuePositiveCorrection(new IssuePositiveDocumentCorrection(
            commandKey: 'cmd-f8-idem',
            scope: $this->account(),
            correctionRef: 'corr-f8-idem',
            originalLegalDocumentRef: $origRef,
            obligationRef: 'ob-f8-pos',
            customerRef: 'cust-f7orig',
            componentRef: 'comp-1',
            parentOccurrenceRef: 'occ-f8',
            correctionRevision: 1,
            deltaNetMinor: 1000,
            deltaTaxMinor: 200,
            currency: 'RUB',
            taxPolicy: $policy,
            reason: 'positive_delta_without_legal_doc',
            issueLegalDocument: false,
        ));
        self::assertTrue($firstOutcome->accepted);
        self::assertNull($firstOutcome->body['resultingLegalDocumentRef'] ?? null);

        // Replay same commandKey switching issueLegalDocument=true with valid coordinates -> IdempotencyConflict.
        try {
            $this->correctionService()->issuePositiveCorrection(new IssuePositiveDocumentCorrection(
                commandKey: 'cmd-f8-idem',
                scope: $this->account(),
                correctionRef: 'corr-f8-idem',
                originalLegalDocumentRef: $origRef,
                obligationRef: 'ob-f8-pos',
                customerRef: 'cust-f7orig',
                componentRef: 'comp-1',
                parentOccurrenceRef: 'occ-f8',
                correctionRevision: 1,
                deltaNetMinor: 1000,
                deltaTaxMinor: 200,
                currency: 'RUB',
                taxPolicy: $policy,
                reason: 'positive_delta_without_legal_doc',
                issueLegalDocument: true,
                seriesRef: 'CORR',
                periodKey: '2026',
                finalizationRef: 'fin-f8-pos',
                approvedSnapshotDigest: $rdF8->approvedSnapshotDigest,
            ));
            self::fail('Replaying positive correction commandKey with issueLegalDocument=true must trigger IdempotencyConflict');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::IdempotencyConflict, $error->problem);
        }

        // Replay same commandKey mutating customerRef -> IdempotencyConflict.
        try {
            $this->correctionService()->issuePositiveCorrection(new IssuePositiveDocumentCorrection(
                commandKey: 'cmd-f8-idem',
                scope: $this->account(),
                correctionRef: 'corr-f8-idem',
                originalLegalDocumentRef: $origRef,
                obligationRef: 'ob-f8-pos',
                customerRef: 'cust-mutated',
                componentRef: 'comp-1',
                parentOccurrenceRef: 'occ-f8',
                correctionRevision: 1,
                deltaNetMinor: 1000,
                deltaTaxMinor: 200,
                currency: 'RUB',
                taxPolicy: $policy,
                reason: 'positive_delta_without_legal_doc',
                issueLegalDocument: false,
            ));
            self::fail('Replaying positive correction commandKey with mutated customerRef must trigger IdempotencyConflict');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::IdempotencyConflict, $error->problem);
        }

        // Replay same commandKey mutating componentRef -> IdempotencyConflict.
        try {
            $this->correctionService()->issuePositiveCorrection(new IssuePositiveDocumentCorrection(
                commandKey: 'cmd-f8-idem',
                scope: $this->account(),
                correctionRef: 'corr-f8-idem',
                originalLegalDocumentRef: $origRef,
                obligationRef: 'ob-f8-pos',
                customerRef: 'cust-f7orig',
                componentRef: 'comp-mutated',
                parentOccurrenceRef: 'occ-f8',
                correctionRevision: 1,
                deltaNetMinor: 1000,
                deltaTaxMinor: 200,
                currency: 'RUB',
                taxPolicy: $policy,
                reason: 'positive_delta_without_legal_doc',
                issueLegalDocument: false,
            ));
            self::fail('Replaying positive correction commandKey with mutated componentRef must trigger IdempotencyConflict');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::IdempotencyConflict, $error->problem);
        }
    }
}
