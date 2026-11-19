<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Diagnostics\BilliumCompatibilityDoctorCheck;
use Billium\Core\Tests\P6\Support\DocumentsTestCase;
use Billium\Core\Tests\Support\Concurrency\WorkerApplication;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Documents\Contracts\DocumentRenderer;
use Billium\Documents\Contracts\DocumentTaxArithmetic;
use Billium\Documents\Contracts\DocumentTaxSnapshotPort;
use Billium\Documents\Contracts\LegalDocumentIssuer;
use Billium\Documents\Data\AttachTaxSnapshot;
use Billium\Documents\Data\CreateDocumentDraft;
use Billium\Documents\Data\DocumentLineBasis;
use Billium\Documents\Data\DocumentTaxLineSnapshot;
use Billium\Documents\Data\DocumentTaxSnapshot;
use Billium\Documents\Data\RegisterDocumentProfile;
use Billium\Documents\Data\ReviseDocumentDraft;
use Billium\Documents\Data\TaxArithmeticLineInput;
use Billium\Documents\Data\TaxSnapshotRequest;
use Billium\Documents\Enums\DiscountTaxOrder;
use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\FinalizationState;
use Billium\Documents\Enums\SeriesPeriodPolicy;
use Billium\Documents\Enums\TaxConvention;
use Billium\Documents\Laravel\DocumentsServiceProvider;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Obligations\AdjustObligation;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Packman\Doctor\Data\DoctorContext;
use Packman\Doctor\Enums\CheckStatus;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
final class DocumentProfileTest extends DocumentsTestCase
{
    public function test_document_profile_versions_conflicts_and_incomplete_blocks_issue(): void
    {
        $v1 = $this->registerCompleteProfile('prof-tax', 1);
        self::assertTrue($v1->isComplete);

        // Idempotent registration of identical profile version returns same digest & id.
        $same = $this->registerCompleteProfile('prof-tax', 1);
        self::assertSame($v1->id, $same->id);
        self::assertSame($v1->profileDigest, $same->profileDigest);

        // Conflicting definition for same (profileRef, version) is rejected.
        try {
            $this->profiles()->register(new RegisterDocumentProfile(
                scope: $this->docScope(),
                profileRef: 'prof-tax',
                version: 1,
                jurisdictionRef: 'DE-BE',
                supportedTypes: [DocumentType::Invoice],
                numberingPolicy: ['seriesRef' => 'INV', 'periodPolicy' => SeriesPeriodPolicy::Yearly->value],
                taxSnapshotSchema: 'v1',
                correctionPolicy: ['taxTreatment' => 'original_rate'],
                requiredFields: ['seller.legalName'],
                retentionPolicyRef: 'ret-10y',
                sellerSnapshot: ['legalName' => 'Acme Seller LLC', 'taxId' => 'US-99-1234567'],
            ));
            self::fail('Conflicting profile version must be refused');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            self::assertSame('billium.documents.profile_conflict', $error->detail);
        }

        // Incomplete profile (missing seller taxId & required seller field) registers with isComplete = false.
        $incomplete = $this->profiles()->register(new RegisterDocumentProfile(
            scope: $this->docScope(),
            profileRef: 'prof-incomplete',
            version: 1,
            jurisdictionRef: 'US-NY',
            supportedTypes: [DocumentType::Invoice],
            numberingPolicy: ['seriesRef' => 'INV', 'periodPolicy' => SeriesPeriodPolicy::Yearly->value],
            taxSnapshotSchema: 'v1',
            correctionPolicy: ['taxTreatment' => 'original_rate'],
            requiredFields: ['seller.legalName', 'seller.taxId'],
            retentionPolicyRef: 'ret-10y',
            sellerSnapshot: ['legalName' => 'Missing Tax Id LLC'],
        ));
        self::assertFalse($incomplete->isComplete);

        $this->registerSeries();
        $finalized = $this->prepareFinalizedInvoiceBasis(suffix: 'inc', profileRef: 'prof-incomplete');

        try {
            $this->issuer()->issueInvoiceDocument($this->issueCommand('issue-incomplete', 'inc', $finalized->approvedSnapshotDigest ?? ''));
            self::fail('Incomplete DocumentProfile must forbid issue');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            self::assertSame('billium.documents.profile_incomplete', $error->detail);
        }

        self::assertSame('draft', $this->invoiceStatus('inv-inc'));
        self::assertSame(0, $this->rows('billium_document_legal'));
    }

