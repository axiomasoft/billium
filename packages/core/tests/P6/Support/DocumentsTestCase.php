<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6\Support;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Documents\Contracts\DocumentCorrectionService;
use Billium\Documents\Contracts\DocumentFinalizationService;
use Billium\Documents\Contracts\DocumentProfileRegistry;
use Billium\Documents\Contracts\DocumentRenderer;
use Billium\Documents\Contracts\DocumentRenderService;
use Billium\Documents\Contracts\DocumentSeriesRegistry;
use Billium\Documents\Contracts\DocumentTaxArithmetic;
use Billium\Documents\Contracts\DocumentTaxSnapshotPort;
use Billium\Documents\Contracts\LegalDocumentIssuer;
use Billium\Documents\Contracts\LegalDocumentQueries;
use Billium\Documents\Data\AttachTaxSnapshot;
use Billium\Documents\Data\CreateDocumentDraft;
use Billium\Documents\Data\CreateDocumentSeries;
use Billium\Documents\Data\DocumentFinalizationView;
use Billium\Documents\Data\DocumentLineBasis;
use Billium\Documents\Data\DocumentProfile;
use Billium\Documents\Data\DocumentScope;
use Billium\Documents\Data\DocumentSeriesView;
use Billium\Documents\Data\DocumentTaxLineSnapshot;
use Billium\Documents\Data\DocumentTaxSnapshot;
use Billium\Documents\Data\IssueLegalDocument;
use Billium\Documents\Data\RegisterDocumentProfile;
use Billium\Documents\Data\RenderDocumentInput;
use Billium\Documents\Data\RenderedArtifact;
use Billium\Documents\Data\TaxArithmeticLineInput;
use Billium\Documents\Data\TaxSnapshotRequest;
use Billium\Documents\Enums\DiscountTaxOrder;
use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\SeriesPeriodPolicy;
use Billium\Documents\Enums\TaxConvention;
use Billium\Documents\Laravel\DocumentsServiceProvider;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Tests\Commands\InvoiceFixtures;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;

/**
 * Real PostgreSQL test base for Phase P6 (Documents + Ledger atomic issue composition).
 */
abstract class DocumentsTestCase extends LedgerTestCase
{
    use InvoiceFixtures;

