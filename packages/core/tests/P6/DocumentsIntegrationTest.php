<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Data\Money;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Contracts\Runtime\FinancialSessionAdmission;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Core\Tests\Support\Concurrency\WorkerApplication;
use Billium\Core\Tests\Support\FixedCallerContext;
use Billium\Core\Tests\Support\FixedSessionAdmission;
use Billium\Core\Tests\Support\SimulatedFinancialAuthorityEvidence;
use Billium\Documents\Contracts\DocumentCorrectionService;
use Billium\Documents\Contracts\DocumentFinalizationService;
use Billium\Documents\Contracts\DocumentProfileRegistry;
use Billium\Documents\Contracts\DocumentRenderer;
use Billium\Documents\Contracts\DocumentRenderService;
use Billium\Documents\Contracts\DocumentSeriesRegistry;
use Billium\Documents\Contracts\DocumentStorage;
use Billium\Documents\Contracts\DocumentTaxArithmetic;
use Billium\Documents\Contracts\DocumentTaxSnapshotPort;
use Billium\Documents\Contracts\LegalDocumentIssuer;
use Billium\Documents\Contracts\LegalDocumentQueries;
use Billium\Documents\Data\CreateDocumentDraft;
use Billium\Documents\Data\CreateDocumentSeries;
use Billium\Documents\Data\CreditNoteLineRequest;
use Billium\Documents\Data\DocumentLineBasis;
use Billium\Documents\Data\DocumentProfile;
use Billium\Documents\Data\DocumentScope;
use Billium\Documents\Data\DocumentTaxCorrectionPolicy;
use Billium\Documents\Data\DocumentTaxLineSnapshot;
use Billium\Documents\Data\DocumentTaxSnapshot;
use Billium\Documents\Data\IssueDocumentCreditNote;
use Billium\Documents\Data\IssueLegalDocument;
use Billium\Documents\Data\ReadDocumentRender;
use Billium\Documents\Data\RegisterDocumentProfile;
use Billium\Documents\Data\RequestDocumentRender;
use Billium\Documents\Data\TaxArithmeticLineInput;
use Billium\Documents\Data\TaxSnapshotRequest;
use Billium\Documents\Enums\DiscountTaxOrder;
use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\RenderState;
use Billium\Documents\Enums\SeriesPeriodPolicy;
use Billium\Documents\Enums\TaxConvention;
use Billium\Documents\Enums\TaxCorrectionTreatment;
use Billium\Documents\Laravel\DocumentsServiceProvider;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionQueries;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\Fiscal\FiscalDrivers;
use Billium\Execution\Contracts\Fiscal\FiscalOrchestrator;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Data\Fiscal\FiscalIntentRecord;
use Billium\Execution\Data\Fiscal\FiscalPolicyConfig;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ReductionResult;
use Billium\Execution\Enums\Fiscal\FiscalCallbackTrustStrategy;
use Billium\Execution\Enums\Fiscal\FiscalIntentState;
use Billium\Execution\Enums\Fiscal\FiscalOperationKind;
use Billium\Execution\Enums\Fiscal\FiscalReceiptKind;
use Billium\Execution\Enums\Fiscal\FiscalRoute;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\OutcomeDeliveryTest as Delivery;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\CallbackCustodyTest as Inputs;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Contracts\ObservationCheckpointQueries;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Credits\RecordCustomerCredit;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\CreditNoteMode;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Platform\Contracts\Accounting\AccountingConsumer;
use Billium\Platform\Contracts\Accounting\AccountingMapper;
use Billium\Platform\Data\Accounting\AccountingFactCategory;
use Billium\Platform\Data\Accounting\AccountingFactKind;
use Billium\Platform\Data\Accounting\AccountingMappingStatus;
use Billium\Platform\Data\Accounting\AccountingSourceRecord;
use Billium\Platform\Internal\Accounting\AccountingFactMapper;
use Billium\Testkit\Accounting\AccountingExportExtension;
use Billium\Testkit\Accounting\FakeFinBridgeConsumer;
use Billium\Testkit\Documents\InMemoryDocumentRenderer;
use Billium\Testkit\Documents\InMemoryDocumentStorage;
use Billium\Testkit\Fiscal\SimulatedFiscalDriver;
use Billium\Testkit\Fiscal\SimulatedFiscalDrivers;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
final class DocumentsIntegrationTest extends ExecutionTestCase
{
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
        $config->set('billium.modules.enabled', ['platform', 'ledger', 'documents', 'execution']);
        $config->set('billium.documents.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->application()->make(SchemaMigrator::class)->migrateSets(
            ['axiomasoft/billium-core:billium-documents'],
            'billium_owner',
        );
    }

    public function test_end_to_end_lifecycle_issue_render_partial_allocation_credit_note_refund_fiscal_recovery_and_accounting_replay(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $execScope = Fixture::scope();
            $docScope = new DocumentScope('seller-a', 'test');
            $account = new BillingAccountScope('seller-a', 'test', 'billing-a');
            $ledger = $app->make(Ledger::class);
            $this->bindArithmeticTaxPort($app);

            // 1. Host supplies accepted offer/terms snapshot & stable purchase/component identity BEFORE obligations/money ops.
            $acceptedTermsSnapshot = [
                'offerRef' => 'offer-pro-2026-q4',
                'termsVersion' => 'terms-v3',
                'acceptedAt' => '2026-11-19T08:00:00Z',
                'roundingPolicyVersion' => 'half_up/1',
            ];
            $termsDigest = 'sha256:'.hash('sha256', json_encode($acceptedTermsSnapshot, JSON_THROW_ON_ERROR));

            $ledger->recordObligation(new RecordObligation(
                commandKey: 'e2e-ob-1',
                scope: $account,
                obligationRef: 'ob-e2e-1',
                basisRef: 'purchase:ord-e2e:comp-base-seat',
                customerRef: 'cust-e2e',
                componentRef: 'comp-base-seat',
                money: new Money(6000, Currency::of('RUB')),
                termsDigest: $termsDigest,
            ))->orThrow();
            $ledger->recordObligation(new RecordObligation(
                commandKey: 'e2e-ob-2',
                scope: $account,
                obligationRef: 'ob-e2e-2',
                basisRef: 'purchase:ord-e2e:comp-addon-api',
                customerRef: 'cust-e2e',
                componentRef: 'comp-addon-api',
                money: new Money(6000, Currency::of('RUB')),
                termsDigest: $termsDigest,
            ))->orThrow();

            $ledger->createInvoice(new CreateInvoice(
                commandKey: 'e2e-create-inv',
                scope: $account,
                invoiceRef: 'inv-e2e',
                customerRef: 'cust-e2e',
                lines: [
                    new InvoiceLineInput('ob-e2e-1', 1000, ['component' => 'comp-base-seat']),
                    new InvoiceLineInput('ob-e2e-2', 1000, ['component' => 'comp-addon-api']),
                ],
                snapshot: ['buyer' => 'Enterprise Buyer LLC', 'offerRef' => 'offer-pro-2026-q4'],
            ))->orThrow();

            // 2. DocumentProfile + series + tax finalization via DocumentTaxSnapshotPort.
            $profile = $this->registerCompleteProfile($app, $docScope, 'prof-e2e');
            self::assertTrue($profile->isComplete);
            $this->registerSeries($app, $docScope, 'INV', '2026', DocumentType::Invoice);
            $this->registerSeries($app, $docScope, 'CN', '2026', DocumentType::CreditNote);

            $finalizations = $app->make(DocumentFinalizationService::class);
            $finalizations->createDraft(new CreateDocumentDraft(
                scope: $docScope,
                finalizationRef: 'fin-e2e',
                documentType: DocumentType::Invoice,
                economicRef: 'inv-e2e',
                basisRevision: 1,
                basisDigest: $termsDigest,
                profileRef: 'prof-e2e',
                profileVersion: 1,
                currency: 'RUB',
                currencyExponent: 2,
                convention: TaxConvention::Exclusive,
                roundingMode: 'half_up',
                discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
                lines: [
                    new DocumentLineBasis('line-1', 'ob-e2e-1', 'comp-base-seat', '1', '50.00', 5000, 0, 'VAT20', '0.20', 'Base Seat'),
                    new DocumentLineBasis('line-2', 'ob-e2e-2', 'comp-addon-api', '1', '50.00', 5000, 0, 'VAT20', '0.20', 'API Addon'),
                ],
                buyerSnapshot: ['legalName' => 'Enterprise Buyer LLC', 'taxId' => 'RU-7700112233'],
                termsSnapshot: $acceptedTermsSnapshot,
            ));
            $ready = $finalizations->finalizeWithTaxPort($docScope, 'fin-e2e');
            self::assertTrue($ready->attached);
            self::assertSame(10000, $ready->finalization->subtotalMinor);
            self::assertSame(2000, $ready->finalization->taxMinor);
            self::assertSame(12000, $ready->finalization->totalMinor);

            // 3. Atomic legal issue (Ledger invoice issued + INV-2026-000001 allocated in one root Tx).
            $issuer = $app->make(LegalDocumentIssuer::class);
            $issued = $issuer->issueInvoiceDocument(new IssueLegalDocument(
                commandKey: 'e2e-issue-legal',
                scope: $account,
                invoiceRef: 'inv-e2e',
                economicRef: 'inv-e2e',
                documentRevision: 1,
                finalizationRef: 'fin-e2e',
                approvedSnapshotDigest: $ready->finalization->approvedSnapshotDigest ?? '',
                seriesRef: 'INV',
                periodKey: '2026',
            ));
            self::assertTrue($issued->accepted);
            self::assertSame('INV-2026-000001', $issued->body['legalNumber'] ?? null);
            $legalDocRef = self::text($issued->body['legalDocumentRef'] ?? null);

            $docQueries = $app->make(LegalDocumentQueries::class);
            $legalViewBefore = $docQueries->find($docScope, $legalDocRef);
            self::assertNotNull($legalViewBefore);
            $expectedTerms = $acceptedTermsSnapshot;
            $actualTerms = $legalViewBefore->termsSnapshot;
            ksort($expectedTerms);
            ksort($actualTerms);
            self::assertSame($expectedTerms, $actualTerms);
            self::assertSame('issued', $this->value("SELECT status FROM billium_invoice WHERE invoice_ref='inv-e2e'"));

            // 4. Async render & short-lived readRender grant (never persists URL in DB).
            $renderClock = new DateTimeImmutable('2026-11-19T09:00:00Z', new DateTimeZone('UTC'));
            $renderer = new InMemoryDocumentRenderer('in-memory-pdf', '1.0.0');
            $storage = new InMemoryDocumentStorage($renderClock);
            $app->instance(DocumentRenderer::class, $renderer);
            $app->instance(DocumentStorage::class, $storage);

            $renders = $app->make(DocumentRenderService::class);
            $renders->requestRender(new RequestDocumentRender(
                scope: $docScope,
                renderRequestRef: 'rreq-e2e-1',
                legalDocumentRef: $legalDocRef,
                templateId: 'invoice-standard',
                templateVersion: '1.0',
                templateDigest: self::digest('a'),
                locale: 'en-US',
                rendererName: 'in-memory-pdf',
                rendererVersion: '1.0.0',
                rendererDigest: self::digest('b'),
                assetRefs: ['asset://brand/logo.png'],
            ));
            $rendered = $renders->executeRender($docScope, 'rreq-e2e-1');
            self::assertTrue($rendered->attached);
            self::assertSame(RenderState::Ready, $rendered->render->state);
            $readGrant = $renders->readRender(new ReadDocumentRender(
                scope: $docScope,
                legalDocumentRef: $legalDocRef,
                templateId: 'invoice-standard',
                locale: 'en-US',
                ttlSeconds: 60,
            ));
            self::assertNotNull($storage->fetchByTemporaryUrl($readGrant->temporaryUrl, $renderClock->modify('+30 seconds')));
            self::assertNull($storage->fetchByTemporaryUrl($readGrant->temporaryUrl, $renderClock->modify('+61 seconds')));
            self::assertSame(0, $this->rows('billium_document_render_request', "blob_ref LIKE 'https://%'"));

            // 5. Payment capture (100.00 RUB = 10000 minor) targeting ob-e2e-1 (6000) + manual partial allocation (2000) on ob-e2e-2.
            $this->declareSettlementIntent($app, $account, 'intent-a', ['ob-e2e-1']);
            Fixture::seedMerchant($app);
            Fixture::host($app, $path);
            $app->make(RootPaymentCommands::class)->admit($execScope, Fixture::wire([
                'money' => ['amount' => '100.00', 'currency' => 'RUB'],
            ]))->orThrow();
            $app->make(AttemptWorker::class)->dispatch($execScope, UnknownResultTest::attemptRef(), 'worker-cap-e2e', new DateTimeImmutable('+1 minute'))->orThrow();
            $captureEvidence = Inputs::providerInput($app);
            $captureReduced = $app->make(EvidenceReducer::class)->reduce(Inputs::work($captureEvidence));
            self::assertSame(ReductionDisposition::Applied, $captureReduced->disposition);
            $this->deliverObservations($app, $path, $captureReduced);

            self::assertSame(1, $this->rows('billium_payment', "payment_ref='root-a' AND captured_minor=10000 AND allocated_minor=6000 AND refunded_minor=0"));

            // Explicit partial allocation (2000 minor) onto ob-e2e-2 via Ledger::allocate (leaves 4000 open on inv-e2e and 4000 unallocated on root-a).
            $ledger->recordCustomerCredit(new RecordCustomerCredit(
                commandKey: 'e2e-credit-1',
                scope: $account,
                creditRef: 'credit-e2e-1',
                sourceRef: 'promo:e2e-credit-1',
                money: new Money(2000, Currency::of('RUB')),
            ))->orThrow();
            $ledger->allocate(new Allocate(
                commandKey: 'e2e-partial-alloc-2',
                scope: $account,
                allocationRef: 'alloc-e2e-partial-2',
                obligationRef: 'ob-e2e-2',
                money: new Money(2000, Currency::of('RUB')),
                customerCreditRef: 'credit-e2e-1',
            ))->orThrow();
            self::assertSame('partially_paid', $this->value("SELECT status FROM billium_invoice WHERE invoice_ref='inv-e2e'"));
            self::assertSame(8000, self::integer($this->value("SELECT allocated_minor FROM billium_invoice WHERE invoice_ref='inv-e2e'")));
            self::assertSame(4000, self::integer($this->value("SELECT open_minor FROM billium_invoice WHERE invoice_ref='inv-e2e'")));
            self::assertSame(6000, self::integer($this->value("SELECT allocated_minor FROM billium_payment WHERE payment_ref='root-a'")));

            // 6. Partial credit note (4000 minor on line-2 / ob-e2e-2): reduces receivable WITHOUT dispatching any automatic PSP refund!
            $taxCorrectionPolicy = new DocumentTaxCorrectionPolicy(
                policyRef: 'pol-orig-e2e',
                policyVersion: 'v1',
                treatment: TaxCorrectionTreatment::OriginalRate,
                selectedRateDecimal: '0.20',
                taxPoint: '2026-11-19',
                roundingPolicyVersion: 'half_up/1',
                inputDigest: self::digest('c'),
                outputDigest: self::digest('d'),
                resolverVersion: '1.0',
            );
            $cnOutcome = $app->make(DocumentCorrectionService::class)->issueCreditNote(new IssueDocumentCreditNote(
                commandKey: 'e2e-credit-note-1',
                scope: $account,
                correctionRef: 'corr-e2e-cn-1',
                creditNoteRef: 'cn-e2e-1',
                originalLegalDocumentRef: $legalDocRef,
                invoiceRef: 'inv-e2e',
                mode: CreditNoteMode::OpenOnly,
                lines: [new CreditNoteLineRequest('line-2', '0.6667', 4000)],
                taxPolicy: $taxCorrectionPolicy,
                reason: 'partial_service_discount',
                issueLegalDocument: true,
                seriesRef: 'CN',
                periodKey: '2026',
            ));
            self::assertTrue($cnOutcome->accepted);
            self::assertSame('CN-2026-000001', $cnOutcome->body['resultingLegalNumber'] ?? null);
            self::assertSame(4000, self::integer($this->value("SELECT credited_minor FROM billium_invoice WHERE invoice_ref='inv-e2e'")));
            self::assertSame(0, self::integer($this->value("SELECT open_minor FROM billium_invoice WHERE invoice_ref='inv-e2e'")));
            self::assertEquals($legalViewBefore, $docQueries->find($docScope, $legalDocRef));

            // Critical invariant: credit note NEVER dispatches an automatic PSP refund.
            self::assertSame(0, $this->rows('billium_execution_operation', "kind='refund'"));
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(0, self::integer($this->value("SELECT refunded_minor FROM billium_payment WHERE payment_ref='root-a'")));

            // 7. Separately authorized refund (2000 minor = 20.00 RUB) admitted, dispatched, reduced, and projected.
            $app->make(RefundCommands::class)->admit($execScope, Refunds::refund('refund-e2e-1', 2000, 2))->orThrow();
            $app->make(AttemptWorker::class)->dispatch($execScope, Refunds::attemptRef('refund-e2e-1'), 'worker-ref-e2e', new DateTimeImmutable('+1 minute'))->orThrow();
            [$refundEvidence, $refundReduced] = $this->reduceSignedWork($app, 'refund-e2e-1');
            $this->deliverObservations($app, $path, $refundReduced);

            self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(1, $this->rows('billium_payment', "payment_ref='root-a' AND captured_minor=10000 AND allocated_minor=6000 AND refunded_minor=2000 AND NOT financial_hold"));

            // 8. Fiscal dispatch losing registration response (unknown) -> 24h provider outage -> reconcile/lookup recovery.
            $txProbe = static fn (): int => $app->make(DatabaseManager::class)->connection()->transactionLevel();
            $fiscalDriver = new SimulatedFiscalDriver(
                id: 'cloud-fiscal',
                version: 'fiscal-driver.v1',
                supportedRoutes: [FiscalRoute::FiscalDriver],
                txLevelProbe: $txProbe,
            );
            $app->instance(FiscalDrivers::class, new SimulatedFiscalDrivers([$fiscalDriver]));
            $fiscal = $app->make(FiscalOrchestrator::class);
            foreach ([FiscalOperationKind::Charge, FiscalOperationKind::Refund] as $opKind) {
                $fiscal->configurePolicy(new FiscalPolicyConfig(
                    scope: $execScope,
                    executionProfile: 'remote-billium',
                    operationKind: $opKind,
                    receiptRequired: true,
                    fiscalRoute: FiscalRoute::FiscalDriver,
                    driverId: 'cloud-fiscal',
                    driverContractVersion: 'fiscal-driver.v1',
                    callbackTrustStrategy: FiscalCallbackTrustStrategy::HmacSignature,
                ));
            }

            $fiscalIntents = $fiscal->syncActualEffectsForRoot($execScope, 'root-a', 'remote-billium');
            self::assertCount(2, $fiscalIntents);
            $saleIntent = array_values(array_filter($fiscalIntents, static fn (FiscalIntentRecord $i): bool => $i->receiptKind === FiscalReceiptKind::Sale))[0];
            $refundIntent = array_values(array_filter($fiscalIntents, static fn (FiscalIntentRecord $i): bool => $i->receiptKind === FiscalReceiptKind::Refund))[0];
            self::assertSame(10000, $saleIntent->amount->minor);
            self::assertSame(2000, $refundIntent->amount->minor);

            $fiscalDriver->loseRegisterResponseFor($saleIntent->externalId);
            $t0 = new DateTimeImmutable('2026-11-19T10:00:00Z');
            $afterLost = $fiscal->dispatchIntent($execScope, $saleIntent->intentRef, $t0);
            self::assertSame(FiscalIntentState::Unknown, $afterLost->state);
            self::assertSame(1, $fiscalDriver->registerCalls);
            self::assertSame(0, $fiscalDriver->duplicateRegistrations);

            // 24h fiscal outage: retries never re-register, and paid state stays completely intact.
            $fiscalDriver->setOffline(true);
            self::assertSame(FiscalIntentState::Unknown, $fiscal->dispatchIntent($execScope, $saleIntent->intentRef, $t0->modify('+2 hours'))->state);
            self::assertSame(FiscalIntentState::Unknown, $fiscal->lookupIntent($execScope, $saleIntent->intentRef, $t0->modify('+12 hours'))->state);
            self::assertSame(FiscalIntentState::Unknown, $fiscal->lookupIntent($execScope, $saleIntent->intentRef, $t0->modify('+23 hours'))->state);
            self::assertSame(1, $fiscalDriver->registerCalls);
            self::assertSame(0, $fiscalDriver->duplicateRegistrations);
            self::assertSame('succeeded', $app->make(ExecutionQueries::class)->status($execScope, 'root-a')->status);
            self::assertSame(1, $this->rows('billium_payment', "payment_ref='root-a' AND captured_minor=10000 AND refunded_minor=2000"));

            // Outage clears at +24h: lookup recovers original sale receipt with registerCalls === 1 & duplicateRegistrations === 0.
            $fiscalDriver->setOffline(false);
            $recoveredSale = $fiscal->lookupIntent($execScope, $saleIntent->intentRef, $t0->modify('+24 hours'));
            self::assertSame(FiscalIntentState::Registered, $recoveredSale->state);
            self::assertNotNull($recoveredSale->providerReceiptId);
            self::assertNotNull($recoveredSale->fiscalDocumentNumber);
            self::assertSame(1, $fiscalDriver->registerCalls);
            self::assertSame(0, $fiscalDriver->duplicateRegistrations);

            // Dispatch refund fiscal intent cleanly as well.
            $registeredRefund = $fiscal->dispatchIntent($execScope, $refundIntent->intentRef, $t0->modify('+24 hours 5 minutes'));
            self::assertSame(FiscalIntentState::Registered, $registeredRefund->state);
            self::assertSame(2, $fiscalDriver->registerCalls);
            self::assertSame(0, $fiscalDriver->duplicateRegistrations);

            // 9. Accounting export replay (N=3 passes): 1 CashIn, 1 CashOut, separate NoncashAllocations, linked CreditNote, separate delivery ACK vs applied receipt.
            $mapper = $app->make(AccountingMapper::class);
            self::assertInstanceOf(AccountingFactMapper::class, $mapper);
            $consumer = new FakeFinBridgeConsumer('finbridge:e2e');
            $exportAt = new DateTimeImmutable('2026-11-20T10:30:00Z');

            $captureFactRecord = new AccountingSourceRecord(
                schemaVersion: 'accounting.source.v1',
                eventType: 'payment.captured.v1',
                installationRef: self::INSTALLATION,
                sellerRef: 'seller-a',
                environment: 'test',
                sourceLineage: 'execution:remote-billium',
                aggregateRef: 'root-a',
                aggregateRevision: 1,
                occurredAt: $exportAt,
                payload: [
                    'operationRef' => 'root-a',
                    'status' => 'succeeded',
                    'providerAccountRef' => 'merchant-a',
                    'providerEffectRef' => $captureEvidence->evidenceRef,
                    'providerEvidenceRef' => $captureEvidence->evidenceRef,
                    'effectMoney' => ['amount' => '100.00', 'currency' => 'RUB'],
                ],
            );
            $mappedCap = $mapper->map($captureFactRecord);
            self::assertSame(AccountingMappingStatus::Mapped, $mappedCap->status);
            self::assertNotNull($mappedCap->fact);
            $captureFactRef = $mappedCap->fact->sourceFactRef;

            $refundFactRecord = new AccountingSourceRecord(
                schemaVersion: 'accounting.source.v1',
                eventType: 'refund.succeeded.v1',
                installationRef: self::INSTALLATION,
                sellerRef: 'seller-a',
                environment: 'test',
                sourceLineage: 'execution:remote-billium',
                aggregateRef: 'refund-e2e-1',
                aggregateRevision: 1,
                occurredAt: $exportAt,
                payload: [
                    'operationRef' => 'refund-e2e-1',
                    'originalOperationRef' => 'root-a',
                    'status' => 'succeeded',
                    'providerAccountRef' => 'merchant-a',
                    'providerEffectRef' => $refundEvidence->evidenceRef,
                    'providerEvidenceRef' => $refundEvidence->evidenceRef,
                    'effectMoney' => ['amount' => '20.00', 'currency' => 'RUB'],
                    'originalFactRef' => $captureFactRef,
                ],
            );
            $mappedRef = $mapper->map($refundFactRecord);
            self::assertSame(AccountingMappingStatus::Mapped, $mappedRef->status);
            self::assertNotNull($mappedRef->fact);
            $refundFactRef = $mappedRef->fact->sourceFactRef;

            $outboxRows = $this->table('SELECT event_id, type, schema_version, aggregate_ref, aggregate_revision, payload, occurred_at FROM billium_outbox_event ORDER BY id');
            self::assertNotEmpty($outboxRows);

            for ($pass = 1; $pass <= 3; $pass++) {
                $consumer->consume($captureFactRecord, $mapper, "e2e-cap-pass-{$pass}");
                $consumer->consume($refundFactRecord, $mapper, "e2e-ref-pass-{$pass}");
                foreach ($outboxRows as $row) {
                    /** @var array<string, mixed> $payload */
                    $payload = json_decode(self::text($row['payload']), true, 64, JSON_THROW_ON_ERROR);
                    $consumer->consume(new AccountingSourceRecord(
                        schemaVersion: self::text($row['schema_version']),
                        eventType: self::text($row['type']),
                        installationRef: self::INSTALLATION,
                        sellerRef: 'seller-a',
                        environment: 'test',
                        sourceLineage: 'ledger:core',
                        aggregateRef: self::text($row['aggregate_ref']),
                        aggregateRevision: self::integer($row['aggregate_revision']),
                        occurredAt: new DateTimeImmutable(self::text($row['occurred_at'])),
                        payload: $payload,
                        deliveryAttemptRef: self::text($row['event_id']).":pass-{$pass}",
                    ), $mapper);
                }
            }

            // N=3 replays preserve strictly 1 CashIn (10000), 1 CashOut (2000), 2 separate NoncashAllocations (6000 + 2000 = 8000), 1 Invoice (12000), 1 linked CreditNote (4000).
            self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::CashIn));
            self::assertSame(10000, $consumer->totalMinorByCategory(AccountingFactCategory::CashIn, 'RUB'));
            self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::CashOut));
            self::assertSame(2000, $consumer->totalMinorByCategory(AccountingFactCategory::CashOut, 'RUB'));
            self::assertSame(2, $consumer->appliedCount(AccountingFactCategory::NoncashAllocation, AccountingFactKind::AllocationApplied));
            self::assertSame(8000, $consumer->totalMinorByKind(AccountingFactKind::AllocationApplied, 'RUB'));
            self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::Receivable, AccountingFactKind::InvoiceIssued));
            self::assertSame(12000, $consumer->totalMinorByKind(AccountingFactKind::InvoiceIssued, 'RUB'));
            self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::Receivable, AccountingFactKind::CreditNoteIssued));
            self::assertSame(4000, $consumer->totalMinorByKind(AccountingFactKind::CreditNoteIssued, 'RUB'));

            $invoiceEnv = $consumer->appliedEnvelopes(AccountingFactCategory::Receivable, AccountingFactKind::InvoiceIssued)[0];
            $creditNoteEnv = $consumer->appliedEnvelopes(AccountingFactCategory::Receivable, AccountingFactKind::CreditNoteIssued)[0];
            $refundEnv = $consumer->appliedEnvelopes(AccountingFactCategory::CashOut)[0];
            self::assertSame($invoiceEnv->sourceFactRef, $creditNoteEnv->originalFactRef);
            self::assertSame($captureFactRef, $refundEnv->originalFactRef);

            // Delivery ACK count reflects all 3 replays while applied receipt stays strictly single and idempotent.
            self::assertSame(3, $consumer->deliveryAckCount($captureFactRef));
            self::assertSame(3, $consumer->deliveryAckCount($refundFactRef));
            $appliedCapReceipt = $consumer->receiptFor($captureFactRef);
            self::assertNotNull($appliedCapReceipt);
            self::assertFalse($appliedCapReceipt->replayed);
            $replayedCapReceipt = $consumer->apply($consumer->appliedEnvelopes(AccountingFactCategory::CashIn)[0]);
            self::assertTrue($replayedCapReceipt->replayed);
            self::assertSame($appliedCapReceipt->receiptRef, $replayedCapReceipt->receiptRef);
            self::assertSame(0, $consumer->glWriteCount());
        } finally {
            $this->cleanPath($path);
        }
    }

    #[Group('COV-006')]
    #[Group('COV-364')]
    #[Group('COV-365')]
    #[Group('COV-372')]
    #[Group('COV-523')]
    public function test_separate_receipt_levels_stay_independent_across_fulfilment_timeout_fiscal_outage_and_payment_replays(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $execScope = Fixture::scope();
            $docScope = new DocumentScope('seller-a', 'test');
            $account = new BillingAccountScope('seller-a', 'test', 'billing-a');
            $ledger = $app->make(Ledger::class);
            $this->bindArithmeticTaxPort($app);

            // Host supplies accepted offer/terms snapshot & stable purchase/component identity before obligations (COV-364).
            $offerSnapshot = [
                'offerRef' => 'offer-bundle-sep-1',
                'termsVersion' => 'terms-2026-v1',
                'purchaseRef' => 'purchase-sep-001',
                'roundingPolicyVersion' => 'half_up/1',
            ];
            $termsDigest = 'sha256:'.hash('sha256', json_encode($offerSnapshot, JSON_THROW_ON_ERROR));

            $ledger->recordObligation(new RecordObligation(
                commandKey: 'sep-ob-1',
                scope: $account,
                obligationRef: 'ob-sep-1',
                basisRef: 'purchase:purchase-sep-001:comp-license-core',
                customerRef: 'cust-sep',
                componentRef: 'comp-license-core',
                money: new Money(7500, Currency::of('RUB')),
                termsDigest: $termsDigest,
            ))->orThrow();
            $ledger->recordObligation(new RecordObligation(
                commandKey: 'sep-ob-2',
                scope: $account,
                obligationRef: 'ob-sep-2',
                basisRef: 'purchase:purchase-sep-001:comp-storage-pack',
                customerRef: 'cust-sep',
                componentRef: 'comp-storage-pack',
                money: new Money(4500, Currency::of('RUB')),
                termsDigest: $termsDigest,
            ))->orThrow();
            $ledger->createInvoice(new CreateInvoice(
                commandKey: 'sep-create-inv',
                scope: $account,
                invoiceRef: 'inv-sep',
                customerRef: 'cust-sep',
                lines: [
                    new InvoiceLineInput('ob-sep-1', 1250, ['component' => 'comp-license-core']),
                    new InvoiceLineInput('ob-sep-2', 750, ['component' => 'comp-storage-pack']),
                ],
                snapshot: ['buyer' => 'Host Tenant Corp', 'purchaseRef' => 'purchase-sep-001'],
            ))->orThrow();

            // Receipt Level 1: Legal Document receipt.
            $this->registerCompleteProfile($app, $docScope, 'prof-sep');
            $this->registerSeries($app, $docScope, 'INV', '2026', DocumentType::Invoice);
            $finalizations = $app->make(DocumentFinalizationService::class);
            $finalizations->createDraft(new CreateDocumentDraft(
                scope: $docScope,
                finalizationRef: 'fin-sep',
                documentType: DocumentType::Invoice,
                economicRef: 'inv-sep',
                basisRevision: 1,
                basisDigest: $termsDigest,
                profileRef: 'prof-sep',
                profileVersion: 1,
                currency: 'RUB',
                currencyExponent: 2,
                convention: TaxConvention::Exclusive,
                roundingMode: 'half_up',
                discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
                lines: [
                    new DocumentLineBasis('line-core', 'ob-sep-1', 'comp-license-core', '1', '62.50', 6250, 0, 'VAT20', '0.20', 'Core License'),
                    new DocumentLineBasis('line-pack', 'ob-sep-2', 'comp-storage-pack', '1', '37.50', 3750, 0, 'VAT20', '0.20', 'Storage Pack'),
                ],
                buyerSnapshot: ['legalName' => 'Host Tenant Corp', 'taxId' => 'RU-7799887766'],
                termsSnapshot: $offerSnapshot,
            ));
            $ready = $finalizations->finalizeWithTaxPort($docScope, 'fin-sep');
            $issuedDoc = $app->make(LegalDocumentIssuer::class)->issueInvoiceDocument(new IssueLegalDocument(
                commandKey: 'sep-issue-legal',
                scope: $account,
                invoiceRef: 'inv-sep',
                economicRef: 'inv-sep',
                documentRevision: 1,
                finalizationRef: 'fin-sep',
                approvedSnapshotDigest: $ready->finalization->approvedSnapshotDigest ?? '',
                seriesRef: 'INV',
                periodKey: '2026',
            ));
            self::assertTrue($issuedDoc->accepted);
            $legalDocRef = self::text($issuedDoc->body['legalDocumentRef'] ?? null);
            $legalView = $app->make(LegalDocumentQueries::class)->find($docScope, $legalDocRef);
            self::assertNotNull($legalView);
            $legalReceiptRef = $legalView->receiptRef;

            // Receipt Level 2: Payment capture + N=4 observation replays (COV-006, COV-365).
            $this->declareSettlementIntent($app, $account, 'intent-a', ['ob-sep-1', 'ob-sep-2']);
            Fixture::seedMerchant($app);
            Fixture::host($app, $path);
            $app->make(RootPaymentCommands::class)->admit($execScope, Fixture::wire([
                'money' => ['amount' => '120.00', 'currency' => 'RUB'],
            ]))->orThrow();
            $app->make(AttemptWorker::class)->dispatch($execScope, UnknownResultTest::attemptRef(), 'worker-sep', new DateTimeImmutable('+1 minute'))->orThrow();
            $captureEvidence = Inputs::providerInput($app);
            $captureReduced = $app->make(EvidenceReducer::class)->reduce(Inputs::work($captureEvidence));
            self::assertSame(ReductionDisposition::Applied, $captureReduced->disposition);
            $observations = $this->deliverObservations($app, $path, $captureReduced);
            self::assertCount(1, $observations);
            $obsCommandRef = $captureReduced->observationCommandRefs[0];

            // Replay PaymentObservation N=4 times: PSP charge count stays 1, component money stays exact!
            for ($replay = 1; $replay <= 4; $replay++) {
                Delivery::duplicateDelivery($app, $obsCommandRef);
            }
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));

            $selection = new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium');
            $checkpoint = $app->make(ObservationCheckpointQueries::class)->checkpoint($selection, $observations[0]);
            self::assertNotNull($checkpoint);
            self::assertTrue($checkpoint->projectionApplied);
            self::assertNotNull($checkpoint->allocationReceipt);
            $paymentReceiptRef = $checkpoint->allocationReceipt->receiptRef;

            $queries = $app->make(LedgerQueries::class);
            $obCore = $queries->obligation('ob-sep-1');
            $obPack = $queries->obligation('ob-sep-2');
            self::assertSame(7500, Money::fromWire($obCore['allocated'])->minor);
            self::assertSame(0, Money::fromWire($obCore['open'])->minor);
            self::assertSame(4500, Money::fromWire($obPack['allocated'])->minor);
            self::assertSame(0, Money::fromWire($obPack['open'])->minor);
            self::assertSame('paid', $queries->invoice('inv-sep')['status']);
            self::assertSame(2, $this->rows('billium_allocation'));

            // Receipt Level 3: Fiscal adapter state machine experiences lost response & provider outage (COV-372, COV-523).
            $fiscalDriver = new SimulatedFiscalDriver(
                id: 'cloud-fiscal-sep',
                version: 'fiscal-driver.v1',
                supportedRoutes: [FiscalRoute::FiscalDriver],
            );
            $app->instance(FiscalDrivers::class, new SimulatedFiscalDrivers([$fiscalDriver]));
            $fiscal = $app->make(FiscalOrchestrator::class);
            $fiscal->configurePolicy(new FiscalPolicyConfig(
                scope: $execScope,
                executionProfile: 'remote-billium',
                operationKind: FiscalOperationKind::Charge,
                receiptRequired: true,
                fiscalRoute: FiscalRoute::FiscalDriver,
                driverId: 'cloud-fiscal-sep',
                driverContractVersion: 'fiscal-driver.v1',
            ));
            $saleIntent = $fiscal->syncActualEffectsForRoot($execScope, 'root-a', 'remote-billium')[0];
            $fiscalDriver->loseRegisterResponseFor($saleIntent->externalId);
            $t0 = new DateTimeImmutable('2026-11-19T11:00:00Z');
            $fiscalUnknown = $fiscal->dispatchIntent($execScope, $saleIntent->intentRef, $t0);
            self::assertSame(FiscalIntentState::Unknown, $fiscalUnknown->state);
            self::assertNull($fiscalUnknown->providerReceiptId);

            // Fiscal provider is now completely down.
            $fiscalDriver->setOffline(true);
            self::assertSame(FiscalIntentState::Unknown, $fiscal->lookupIntent($execScope, $saleIntent->intentRef, $t0->modify('+30 minutes'))->state);

            // Receipt Level 4: Accounting receipt applies independently while fiscal provider is down and fulfilment has not activated.
            $mapper = $app->make(AccountingMapper::class);
            $consumer = new FakeFinBridgeConsumer('finbridge:sep');
            $capRes = $consumer->consume(new AccountingSourceRecord(
                schemaVersion: 'accounting.source.v1',
                eventType: 'payment.captured.v1',
                installationRef: self::INSTALLATION,
                sellerRef: 'seller-a',
                environment: 'test',
                sourceLineage: 'execution:remote-billium',
                aggregateRef: 'root-a',
                aggregateRevision: 1,
                occurredAt: $t0,
                payload: [
                    'operationRef' => 'root-a',
                    'status' => 'succeeded',
                    'providerAccountRef' => 'merchant-a',
                    'providerEffectRef' => $captureEvidence->evidenceRef,
                    'effectMoney' => ['amount' => '120.00', 'currency' => 'RUB'],
                ],
                deliveryAttemptRef: 'sep-acc-1',
            ), $mapper);
            self::assertSame(AccountingMappingStatus::Mapped, $capRes->status);
            self::assertNotNull($capRes->fact);
            $accountingReceipt = $consumer->receiptFor($capRes->fact->sourceFactRef);
            self::assertNotNull($accountingReceipt);
            $accountingReceiptRef = $accountingReceipt->receiptRef;

            // Receipt Level 5: Host provisioning/fulfilment double experiences timeout then delayed activation while fiscal is STILL down (COV-006, COV-364, COV-365, COV-372).
            $hostFulfilment = new class
            {
                public int $attempts = 0;

                public int $activationCount = 0;

                public bool $timeoutNext = true;

                /** @var array<string, string> purchaseRef => hostFulfilmentReceiptRef */
                private array $receipts = [];

                /** @param list<string> $components */
                public function onPaymentEvidence(string $purchaseRef, string $paymentReceiptRef, array $components): ?string
                {
                    $this->attempts++;
                    if (isset($this->receipts[$purchaseRef])) {
                        return $this->receipts[$purchaseRef];
                    }
                    if ($this->timeoutNext) {
                        $this->timeoutNext = false;

                        return null;
                    }
                    $this->activationCount++;
                    $this->receipts[$purchaseRef] = sprintf(
                        'host-fulfil:%s:%d:%s',
                        $purchaseRef,
                        $this->activationCount,
                        implode('+', $components),
                    );

                    return $this->receipts[$purchaseRef];
                }
            };

            // Attempt 1: provisioning timeout -> fulfilment receipt is null while payment, legal, accounting receipts stay committed.
            $firstFulfil = $hostFulfilment->onPaymentEvidence('purchase-sep-001', $paymentReceiptRef, ['comp-license-core', 'comp-storage-pack']);
            self::assertNull($firstFulfil);
            self::assertSame(0, $hostFulfilment->activationCount);
            self::assertSame('succeeded', $app->make(ExecutionQueries::class)->status($execScope, 'root-a')->status);
            self::assertSame('paid', $queries->invoice('inv-sep')['status']);
            $intentDuringTimeout = $fiscal->findIntent($execScope, $saleIntent->intentRef);
            self::assertNotNull($intentDuringTimeout);
            self::assertSame(FiscalIntentState::Unknown, $intentDuringTimeout->state);

            // Replay payment observation once more during provisioning failure: zero second PSP charge.
            Delivery::duplicateDelivery($app, $obsCommandRef);
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));

            // Attempt 2 (delayed activation while fiscal provider is STILL down) + Attempt 3 (replay): idempotent host fulfilment receipt.
            $hostFulfilmentReceiptRef = $hostFulfilment->onPaymentEvidence('purchase-sep-001', $paymentReceiptRef, ['comp-license-core', 'comp-storage-pack']);
            self::assertNotNull($hostFulfilmentReceiptRef);
            self::assertSame($hostFulfilmentReceiptRef, $hostFulfilment->onPaymentEvidence('purchase-sep-001', $paymentReceiptRef, ['comp-license-core', 'comp-storage-pack']));
            self::assertSame(1, $hostFulfilment->activationCount);
            $intentAfterFulfil = $fiscal->findIntent($execScope, $saleIntent->intentRef);
            self::assertNotNull($intentAfterFulfil);
            self::assertSame(FiscalIntentState::Unknown, $intentAfterFulfil->state);

            // Finally fiscal provider recovers via lookup (COV-523): separate fiscal receipt obtained with 0 duplicate registrations & 1 PSP charge.
            $fiscalDriver->setOffline(false);
            $fiscalRecovered = $fiscal->lookupIntent($execScope, $saleIntent->intentRef, $t0->modify('+2 hours'));
            self::assertSame(FiscalIntentState::Registered, $fiscalRecovered->state);
            self::assertNotNull($fiscalRecovered->providerReceiptId);
            $fiscalReceiptRef = $fiscalRecovered->providerReceiptId;
            self::assertSame(1, $fiscalDriver->registerCalls);
            self::assertSame(0, $fiscalDriver->duplicateRegistrations);
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));

            // All 5 receipt levels exist independently with strictly distinct identities.
            $allReceipts = [
                $paymentReceiptRef,
                $legalReceiptRef,
                $fiscalReceiptRef,
                $accountingReceiptRef,
                $hostFulfilmentReceiptRef,
            ];
            self::assertCount(5, array_unique($allReceipts));
        } finally {
            $this->cleanPath($path);
        }
    }

    public function test_disabling_documents_and_missing_or_backpressured_finbridge_keeps_core_ledger_api_functional(): void
    {
        $hostApp = WorkerApplication::create();
        try {
            $hostApp->instance(CallerContext::class, FixedCallerContext::default(installationRef: self::INSTALLATION));
            $hostApp->instance(FinancialSessionAdmission::class, new FixedSessionAdmission(FixedSessionAdmission::token(self::INSTALLATION)));
            SimulatedFinancialAuthorityEvidence::admitCurrent($hostApp);

            $config = $hostApp->make(Repository::class);
            $config->set('billium.modules.enabled', ['platform', 'ledger']);
            $config->set('billium.documents.enabled', false);

            // Verify optional documents module and FinBridge consumer are absent.
            self::assertSame([], $hostApp->getProviders(DocumentsServiceProvider::class));
            self::assertFalse($hostApp->bound(LegalDocumentIssuer::class));
            self::assertFalse($hostApp->bound(AccountingConsumer::class));

            // Simulate backpressured FinBridge consumer outside core ledger.
            $consumer = new FakeFinBridgeConsumer('finbridge:backpressured');
            $consumer->setBackpressure(true, 'finbridge_outage');
            $extension = new AccountingExportExtension;
            $mapper = $hostApp->make(AccountingMapper::class);

            $ledger = $hostApp->make(Ledger::class);
            $queries = $hostApp->make(LedgerQueries::class);
            $account = new BillingAccountScope('seller-a', 'test', 'account-indep');

            // 1. RecordObligation
            $obOutcome = $ledger->recordObligation(new RecordObligation(
                commandKey: 'indep-ob-1',
                scope: $account,
                obligationRef: 'ob-indep-1',
                basisRef: 'basis-indep-1',
                customerRef: 'cust-indep',
                componentRef: 'comp-core',
                money: new Money(5000, Currency::of('RUB')),
                termsDigest: self::digest('e'),
            ))->orThrow();
            self::assertTrue($obOutcome->accepted);

            // 2. CreateInvoice
            $createInv = $ledger->createInvoice(new CreateInvoice(
                commandKey: 'indep-create-inv',
                scope: $account,
                invoiceRef: 'inv-indep-1',
                customerRef: 'cust-indep',
                lines: [new InvoiceLineInput('ob-indep-1')],
            ))->orThrow();
            self::assertTrue($createInv->accepted);

            // 3. IssueInvoice (works without Documents module)
            $issueInv = $ledger->issueInvoice(new IssueInvoice(
                commandKey: 'indep-issue-inv',
                scope: $account,
                invoiceRef: 'inv-indep-1',
            ))->orThrow();
            self::assertTrue($issueInv->accepted);

            // 4. Allocate against a captured payment projection
            $this->owner()->insert(<<<'SQL'
                INSERT INTO billium_payment(installation_ref,seller_ref,environment,billing_account_ref,currency,payment_ref,source_authority,source_lineage,operation_ref,kind,captured_minor)
                VALUES('inst-test','seller-a','test','account-indep','RUB','pay-indep-1','authority-a','lineage-a','op-indep-1','charge',5000)
                SQL);
            $allocOutcome = $ledger->allocate(new Allocate(
                commandKey: 'indep-alloc-1',
                scope: $account,
                allocationRef: 'alloc-indep-1',
                obligationRef: 'ob-indep-1',
                money: new Money(3000, Currency::of('RUB')),
                paymentRef: 'pay-indep-1',
            ))->orThrow();
            self::assertTrue($allocOutcome->accepted);

            // 5. LedgerQueries stay 100% functional
            $invoiceView = $queries->invoice('inv-indep-1');
            self::assertSame('partially_paid', $invoiceView['status']);
            self::assertSame(5000, Money::fromWire($invoiceView['total'])->minor);
            self::assertSame(3000, Money::fromWire($invoiceView['allocated'])->minor);
            self::assertSame(2000, Money::fromWire($invoiceView['open'])->minor);

            $obligationView = $queries->obligation('ob-indep-1');
            self::assertSame(3000, Money::fromWire($obligationView['allocated'])->minor);
            self::assertSame(2000, Money::fromWire($obligationView['open'])->minor);

            $receiptBody = $queries->receipt('allocation:alloc-indep-1');
            self::assertNotEmpty($receiptBody);

            // Backpressured FinBridge consumer defers outbox rows cleanly without disturbing Ledger; drains cleanly after recovery.
            $outboxRows = $this->table("SELECT event_id, type, schema_version, aggregate_ref, aggregate_revision, payload, occurred_at FROM billium_outbox_event WHERE type IN ('invoice.issued.v1', 'allocation.applied.v1') ORDER BY id");
            self::assertCount(2, $outboxRows);

            foreach ($outboxRows as $row) {
                /** @var array<string, mixed> $payload */
                $payload = json_decode(self::text($row['payload']), true, 64, JSON_THROW_ON_ERROR);
                $source = new AccountingSourceRecord(
                    schemaVersion: self::text($row['schema_version']),
                    eventType: self::text($row['type']),
                    installationRef: self::INSTALLATION,
                    sellerRef: 'seller-a',
                    environment: 'test',
                    sourceLineage: 'ledger:core',
                    aggregateRef: self::text($row['aggregate_ref']),
                    aggregateRevision: self::integer($row['aggregate_revision']),
                    occurredAt: new DateTimeImmutable(self::text($row['occurred_at'])),
                    payload: $payload,
                    deliveryAttemptRef: self::text($row['event_id']).':indep-1',
                );
                try {
                    $consumer->consume($source, $mapper);
                    self::fail('Expected RuntimeException during FinBridge consumer backpressure');
                } catch (RuntimeException $e) {
                    self::assertStringContainsString('finbridge_outage', $e->getMessage());
                }
                self::assertFalse($extension->export($source, $mapper, $consumer));
            }
            self::assertSame(2, $extension->deferredCount());
            self::assertSame(0, $consumer->appliedCount());
            self::assertSame('partially_paid', $queries->invoice('inv-indep-1')['status']);

            $consumer->setBackpressure(false);
            self::assertSame(2, $extension->drainDeferred($mapper, $consumer));
            self::assertSame(0, $extension->deferredCount());
            self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::Receivable, AccountingFactKind::InvoiceIssued));
            self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::NoncashAllocation, AccountingFactKind::AllocationApplied));
            self::assertSame(5000, $consumer->totalMinorByCategory(AccountingFactCategory::Receivable, 'RUB'));
            self::assertSame(3000, $consumer->totalMinorByKind(AccountingFactKind::AllocationApplied, 'RUB'));
        } finally {
            $databases = $hostApp->make(DatabaseManager::class);
            foreach (array_keys($databases->getConnections()) as $name) {
                $databases->purge($name);
            }
        }
    }

    private function registerCompleteProfile(Application $app, DocumentScope $scope, string $profileRef): DocumentProfile
    {
        return $app->make(DocumentProfileRegistry::class)->register(new RegisterDocumentProfile(
            scope: $scope,
            profileRef: $profileRef,
            version: 1,
            jurisdictionRef: 'RU-MOW',
            supportedTypes: [DocumentType::Invoice, DocumentType::CreditNote, DocumentType::Correction],
            numberingPolicy: ['seriesRef' => 'INV', 'periodPolicy' => SeriesPeriodPolicy::Yearly->value],
            taxSnapshotSchema: 'v1',
            correctionPolicy: ['taxTreatment' => 'original_rate'],
            requiredFields: ['seller.legalName', 'seller.taxId', 'buyer.legalName'],
            retentionPolicyRef: 'ret-10y',
            sellerSnapshot: ['legalName' => 'Acme Seller LLC', 'taxId' => 'RU-7700000001', 'address' => '100 Main St'],
        ));
    }

    private function registerSeries(Application $app, DocumentScope $scope, string $seriesRef, string $periodKey, DocumentType $type): void
    {
        $app->make(DocumentSeriesRegistry::class)->createSeries(new CreateDocumentSeries(
            scope: $scope,
            seriesRef: $seriesRef,
            documentType: $type,
            periodKey: $periodKey,
            periodPolicy: SeriesPeriodPolicy::Yearly,
            timezone: 'UTC',
            prefix: $seriesRef,
            padding: 6,
            legalEntityRevision: 1,
        ));
    }

    private function bindArithmeticTaxPort(Application $app): void
    {
        $arithmetic = $app->make(DocumentTaxArithmetic::class);
        $app->instance(DocumentTaxSnapshotPort::class, new class($arithmetic) implements DocumentTaxSnapshotPort
        {
            public function __construct(private DocumentTaxArithmetic $arithmetic) {}

            public function resolve(TaxSnapshotRequest $request): DocumentTaxSnapshot
            {
                $inputs = [];
                foreach ($request->lines as $line) {
                    if (! is_numeric($line->quantity) || ! is_numeric($line->unitPriceDecimal)) {
                        throw new RuntimeException('Line quantity and unitPriceDecimal must be numeric strings');
                    }
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
                $metaByLine = [];
                foreach ($request->lines as $basis) {
                    $metaByLine[$basis->lineRef] = $basis;
                }
                $lines = [];
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
                    resolver: 'integration-tax-port',
                    resolverVersion: '1.0',
                    noTaxReason: $request->convention === TaxConvention::NoneDeclared ? 'zero_rated_supply' : null,
                );
            }
        });
    }

    /** @param list<string> $obligationRefs */
    private function declareSettlementIntent(Application $app, BillingAccountScope $account, string $intentRef, array $obligationRefs): void
    {
        $ledger = $app->make(LedgerParticipants::class);
        $spec = $this->spec(financialScope: new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium'));
        $this->runner()->run($spec, static fn (TransactionContext $ctx) => $ledger->declareSettlementIntent(
            $ctx,
            new DeclareSettlementIntent('declare-'.$intentRef, $account, $intentRef, 'RUB', $obligationRefs),
        ))->orThrow();
    }

    /** @return array{0: ProviderEvidence, 1: ReductionResult} */
    private function reduceSignedWork(Application $app, string $operation): array
    {
        $work = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint=? AND state='due'", [$operation]));
        $input = $app->make(ExecutionStore::class)->providerWork($work);
        self::assertNotNull($input);
        $result = $app->make(EvidenceReducer::class)->reduce($work);
        self::assertSame(ReductionDisposition::Applied, $result->disposition);

        return [$input->evidence, $result];
    }

    /** @return list<PaymentObservation> */
    private function deliverObservations(Application $app, string $path, ReductionResult $result): array
    {
        $messages = [];
        foreach ($result->observationCommandRefs as $ref) {
            $messages[] = Delivery::attest($app, $path, $ref);
        }
        $delivered = 0;
        for ($i = 0; $i < count($messages) && $delivered < count($messages); $i++) {
            $delivered += Delivery::relay($app, $path)->delivered;
        }
        self::assertSame(count($messages), $delivered);

        return $messages;
    }

    private function cleanPath(string $path): void
    {
        UnknownResultTest::clean($path);
        foreach ([$path.'.origins', $path.'.delivery'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private static function digest(string $char = 'a'): string
    {
        return 'sha256:'.str_repeat($char, 64);
    }
}