    public function test_disabled_documents_mode_does_not_block_ledger_obligations_and_invoices(): void
    {
        $hostApp = WorkerApplication::create();
        $hostApp->instance(CallerContext::class, FixedCallerContext::default());
        $hostApp->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token()));
        SimulatedFinancialAuthorityEvidence::admitCurrent($hostApp);
        $config = $hostApp->make(Repository::class);
        $config->set('billium.modules.enabled', ['platform', 'ledger']);
        $config->set('billium.documents.enabled', false);

        self::assertSame([], $hostApp->getProviders(DocumentsServiceProvider::class));
        self::assertFalse($hostApp->bound(LegalDocumentIssuer::class));

        /** @var Ledger $ledger */
        $ledger = $hostApp->make(Ledger::class);
        $scope = new BillingAccountScope('seller-a', 'test', 'account-a');

        $ledger->recordObligation(new RecordObligation(
            commandKey: 'disabled-docs-ob',
            scope: $scope,
            obligationRef: 'ob-no-docs',
            basisRef: 'basis-no-docs',
            customerRef: 'cust-no-docs',
            componentRef: 'comp-1',
            money: new Money(2500, Currency::of('RUB')),
            termsDigest: self::digest(),
        ));
        $ledger->createInvoice(new CreateInvoice(
            commandKey: 'disabled-docs-inv',
            scope: $scope,
            invoiceRef: 'inv-no-docs',
            customerRef: 'cust-no-docs',
            lines: [new InvoiceLineInput('ob-no-docs')],
        ));
        $issued = $ledger->issueInvoice(new IssueInvoice('disabled-docs-issue', $scope, 'inv-no-docs'));
        self::assertTrue($issued->accepted);
        self::assertSame('issued', $this->value("SELECT status FROM billium_invoice WHERE invoice_ref='inv-no-docs'"));
    }

    public function test_clean_host_resolves_public_document_ports_and_doctor_reports_bindings(): void
    {
        $hostApp = WorkerApplication::create();
        $hostApp->instance(CallerContext::class, FixedCallerContext::default());
        $hostApp->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token()));
        SimulatedFinancialAuthorityEvidence::admitCurrent($hostApp);
        $config = $hostApp->make(Repository::class);
        $config->set('billium.modules.enabled', ['platform', 'ledger', 'documents']);
        $config->set('billium.documents.enabled', true);
        $config->set('billium.feed.signing_key', str_repeat('a', 32));
        $hostApp->register(DocumentsServiceProvider::class);

        // Default unbound TaxSnapshotPort fails explicitly.
        try {
            $hostApp->make(DocumentTaxSnapshotPort::class)->resolve(new TaxSnapshotRequest(
                sellerRef: 'seller-a',
                environment: 'test',
                finalizationRef: 'fin-probe',
                finalizationRevision: 1,
                documentType: DocumentType::Invoice,
                economicRef: 'inv-probe',
                basisRevision: 1,
                basisDigest: self::digest(),
                inputDigest: self::digest('e'),
                taxSnapshotSchema: 'v1',
                jurisdictionRef: 'US-NY',
                currency: 'USD',
                currencyExponent: 2,
                convention: TaxConvention::Exclusive,
                roundingMode: 'half_up',
                discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
                lines: [],
                buyerSnapshot: [],
                sellerSnapshot: [],
            ));
            self::fail('Unbound TaxSnapshotPort must throw LookupUnavailable');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::LookupUnavailable, $error->problem);
        }

        // Before host binds DocumentRenderer, compatibility check flags missing required port.
        $before = $hostApp->make(BilliumCompatibilityDoctorCheck::class)->run(new DoctorContext(production: false));
        $beforeCodes = array_map(static fn ($f): string => $f->code, $before);
        self::assertNotContains(CheckStatus::Ok, array_map(static fn ($f): CheckStatus => $f->status, $before));
        self::assertNotEmpty($beforeCodes);

        // Host binds public DocumentRenderer and custom DocumentTaxSnapshotPort without any Internal references.
        $customPort = new class implements DocumentTaxSnapshotPort
        {
            public function resolve(TaxSnapshotRequest $request): DocumentTaxSnapshot
            {
                return new DocumentTaxSnapshot(
                    taxSnapshotRef: 'host-ts-1',
                    taxPolicyVersion: 'v1',
                    taxPoint: '2026-11-19',
                    convention: $request->convention,
                    currency: $request->currency,
                    currencyExponent: $request->currencyExponent,
                    subtotalMinor: 1000,
                    taxMinor: 0,
                    totalMinor: 1000,
                    lines: [],
                    inputDigest: $request->inputDigest,
                    evidenceDigest: self::digest('f'),
                    resolver: 'host-port',
                    resolverVersion: '1.0',
                );
            }
        };
        $hostApp->instance(DocumentTaxSnapshotPort::class, $customPort);
        $hostApp->instance(DocumentRenderer::class, new class implements DocumentRenderer
        {
            public function rendererVersion(): string
            {
                return 'host-renderer-1.0';
            }
        });

        self::assertSame($customPort, $hostApp->make(DocumentTaxSnapshotPort::class));
        $after = $hostApp->make(BilliumCompatibilityDoctorCheck::class)->run(new DoctorContext(production: false));
        self::assertCount(1, $after);
        self::assertSame(CheckStatus::Ok, $after[0]->status);
    }

    public function test_finalization_cas_timeout_retry_and_late_stale_result_handling(): void
    {
        $this->registerCompleteProfile();

        $draft = $this->finalizations()->createDraft(new CreateDocumentDraft(
            scope: $this->docScope(),
            finalizationRef: 'fin-cas',
            documentType: DocumentType::Invoice,
            economicRef: 'inv-cas',
            basisRevision: 1,
            basisDigest: self::digest('a'),
            profileRef: 'prof-default',
            profileVersion: 1,
            currency: 'USD',
            currencyExponent: 2,
            convention: TaxConvention::Exclusive,
            roundingMode: 'half_up',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [
                new DocumentLineBasis('l1', 'ob-cas', 'comp-1', '1', '10.00', 1000, 0, 'VAT', '0.10', 'Line 1'),
            ],
            buyerSnapshot: ['legalName' => 'Buyer One'],
            termsSnapshot: ['term' => 'NET30'],
        ));
        self::assertSame(FinalizationState::Draft, $draft->state);

        // Bind a port that fails once (simulating timeout outside DB Tx), then succeeds on retry.
        $attempts = 0;
        $txLevelsObserved = [];
        $db = $this->application()->make(DatabaseManager::class);
        $arithmetic = $this->taxArithmetic();
        $this->application()->instance(DocumentTaxSnapshotPort::class, new class($attempts, $txLevelsObserved, $db, $arithmetic) implements DocumentTaxSnapshotPort
        {
            public function __construct(
                private int &$attempts,
                /** @var list<int> */
                private array &$txLevels,
                private DatabaseManager $db,
                private DocumentTaxArithmetic $arithmetic,
            ) {}

            public function resolve(TaxSnapshotRequest $request): DocumentTaxSnapshot
            {
                $this->txLevels[] = $this->db->connection()->transactionLevel();
                $this->attempts++;
                if ($this->attempts === 1) {
                    throw new RuntimeException('tax provider timeout');
                }
                $calc = $this->arithmetic->calculate(
                    [new TaxArithmeticLineInput('l1', '10.00', '0', '0.10', 'VAT')],
                    $request->convention,
                    $request->currency,
                    $request->roundingMode,
                    $request->discountTaxOrder,
                );
                $line = $calc->lines[0];

                return new DocumentTaxSnapshot(
                    taxSnapshotRef: 'ts-cas-retry',
                    taxPolicyVersion: 'v1',
                    taxPoint: '2026-11-19',
                    convention: $request->convention,
                    currency: $request->currency,
                    currencyExponent: $request->currencyExponent,
                    subtotalMinor: $calc->subtotalMinor,
                    taxMinor: $calc->taxMinor,
                    totalMinor: $calc->totalMinor,
                    lines: [new DocumentTaxLineSnapshot('l1', 'VAT', '0.10', $line->netMinor, $line->taxMinor, $line->grossMinor)],
                    inputDigest: $request->inputDigest,
                    evidenceDigest: self::digest('e'),
                    resolver: 'port-retry',
                    resolverVersion: '1.0',
                );
            }
        });

        try {
            $this->finalizations()->finalizeWithTaxPort($this->docScope(), 'fin-cas');
            self::fail('Simulated tax port timeout must fail');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::TransportUnavailable, $error->problem);
        }

        // State stayed Finalizing, tax_port_failed event recorded, and resolve ran strictly outside any DB Tx.
        $afterTimeout = $this->finalizations()->find($this->docScope(), 'fin-cas');
        self::assertNotNull($afterTimeout);
        self::assertSame(FinalizationState::Finalizing, $afterTimeout->state);
        self::assertSame([0], $txLevelsObserved);
        $oldDigest = $afterTimeout->inputDigest;
        $oldRev = $afterTimeout->finalizationRevision;

        // Before stale reply arrives, draft is revised to revision 2 (superseding revision 1).
        $revised = $this->finalizations()->reviseDraft(new ReviseDocumentDraft(
            scope: $this->docScope(),
            finalizationRef: 'fin-cas',
            basisRevision: 2,
            basisDigest: self::digest('b'),
            currency: 'USD',
            currencyExponent: 2,
            convention: TaxConvention::Exclusive,
            roundingMode: 'half_up',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [
                new DocumentLineBasis('l1', 'ob-cas', 'comp-1', '1', '20.00', 2000, 0, 'VAT', '0.10', 'Revised Line 1'),
            ],
            buyerSnapshot: ['legalName' => 'Buyer One'],
            termsSnapshot: ['term' => 'NET30'],
        ));
        self::assertSame(2, $revised->finalizationRevision);
        self::assertSame(FinalizationState::Draft, $revised->state);

        // Late snapshot for superseded revision 1 is safely ignored and logged.
        $late = $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
            scope: $this->docScope(),
            finalizationRef: 'fin-cas',
            finalizationRevision: $oldRev,
            inputDigest: $oldDigest,
            snapshot: new DocumentTaxSnapshot(
                taxSnapshotRef: 'ts-late',
                taxPolicyVersion: 'v1',
                taxPoint: '2026-11-19',
                convention: TaxConvention::Exclusive,
                currency: 'USD',
                currencyExponent: 2,
                subtotalMinor: 1000,
                taxMinor: 100,
                totalMinor: 1100,
                lines: [new DocumentTaxLineSnapshot('l1', 'VAT', '0.10', 1000, 100, 1100)],
                inputDigest: $oldDigest,
                evidenceDigest: self::digest('f'),
                resolver: 'late',
                resolverVersion: '1.0',
            ),
        ));
        self::assertFalse($late->attached);
        self::assertTrue($late->staleIgnored);
        self::assertSame(2, $late->finalization->finalizationRevision);
        self::assertSame(FinalizationState::Draft, $late->finalization->state);

        // Revision 2 finalization + retry now succeeds.
        $this->bindArithmeticTaxPort();
        $ready = $this->finalizations()->finalizeWithTaxPort($this->docScope(), 'fin-cas');
        self::assertTrue($ready->attached);
        self::assertFalse($ready->staleIgnored);
        self::assertSame(FinalizationState::SnapshotReady, $ready->finalization->state);
        self::assertSame(2000, $ready->finalization->subtotalMinor);
        self::assertSame(200, $ready->finalization->taxMinor);
        self::assertSame(2200, $ready->finalization->totalMinor);

        // Idempotent retry on already snapshot_ready does not re-attach or error.
        $replay = $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
            scope: $this->docScope(),
            finalizationRef: 'fin-cas',
            finalizationRevision: $ready->finalization->finalizationRevision,
            inputDigest: $ready->finalization->inputDigest,
            snapshot: $ready->finalization->taxSnapshot ?? self::fail('Expected taxSnapshot'),
        ));
        self::assertFalse($replay->attached);
        self::assertFalse($replay->staleIgnored);

        $eventKinds = array_map(static fn ($e): string => $e->eventKind, $this->finalizations()->events($this->docScope(), 'fin-cas'));
        self::assertContains('tax_port_failed', $eventKinds);
        self::assertContains('revision_superseded', $eventKinds);
        self::assertContains('stale_snapshot_ignored', $eventKinds);
        self::assertContains('snapshot_attached', $eventKinds);
    }

    public function test_currency_and_amount_conflicts_refused_and_locked_basis_change_blocks_issue(): void
    {
        $this->registerCompleteProfile();
        $this->registerSeries();

        $draft = $this->finalizations()->createDraft(new CreateDocumentDraft(
            scope: $this->docScope(),
            finalizationRef: 'fin-conflict',
            documentType: DocumentType::Invoice,
            economicRef: 'inv-conflict',
            basisRevision: 1,
            basisDigest: self::digest('a'),
            profileRef: 'prof-default',
            profileVersion: 1,
            currency: 'USD',
            currencyExponent: 2,
            convention: TaxConvention::Exclusive,
            roundingMode: 'half_up',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [new DocumentLineBasis('l1', 'ob-c', 'comp-1', '1', '50.00', 5000, 0, 'VAT', '0.10')],
            buyerSnapshot: ['legalName' => 'Buyer C'],
            termsSnapshot: [],
        ));
        $finalizing = $this->finalizations()->startFinalization($this->docScope(), 'fin-conflict');

        // 1. Currency conflict rejected with MoneyCurrencyMismatch.
        try {
            $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
                scope: $this->docScope(),
                finalizationRef: 'fin-conflict',
                finalizationRevision: 1,
                inputDigest: $finalizing->inputDigest,
                snapshot: new DocumentTaxSnapshot(
                    taxSnapshotRef: 'ts-eur',
                    taxPolicyVersion: 'v1',
                    taxPoint: '2026-11-19',
                    convention: TaxConvention::Exclusive,
                    currency: 'EUR',
                    currencyExponent: 2,
                    subtotalMinor: 5000,
                    taxMinor: 500,
                    totalMinor: 5500,
                    lines: [new DocumentTaxLineSnapshot('l1', 'VAT', '0.10', 5000, 500, 5500)],
                    inputDigest: $finalizing->inputDigest,
                    evidenceDigest: self::digest(),
                    resolver: 'bad',
                    resolverVersion: '1',
                ),
            ));
            self::fail('Currency conflict must throw');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::MoneyCurrencyMismatch, $error->problem);
        }

        // 2. Subtotal/amount conflict rejected with ValidationInvalid.
        try {
            $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
                scope: $this->docScope(),
                finalizationRef: 'fin-conflict',
                finalizationRevision: 1,
                inputDigest: $finalizing->inputDigest,
                snapshot: new DocumentTaxSnapshot(
                    taxSnapshotRef: 'ts-amt',
                    taxPolicyVersion: 'v1',
                    taxPoint: '2026-11-19',
                    convention: TaxConvention::Exclusive,
                    currency: 'USD',
                    currencyExponent: 2,
                    subtotalMinor: 9999,
                    taxMinor: 500,
                    totalMinor: 10499,
                    lines: [new DocumentTaxLineSnapshot('l1', 'VAT', '0.10', 9999, 500, 10499)],
                    inputDigest: $finalizing->inputDigest,
                    evidenceDigest: self::digest(),
                    resolver: 'bad',
                    resolverVersion: '1',
                ),
            ));
            self::fail('Amount conflict must throw');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
        }
        self::assertSame(FinalizationState::Finalizing, $this->finalizations()->find($this->docScope(), 'fin-conflict')?->state);

        // 3. Locked basis recheck: snapshot prepared for 10000 RUB net, then obligation is adjusted +500 before issue.
        $ready = $this->prepareFinalizedInvoiceBasis(suffix: 'drift', netMinor: 10000, taxMinor: 0, currency: 'RUB', includeLedgerTax: false);
        $this->ledger()->adjustObligation(new AdjustObligation(
            commandKey: 'adj-drift',
            scope: $this->account(),
            adjustmentRef: 'adj-drift-1',
            obligationRef: 'ob-drift',
            money: new Money(500, Currency::of('RUB')),
            causeRef: 'post-finalization-adjustment',
        ));

        try {
            $this->issuer()->issueInvoiceDocument($this->issueCommand('issue-drift', 'drift', $ready->approvedSnapshotDigest ?? ''));
            self::fail('Issue must fail when locked invoice basis changed after tax snapshot');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            self::assertSame('billium.documents.basis_changed_under_lock', $error->detail);
        }

        // Whole transaction rolled back: ledger invoice stays draft and zero legal documents/numbers allocated.
        self::assertSame('draft', $this->invoiceStatus('inv-drift'));
        self::assertNull($this->invoiceRow('inv-drift')['legal_document_ref']);
        self::assertSame(0, $this->rows('billium_document_legal'));
        self::assertSame(0, $this->rows('billium_document_number_journal'));
        self::assertSame(1, $this->value("SELECT next_number FROM billium_document_series WHERE series_ref='INV'"));
    }

    public function test_tax_arithmetic_matches_all_golden_vectors_without_float(): void
    {
        $path = dirname(__DIR__, 4).'/fixtures/vectors/documents/tax-arithmetic.json';
        self::assertFileExists($path);
        /** @var array{given: array{example: array{cases: list<array<string, mixed>>}}} $vector */
        $vector = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $arithmetic = $this->taxArithmetic();

        foreach ($vector['given']['example']['cases'] as $case) {
            /** @var list<array{lineRef: string, amountDecimal: string, discountDecimal: string, rateDecimal: string, taxCode: string}> $rawLines */
            $rawLines = $case['lines'];
            $inputs = array_map(
                static fn (array $l): TaxArithmeticLineInput => new TaxArithmeticLineInput(
                    lineRef: $l['lineRef'],
                    amountDecimal: $l['amountDecimal'],
                    discountDecimal: $l['discountDecimal'],
                    rateDecimal: $l['rateDecimal'],
                    taxCode: $l['taxCode'],
                ),
                $rawLines,
            );

            $result = $arithmetic->calculate(
                lines: $inputs,
                convention: TaxConvention::from((string) $case['convention']),
                currency: (string) $case['currency'],
                roundingMode: (string) $case['roundingMode'],
                order: DiscountTaxOrder::from((string) $case['order']),
                noTaxReason: is_string($case['noTaxReason']) ? $case['noTaxReason'] : null,
            );

            /** @var array{subtotalMinor: int, taxMinor: int, totalMinor: int, lines: list<array{lineRef: string, netMinor: int, taxMinor: int, grossMinor: int, residualMinor: int}>} $exp */
            $exp = $case['expected'];
            $name = (string) $case['name'];
            self::assertSame($exp['subtotalMinor'], $result->subtotalMinor, $name.' subtotalMinor');
            self::assertSame($exp['taxMinor'], $result->taxMinor, $name.' taxMinor');
            self::assertSame($exp['totalMinor'], $result->totalMinor, $name.' totalMinor');
            self::assertCount(count($exp['lines']), $result->lines, $name.' line count');
            foreach ($exp['lines'] as $idx => $expLine) {
                $actual = $result->lines[$idx];
                self::assertSame($expLine['lineRef'], $actual->lineRef, $name.' lineRef');
                self::assertSame($expLine['netMinor'], $actual->netMinor, $name.' netMinor');
                self::assertSame($expLine['taxMinor'], $actual->taxMinor, $name.' taxMinor');
                self::assertSame($expLine['grossMinor'], $actual->grossMinor, $name.' grossMinor');
                self::assertSame($expLine['residualMinor'], $actual->residualMinor, $name.' residualMinor');
            }
        }
    }
}