    /** @param Application $app */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), DocumentsServiceProvider::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $config = $app->make(Repository::class);
        $config->set('billium.modules.enabled', ['platform', 'ledger', 'documents']);
        $config->set('billium.documents.enabled', true);
    }

    protected function docScope(?string $sellerRef = null, ?string $environment = null): DocumentScope
    {
        $caller = $this->callers->current();

        return new DocumentScope($sellerRef ?? $caller->sellerRef, $environment ?? $caller->environment);
    }

    protected function profiles(): DocumentProfileRegistry
    {
        return $this->application()->make(DocumentProfileRegistry::class);
    }

    protected function finalizations(): DocumentFinalizationService
    {
        return $this->application()->make(DocumentFinalizationService::class);
    }

    protected function seriesRegistry(): DocumentSeriesRegistry
    {
        return $this->application()->make(DocumentSeriesRegistry::class);
    }

    protected function issuer(): LegalDocumentIssuer
    {
        return $this->application()->make(LegalDocumentIssuer::class);
    }

    protected function docQueries(): LegalDocumentQueries
    {
        return $this->application()->make(LegalDocumentQueries::class);
    }

    protected function renderService(): DocumentRenderService
    {
        return $this->application()->make(DocumentRenderService::class);
    }

    protected function correctionService(): DocumentCorrectionService
    {
        return $this->application()->make(DocumentCorrectionService::class);
    }

    protected function taxArithmetic(): DocumentTaxArithmetic
    {
        return $this->application()->make(DocumentTaxArithmetic::class);
    }

    protected function bindStubRenderer(): void
    {
        $this->application()->instance(DocumentRenderer::class, new class implements DocumentRenderer
        {
            public function rendererName(): string
            {
                return 'stub';
            }

            public function rendererVersion(): string
            {
                return 'stub-1.0';
            }

            public function render(RenderDocumentInput $input): RenderedArtifact
            {
                $bytes = '%PDF-1.4 '.$input->legalNumber;

                return new RenderedArtifact('application/pdf', $bytes, 'sha256:'.hash('sha256', $bytes));
            }
        });
    }

    protected function bindArithmeticTaxPort(): void
    {
        $arithmetic = $this->taxArithmetic();
        $this->application()->instance(DocumentTaxSnapshotPort::class, new class($arithmetic) implements DocumentTaxSnapshotPort
        {
            public function __construct(private DocumentTaxArithmetic $arithmetic) {}

            public function resolve(TaxSnapshotRequest $request): DocumentTaxSnapshot
            {
                $inputs = [];
                foreach ($request->lines as $line) {
                    $amountDec = bcmul($line->quantity, $line->unitPriceDecimal, 2);
                    $discountDec = bcdiv((string) $line->discountMinor, bcpow('10', (string) $request->currencyExponent, 0), $request->currencyExponent);
                    $inputs[] = new TaxArithmeticLineInput($line->lineRef, $amountDec, $discountDec, $line->taxRate, $line->taxCode);
                }
                $calc = $this->arithmetic->calculate(
                    $inputs,
                    $request->convention,
                    $request->currency,
                    $request->roundingMode,
                    $request->discountTaxOrder,
                    $request->convention === TaxConvention::NoneDeclared ? 'zero_rated_supply' : null,
                );
                $lines = [];
                $metaByLine = [];
                foreach ($request->lines as $basis) {
                    $metaByLine[$basis->lineRef] = $basis;
                }
                foreach ($calc->lines as $res) {
                    $meta = $metaByLine[$res->lineRef];
                    $lines[] = new DocumentTaxLineSnapshot(
                        lineRef: $res->lineRef,
                        taxCode: $meta->taxCode,
                        rateDecimal: $meta->taxRate,
                        netMinor: $res->netMinor,
                        taxMinor: $res->taxMinor,
                        grossMinor: $res->grossMinor,
                    );
                }

                return new DocumentTaxSnapshot(
                    taxSnapshotRef: 'tsnap-'.$request->finalizationRef.'-r'.$request->finalizationRevision,
                    taxPolicyVersion: 'policy-v1',
                    taxPoint: '2026-11-19',
                    convention: $request->convention,
                    currency: $request->currency,
                    currencyExponent: $request->currencyExponent,
                    subtotalMinor: $calc->subtotalMinor,
                    taxMinor: $calc->taxMinor,
                    totalMinor: $calc->totalMinor,
                    lines: $lines,
                    inputDigest: $request->inputDigest,
                    evidenceDigest: 'sha256:'.hash('sha256', $request->inputDigest.':evidence'),
                    resolver: 'test-tax-port',
                    resolverVersion: '1.0',
                    noTaxReason: $request->convention === TaxConvention::NoneDeclared ? 'zero_rated_supply' : null,
                );
            }
        });
    }

    protected function registerCompleteProfile(string $ref = 'prof-default', int $version = 1, ?DocumentScope $scope = null): DocumentProfile
    {
        return $this->profiles()->register(new RegisterDocumentProfile(
            scope: $scope ?? $this->docScope(),
            profileRef: $ref,
            version: $version,
            jurisdictionRef: 'US-NY',
            supportedTypes: [DocumentType::Invoice, DocumentType::CreditNote, DocumentType::Correction],
            numberingPolicy: ['seriesRef' => 'INV', 'periodPolicy' => SeriesPeriodPolicy::Yearly->value],
            taxSnapshotSchema: 'v1',
            correctionPolicy: ['taxTreatment' => 'original_rate'],
            requiredFields: ['seller.legalName', 'seller.taxId', 'buyer.legalName'],
            retentionPolicyRef: 'ret-10y',
            sellerSnapshot: ['legalName' => 'Acme Seller LLC', 'taxId' => 'US-99-1234567', 'address' => '100 Main St'],
        ));
    }

    protected function registerSeries(
        string $seriesRef = 'INV',
        string $periodKey = '2026',
        SeriesPeriodPolicy $policy = SeriesPeriodPolicy::Yearly,
        DocumentType $type = DocumentType::Invoice,
        ?DocumentScope $scope = null,
    ): DocumentSeriesView {
        return $this->seriesRegistry()->createSeries(new CreateDocumentSeries(
            scope: $scope ?? $this->docScope(),
            seriesRef: $seriesRef,
            documentType: $type,
            periodKey: $periodKey,
            periodPolicy: $policy,
            timezone: 'UTC',
            prefix: $seriesRef,
            padding: 6,
            legalEntityRevision: 1,
        ));
    }

    protected function prepareFinalizedInvoiceBasis(
        string $suffix = 'a',
        int $netMinor = 10000,
        int $taxMinor = 2000,
        string $currency = 'RUB',
        string $profileRef = 'prof-default',
        string $buyerName = 'Buyer Corp',
        bool $includeLedgerTax = true,
    ): DocumentFinalizationView {
        $obligationRef = 'ob-'.$suffix;
        $invoiceRef = 'inv-'.$suffix;
        $finalizationRef = 'fin-'.$suffix;
        $ledgerTotal = $includeLedgerTax ? ($netMinor + $taxMinor) : $netMinor;

        $this->ledger()->recordObligation(new RecordObligation(
            commandKey: 'rec-ob-'.$suffix,
            scope: $this->account(),
            obligationRef: $obligationRef,
            basisRef: 'basis-'.$suffix,
            customerRef: 'cust-'.$suffix,
            componentRef: 'comp-1',
            money: new Money($ledgerTotal, Currency::of($currency)),
            termsDigest: self::digest('b'),
        ));

        $this->ledger()->createInvoice(new CreateInvoice(
            commandKey: 'create-inv-'.$suffix,
            scope: $this->account(),
            invoiceRef: $invoiceRef,
            customerRef: 'cust-'.$suffix,
            lines: [new InvoiceLineInput($obligationRef, $includeLedgerTax ? $taxMinor : 0, ['name' => 'Service'])],
            snapshot: ['buyer' => $buyerName],
        ));

        $draft = $this->finalizations()->createDraft(new CreateDocumentDraft(
            scope: $this->docScope(),
            finalizationRef: $finalizationRef,
            documentType: DocumentType::Invoice,
            economicRef: $invoiceRef,
            basisRevision: 1,
            basisDigest: self::digest('c'),
            profileRef: $profileRef,
            profileVersion: 1,
            currency: $currency,
            currencyExponent: Currency::of($currency)->scale,
            convention: TaxConvention::Exclusive,
            roundingMode: 'half_up',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [
                new DocumentLineBasis(
                    lineRef: 'line-1',
                    obligationRef: $obligationRef,
                    componentRef: 'comp-1',
                    quantity: '1',
                    unitPriceDecimal: bcdiv((string) $netMinor, bcpow('10', (string) Currency::of($currency)->scale, 0), Currency::of($currency)->scale),
                    netMinor: $netMinor,
                    discountMinor: 0,
                    taxCode: 'VAT20',
                    taxRate: '0.20',
                    description: 'Service charge',
                ),
            ],
            buyerSnapshot: ['legalName' => $buyerName, 'taxId' => 'BUYER-TAX-1'],
            termsSnapshot: ['paymentTerm' => 'NET30'],
        ));

        $started = $this->finalizations()->startFinalization($this->docScope(), $finalizationRef);

        $snapshot = new DocumentTaxSnapshot(
            taxSnapshotRef: 'snap-'.$suffix,
            taxPolicyVersion: 'v1',
            taxPoint: '2026-11-19',
            convention: TaxConvention::Exclusive,
            currency: $currency,
            currencyExponent: Currency::of($currency)->scale,
            subtotalMinor: $netMinor,
            taxMinor: $taxMinor,
            totalMinor: $netMinor + $taxMinor,
            lines: [
                new DocumentTaxLineSnapshot(
                    lineRef: 'line-1',
                    taxCode: 'VAT20',
                    rateDecimal: '0.20',
                    netMinor: $netMinor,
                    taxMinor: $taxMinor,
                    grossMinor: $netMinor + $taxMinor,
                ),
            ],
            inputDigest: $started->inputDigest,
            evidenceDigest: self::digest('d'),
            resolver: 'fixture-tax-port',
            resolverVersion: '1.0',
        );

        return $this->finalizations()->attachTaxSnapshot(new AttachTaxSnapshot(
            scope: $this->docScope(),
            finalizationRef: $finalizationRef,
            finalizationRevision: $started->finalizationRevision,
            inputDigest: $started->inputDigest,
            snapshot: $snapshot,
        ))->finalization;
    }

    protected function issueCommand(string $key, string $suffix, string $approvedDigest, string $seriesRef = 'INV', string $periodKey = '2026'): IssueLegalDocument
    {
        return new IssueLegalDocument(
            commandKey: $key,
            scope: $this->account(),
            invoiceRef: 'inv-'.$suffix,
            economicRef: 'inv-'.$suffix,
            documentRevision: 1,
            finalizationRef: 'fin-'.$suffix,
            approvedSnapshotDigest: $approvedDigest,
            seriesRef: $seriesRef,
            periodKey: $periodKey,
        );
    }
}
