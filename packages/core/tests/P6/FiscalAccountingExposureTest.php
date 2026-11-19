<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Envelopes\PaymentObservation;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Documents\Contracts\DocumentFinalizationService;
use Billium\Documents\Contracts\DocumentProfileRegistry;
use Billium\Documents\Contracts\DocumentSeriesRegistry;
use Billium\Documents\Contracts\LegalDocumentIssuer;
use Billium\Documents\Contracts\LegalDocumentQueries;
use Billium\Documents\Data\AttachTaxSnapshot;
use Billium\Documents\Data\CreateDocumentDraft;
use Billium\Documents\Data\CreateDocumentSeries;
use Billium\Documents\Data\DocumentLineBasis;
use Billium\Documents\Data\DocumentScope;
use Billium\Documents\Data\DocumentTaxLineSnapshot;
use Billium\Documents\Data\DocumentTaxSnapshot;
use Billium\Documents\Data\IssueLegalDocument;
use Billium\Documents\Data\LegalDocumentView;
use Billium\Documents\Data\RegisterDocumentProfile;
use Billium\Documents\Enums\DiscountTaxOrder;
use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\SeriesPeriodPolicy;
use Billium\Documents\Enums\TaxConvention;
use Billium\Documents\Laravel\DocumentsServiceProvider;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\CallbackCustody;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\ExecutionStore;
use Billium\Execution\Contracts\Fiscal\FiscalDrivers;
use Billium\Execution\Contracts\Fiscal\FiscalOrchestrator;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Data\Fiscal\FiscalCallbackEnvelope;
use Billium\Execution\Data\Fiscal\FiscalExternalOutcome;
use Billium\Execution\Data\Fiscal\FiscalIntentRecord;
use Billium\Execution\Data\Fiscal\FiscalPolicyConfig;
use Billium\Execution\Data\Fiscal\FiscalProjectionRecord;
use Billium\Execution\Data\ProviderEvidence;
use Billium\Execution\Data\ReductionResult;
use Billium\Execution\Enums\Fiscal\FiscalCallbackTrustStrategy;
use Billium\Execution\Enums\Fiscal\FiscalIntentState;
use Billium\Execution\Enums\Fiscal\FiscalOperationKind;
use Billium\Execution\Enums\Fiscal\FiscalReceiptKind;
use Billium\Execution\Enums\Fiscal\FiscalRoute;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Internal\Fiscal\ActualEffectExtractor;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\OutcomeDeliveryTest as Delivery;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest as Refunds;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Execution\Tests\Feature\CallbackCustodyTest as Inputs;
use Billium\Execution\Tests\Feature\HistoricalMerchantRefundTest;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Platform\Contracts\Accounting\AccountingMapper;
use Billium\Platform\Data\Accounting\AccountingFactCategory;
use Billium\Platform\Data\Accounting\AccountingFactKind;
use Billium\Platform\Data\Accounting\AccountingMappingStatus;
use Billium\Platform\Data\Accounting\AccountingSourceRecord;
use Billium\Platform\Data\Accounting\FinBridgeExportEnvelope;
use Billium\Testkit\Accounting\FakeFinBridgeConsumer;
use Billium\Testkit\Drivers\ResponseMode;
use Billium\Testkit\Fiscal\SimulatedFiscalDriver;
use Billium\Testkit\Fiscal\SimulatedFiscalDrivers;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integrated ACC-RST-12 cross-module exposure fixture (Execution + Ledger + Documents + Fiscal + Accounting).
 *
 * Proves capture 100 + two confirmed actual refunds 70 + 70 produce:
 * - 1 sale fiscal intent (100) + 2 refund fiscal intents (70 + 70 = 140, 2nd marked exceptional, NOT suppressed);
 * - 1 CashIn fact (100, counted separately) + 2 refund CashOut facts (70 + 70 = 140) + 1 NoncashExposure (40, never counted as CashOut);
 * - Observed refunds 140, applied bounded ledger projection retains last consistent 100 / 70, financial_hold = true, spendable = 0;
 * - Frozen legal document snapshot & legal number remain byte-identical;
 * - 0 automatic compensating charges/refunds;
 * - N replay permutations of callbacks, lookups, and outbox records preserve identical identities, counts, and amounts;
 * - embedded-commerz variant records 2 projected refund fiscal outcomes and dispatches 0 provider sends.
 */
#[Group('pg')]
#[Group('ACC-RST-12')]
final class FiscalAccountingExposureTest extends ExecutionTestCase
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

    public function test_integrated_acc_rst_12_capture_100_two_refunds_70_plus_70_fiscal_accounting_ledger_and_documents_under_replay(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $execScope = Fixture::scope();
            $docScope = new DocumentScope('seller-a', 'test');

            [
                'legalDocBefore' => $legalDocBefore,
                'captureEvidence' => $captureEvidence,
                'replacementEvidence' => $replacementEvidence,
                'lateEvidence' => $lateEvidence,
                'lateReduced' => $lateReduced,
            ] = $this->setupIntegratedAccRst12Fixture($app, $path);

            // 1. Assert Execution + bounded Ledger state: observed refunds 140, applied 100 / 70, financial_hold = true, spendable = 0, 0 automatic compensation.
            self::assertSame(1, $this->rows('billium_execution_operation_revision', "operation_ref='root-a' AND (observed->'refundedTotal'->>'minor')::int=140 AND (exposure->'overRefunded'->>'minor')::int=40 AND (last_consistent_applied->'confirmedRefund'->>'minor')::int=70"));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=70 AND held_refund_minor=0 AND financial_hold'));
            self::assertSame(1, $this->rows('billium_payment', "operation_ref='root-a' AND captured_minor=100 AND refunded_minor=70 AND financial_hold"));
            self::assertSame(3, $this->rows('billium_execution_operation'));
            self::assertSame(3, $this->rows('billium_execution_attempt'));
            self::assertSame(3, UnknownResultTest::events($path, 'provider.call'));

            $paymentStatus = $app->make(LedgerQueries::class)->paymentStatus('root-a', 'remote-billium');
            $observed = self::arr($paymentStatus['observed']);
            $applied = self::arr($paymentStatus['applied']);
            $exposure = self::arr($paymentStatus['exposure']);
            $hold = self::arr($paymentStatus['financialHold']);
            self::assertSame(140, Money::fromWire($observed['refundedTotal'])->minor);
            self::assertSame(100, Money::fromWire($applied['capturedTotal'])->minor);
            self::assertSame(70, Money::fromWire($applied['refundedTotal'])->minor);
            self::assertSame(40, Money::fromWire($exposure['overRefunded'])->minor);
            self::assertTrue($hold['active']);
            self::assertSame(0, Money::fromWire($paymentStatus['spendable'])->minor);

            // 2. Fiscal: configure synthetic policy and sync actual effects -> 1 sale (100) + 2 refunds (70 + 70 = 140, 2nd exceptional with provenance, NOT suppressed).
            $txProbe = static fn (): int => $app->make(DatabaseManager::class)->connection()->transactionLevel();
            $fiscalDriver = new SimulatedFiscalDriver(
                id: 'cloud-fiscal-acc12',
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
                    driverId: 'cloud-fiscal-acc12',
                    driverContractVersion: 'fiscal-driver.v1',
                    callbackTrustStrategy: FiscalCallbackTrustStrategy::HmacSignature,
                ));
            }

            $intents = $fiscal->syncActualEffectsForRoot($execScope, 'root-a', 'remote-billium');
            self::assertCount(3, $intents);

            $sales = array_values(array_filter($intents, static fn (FiscalIntentRecord $i): bool => $i->receiptKind === FiscalReceiptKind::Sale));
            $refundIntents = array_values(array_filter($intents, static fn (FiscalIntentRecord $i): bool => $i->receiptKind === FiscalReceiptKind::Refund));
            self::assertCount(1, $sales);
            self::assertSame(100, $sales[0]->amount->minor);
            self::assertFalse($sales[0]->exceptional);

            self::assertCount(2, $refundIntents);
            self::assertSame(140, array_sum(array_map(static fn (FiscalIntentRecord $i): int => $i->amount->minor, $refundIntents)));

            $exceptionalRefunds = array_values(array_filter($refundIntents, static fn (FiscalIntentRecord $i): bool => $i->exceptional));
            self::assertCount(1, $exceptionalRefunds);
            self::assertSame('old', $exceptionalRefunds[0]->operationRef);
            self::assertSame(70, $exceptionalRefunds[0]->amount->minor);
            self::assertArrayHasKey('discrepancyRef', $exceptionalRefunds[0]->exposureProvenance);
            self::assertArrayHasKey('exposure', $exceptionalRefunds[0]->exposureProvenance);

            $normalRefunds = array_values(array_filter($refundIntents, static fn (FiscalIntentRecord $i): bool => ! $i->exceptional));
            self::assertCount(1, $normalRefunds);
            self::assertSame('replacement', $normalRefunds[0]->operationRef);
            self::assertSame(70, $normalRefunds[0]->amount->minor);

            // Dispatch all 3 fiscal intents; 3 registrations, 0 duplicate registrations.
            foreach ($intents as $intent) {
                $done = $fiscal->dispatchIntent($execScope, $intent->intentRef);
                self::assertSame(FiscalIntentState::Registered, $done->state);
            }
            self::assertSame(3, $fiscalDriver->registerCalls);
            self::assertSame(0, $fiscalDriver->duplicateRegistrations);

            // 3. Accounting: 1 CashIn (100), 2 CashOut (70 + 70 = 140), 1 NoncashExposure (40, NEVER counted as CashOut), plus bounded Receivable & NoncashAllocation from outbox.
            $mapper = $app->make(AccountingMapper::class);
            $consumer = new FakeFinBridgeConsumer('finbridge:acc-rst-12');
            $at = new DateTimeImmutable('2026-11-19T16:00:00Z');

            $captureRecord = new AccountingSourceRecord(
                schemaVersion: 'accounting.source.v1',
                eventType: 'payment.captured.v1',
                installationRef: self::INSTALLATION,
                sellerRef: 'seller-a',
                environment: 'test',
                sourceLineage: 'execution:remote-billium',
                aggregateRef: 'root-a',
                aggregateRevision: 1,
                occurredAt: $at,
                payload: [
                    'operationRef' => 'root-a',
                    'status' => 'succeeded',
                    'providerAccountRef' => 'merchant-a',
                    'providerEffectRef' => $captureEvidence->evidenceRef,
                    'providerEvidenceRef' => $captureEvidence->evidenceRef,
                    'effectMoney' => ['amount' => '1.00', 'currency' => 'RUB'],
                ],
                deliveryAttemptRef: 'acc12-cap-1',
            );
            $captureFact = $mapper->map($captureRecord)->fact;
            self::assertNotNull($captureFact);
            $captureFactRef = $captureFact->sourceFactRef;

            $refund1Record = new AccountingSourceRecord(
                schemaVersion: 'accounting.source.v1',
                eventType: 'refund.succeeded.v1',
                installationRef: self::INSTALLATION,
                sellerRef: 'seller-a',
                environment: 'test',
                sourceLineage: 'execution:remote-billium',
                aggregateRef: 'replacement',
                aggregateRevision: 1,
                occurredAt: $at,
                payload: [
                    'operationRef' => 'replacement',
                    'originalOperationRef' => 'root-a',
                    'status' => 'succeeded',
                    'providerAccountRef' => 'merchant-a',
                    'providerEffectRef' => $replacementEvidence->evidenceRef,
                    'providerEvidenceRef' => $replacementEvidence->evidenceRef,
                    'effectMoney' => ['amount' => '0.70', 'currency' => 'RUB'],
                    'originalFactRef' => $captureFactRef,
                    'exceptional' => false,
                ],
                deliveryAttemptRef: 'acc12-ref-1',
            );
            $refund2Record = new AccountingSourceRecord(
                schemaVersion: 'accounting.source.v1',
                eventType: 'refund.succeeded.v1',
                installationRef: self::INSTALLATION,
                sellerRef: 'seller-a',
                environment: 'test',
                sourceLineage: 'execution:remote-billium',
                aggregateRef: 'old',
                aggregateRevision: 2,
                occurredAt: $at,
                payload: [
                    'operationRef' => 'old',
                    'originalOperationRef' => 'root-a',
                    'status' => 'succeeded',
                    'providerAccountRef' => 'merchant-a',
                    'providerEffectRef' => $lateEvidence->evidenceRef,
                    'providerEvidenceRef' => $lateEvidence->evidenceRef,
                    'effectMoney' => ['amount' => '0.70', 'currency' => 'RUB'],
                    'originalFactRef' => $captureFactRef,
                    'exceptional' => true,
                ],
                deliveryAttemptRef: 'acc12-ref-2',
            );
            $ref1Fact = $mapper->map($refund1Record)->fact;
            $ref2Fact = $mapper->map($refund2Record)->fact;
            self::assertNotNull($ref1Fact);
            self::assertNotNull($ref2Fact);
            self::assertNotSame($ref1Fact->sourceFactRef, $ref2Fact->sourceFactRef);

            $exposureRecord = new AccountingSourceRecord(
                schemaVersion: 'accounting.source.v1',
                eventType: 'exposure.over_refund.v1',
                installationRef: self::INSTALLATION,
                sellerRef: 'seller-a',
                environment: 'test',
                sourceLineage: 'execution:remote-billium',
                aggregateRef: 'root-a',
                aggregateRevision: 3,
                occurredAt: $at,
                payload: [
                    'rootOperationRef' => 'root-a',
                    'reason' => 'over_refund',
                    'discrepancyRef' => $lateReduced->discrepancyRef,
                    'exposureMoney' => ['amount' => '0.40', 'currency' => 'RUB'],
                    'originalFactRef' => $captureFactRef,
                    'linkedCashFactRefs' => [$ref1Fact->sourceFactRef, $ref2Fact->sourceFactRef],
                    'observedRefundedMinor' => 140,
                    'appliedRefundedMinor' => 70,
                    'appliedCapturedMinor' => 100,
                    'contributingEffects' => [
                        ['providerEffectRef' => $captureEvidence->evidenceRef, 'revision' => 1, 'digest' => $captureEvidence->payloadDigest],
                        ['providerEffectRef' => $replacementEvidence->evidenceRef, 'revision' => 1, 'digest' => $replacementEvidence->payloadDigest],
                        ['providerEffectRef' => $lateEvidence->evidenceRef, 'revision' => 18, 'digest' => $lateEvidence->payloadDigest],
                    ],
                ],
                deliveryAttemptRef: 'acc12-exp-1',
            );

            $outboxRecords = $this->outboxSourceRecords();
            self::assertNotEmpty($outboxRecords);

            foreach ([$captureRecord, $refund1Record, $refund2Record, $exposureRecord] as $record) {
                self::assertSame(AccountingMappingStatus::Mapped, $consumer->consume($record, $mapper)->status);
            }
            foreach ($outboxRecords as $outboxRecord) {
                $consumer->consume($outboxRecord, $mapper);
            }

            // Assert exact ACC-RST-12 accounting totals: CashIn 100, CashOut 140 (NOT 180!), NoncashExposure 40, Receivable 100, NoncashAllocation 100.
            self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::CashIn));
            self::assertSame(100, $consumer->totalMinorByCategory(AccountingFactCategory::CashIn, 'RUB'));
            self::assertSame(2, $consumer->appliedCount(AccountingFactCategory::CashOut, AccountingFactKind::RefundSucceeded));
            $totalCashOutMinor = $consumer->totalMinorByCategory(AccountingFactCategory::CashOut, 'RUB');
            self::assertSame(140, $totalCashOutMinor);
            self::assertNotSame(180, $totalCashOutMinor);
            self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::NoncashExposure, AccountingFactKind::OverRefundExposure));
            self::assertSame(40, $consumer->totalMinorByCategory(AccountingFactCategory::NoncashExposure, 'RUB'));
            self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::Receivable, AccountingFactKind::InvoiceIssued));
            self::assertSame(100, $consumer->totalMinorByKind(AccountingFactKind::InvoiceIssued, 'RUB'));
            self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::NoncashAllocation, AccountingFactKind::AllocationApplied));
            self::assertSame(100, $consumer->totalMinorByKind(AccountingFactKind::AllocationApplied, 'RUB'));
            self::assertSame(0, $consumer->glWriteCount());

            $exposureEnv = $consumer->appliedEnvelopes(AccountingFactCategory::NoncashExposure)[0];
            $expectedLinked = [$ref1Fact->sourceFactRef, $ref2Fact->sourceFactRef];
            sort($expectedLinked, SORT_STRING);
            self::assertSame($expectedLinked, $exposureEnv->linkedCashFactRefs);
            self::assertSame($captureFactRef, $exposureEnv->originalFactRef);
            self::assertTrue($exposureEnv->exceptional);

            // 4. Frozen legal document snapshot & legal number remain byte-identical across entire over-refund hold.
            $legalDocAfter = $app->make(LegalDocumentQueries::class)->find($docScope, $legalDocBefore->legalDocumentRef);
            self::assertEquals($legalDocBefore, $legalDocAfter);
            self::assertSame('INV-2026-000001', $legalDocAfter?->legalNumber);
            self::assertSame(0, $this->rows('billium_document_correction'));

            // 5. N=6 replay permutations of provider callbacks, fiscal lookups/callbacks/effects, and accounting outbox/fact records preserve identical IDs, counts, and amounts.
            $baselineFiscalExternalIds = array_map(static fn (FiscalIntentRecord $i): string => $i->externalId, $intents);
            sort($baselineFiscalExternalIds, SORT_STRING);
            $extractedEffects = $app->make(ActualEffectExtractor::class)->extractForRoot($execScope, 'root-a', 'remote-billium');
            self::assertCount(3, $extractedEffects);

            $exposureReversed = new AccountingSourceRecord(
                schemaVersion: $exposureRecord->schemaVersion,
                eventType: $exposureRecord->eventType,
                installationRef: $exposureRecord->installationRef,
                sellerRef: $exposureRecord->sellerRef,
                environment: $exposureRecord->environment,
                sourceLineage: $exposureRecord->sourceLineage,
                aggregateRef: $exposureRecord->aggregateRef,
                aggregateRevision: 9,
                occurredAt: $at,
                payload: array_replace($exposureRecord->payload, [
                    'linkedCashFactRefs' => [$ref2Fact->sourceFactRef, $ref1Fact->sourceFactRef],
                    'contributingEffects' => array_reverse(self::arr($exposureRecord->payload['contributingEffects'])),
                ]),
                deliveryAttemptRef: 'acc12-exp-reversed',
            );

            $baselineAccountingRefs = array_map(static fn (FinBridgeExportEnvelope $e): string => $e->sourceFactRef, $consumer->appliedEnvelopes());
            sort($baselineAccountingRefs, SORT_STRING);

            $callbackReplaySets = [
                [['root-a', $captureEvidence], ['replacement', $replacementEvidence], ['old', $lateEvidence]],
                [['old', $lateEvidence], ['root-a', $captureEvidence], ['replacement', $replacementEvidence]],
                [['replacement', $replacementEvidence], ['old', $lateEvidence], ['root-a', $captureEvidence]],
            ];
            $custody = $app->make(CallbackCustody::class);
            $reducer = $app->make(EvidenceReducer::class);
            foreach ($callbackReplaySets as $cbSet) {
                foreach ($cbSet as [$opRef, $evidence]) {
                    $custody->receive($execScope, 'merchant-a', $opRef, $evidence)->orThrow();
                    self::assertSame(ReductionDisposition::Duplicate, $reducer->reduce(Inputs::work($evidence))->disposition);
                }
            }

            $effectPermutations = [
                [0, 1, 2],
                [0, 2, 1],
                [1, 0, 2],
                [1, 2, 0],
                [2, 0, 1],
                [2, 1, 0],
            ];
            foreach ($effectPermutations as $permIdx => $order) {
                $replayedExternalIds = [];
                foreach ($order as $idx) {
                    $prepared = $fiscal->prepareIntent($extractedEffects[$idx]);
                    self::assertNotNull($prepared);
                    $replayedExternalIds[] = $prepared->externalId;

                    $lookedUp = $fiscal->lookupIntent($execScope, $prepared->intentRef);
                    self::assertSame(FiscalIntentState::Registered, $lookedUp->state);

                    $cbPayload = [
                        'state' => 'registered',
                        'providerReceiptId' => $lookedUp->providerReceiptId,
                        'fiscalDocumentNumber' => $lookedUp->fiscalDocumentNumber,
                    ];
                    $eventId = "acc12-cb-replay-{$permIdx}-{$idx}";
                    $sig = $fiscalDriver->signCallback($lookedUp->externalId, $eventId, FiscalCallbackTrustStrategy::HmacSignature, $cbPayload);
                    $afterCb = $fiscal->ingestCallback(new FiscalCallbackEnvelope(
                        scope: $execScope,
                        externalId: $lookedUp->externalId,
                        driverId: 'cloud-fiscal-acc12',
                        driverContractVersion: 'fiscal-driver.v1',
                        externalEventId: $eventId,
                        trustStrategy: FiscalCallbackTrustStrategy::HmacSignature,
                        signature: $sig,
                        payload: $cbPayload,
                        receivedAt: $at->modify("+{$permIdx} minutes"),
                    ));
                    self::assertSame(FiscalIntentState::Registered, $afterCb->state);
                }
                sort($replayedExternalIds, SORT_STRING);
                self::assertSame($baselineFiscalExternalIds, $replayedExternalIds);
            }

            $accountingPermutations = [
                [$captureRecord, $refund1Record, $refund2Record, $exposureRecord, ...$outboxRecords],
                [$refund2Record, $exposureReversed, ...array_reverse($outboxRecords), $captureRecord, $refund1Record],
                [$exposureReversed, $refund1Record, $captureRecord, $refund2Record, ...$outboxRecords],
                [...$outboxRecords, $refund1Record, $refund2Record, $captureRecord, $exposureRecord],
                [$captureRecord, $exposureReversed, $refund2Record, $refund1Record, ...array_reverse($outboxRecords)],
                [$refund2Record, $refund1Record, $exposureRecord, $captureRecord, ...$outboxRecords],
            ];
            foreach ($accountingPermutations as $permIdx => $batch) {
                $freshConsumer = new FakeFinBridgeConsumer("finbridge:acc12-perm-{$permIdx}");
                foreach ($batch as $step => $item) {
                    $attempt = "acc12-perm-{$permIdx}-step-{$step}";
                    $consumer->consume($item, $mapper, $attempt);
                    $freshConsumer->consume($item, $mapper, $attempt);
                }

                $permRefs = array_map(static fn (FinBridgeExportEnvelope $e): string => $e->sourceFactRef, $freshConsumer->appliedEnvelopes());
                sort($permRefs, SORT_STRING);
                self::assertSame($baselineAccountingRefs, $permRefs);
                self::assertSame(1, $freshConsumer->appliedCount(AccountingFactCategory::CashIn));
                self::assertSame(100, $freshConsumer->totalMinorByCategory(AccountingFactCategory::CashIn, 'RUB'));
                self::assertSame(2, $freshConsumer->appliedCount(AccountingFactCategory::CashOut));
                self::assertSame(140, $freshConsumer->totalMinorByCategory(AccountingFactCategory::CashOut, 'RUB'));
                self::assertSame(1, $freshConsumer->appliedCount(AccountingFactCategory::NoncashExposure));
                self::assertSame(40, $freshConsumer->totalMinorByCategory(AccountingFactCategory::NoncashExposure, 'RUB'));
                self::assertSame(1, $freshConsumer->appliedCount(AccountingFactCategory::Receivable, AccountingFactKind::InvoiceIssued));
                self::assertSame(100, $freshConsumer->totalMinorByKind(AccountingFactKind::InvoiceIssued, 'RUB'));
                self::assertSame(1, $freshConsumer->appliedCount(AccountingFactCategory::NoncashAllocation, AccountingFactKind::AllocationApplied));
                self::assertSame(100, $freshConsumer->totalMinorByKind(AccountingFactKind::AllocationApplied, 'RUB'));
            }

            // Final invariants after all replay permutations: identical counts, 0 duplicate registrations, 0 automatic compensation, legal doc unchanged.
            self::assertSame(3, $this->rows('billium_fiscal_intent'));
            self::assertSame(3, $fiscalDriver->registerCalls);
            self::assertSame(0, $fiscalDriver->duplicateRegistrations);
            self::assertSame(6, $consumer->appliedCount());
            self::assertSame(140, $consumer->totalMinorByCategory(AccountingFactCategory::CashOut, 'RUB'));
            self::assertSame(40, $consumer->totalMinorByCategory(AccountingFactCategory::NoncashExposure, 'RUB'));
            self::assertSame(3, $this->rows('billium_execution_attempt'));
            self::assertSame(3, UnknownResultTest::events($path, 'provider.call'));
            self::assertEquals($legalDocBefore, $app->make(LegalDocumentQueries::class)->find($docScope, $legalDocBefore->legalDocumentRef));
            self::assertSame(1, $this->rows('billium_execution_capacity', 'confirmed_capture_minor=100 AND confirmed_refund_minor=70 AND held_refund_minor=0 AND financial_hold'));
        } finally {
            $this->cleanPath($path);
        }
    }

    public function test_embedded_commerz_acc_rst_12_records_two_refund_fiscal_projections_and_dispatches_zero_provider_sends(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $execScope = Fixture::scope();
            $docScope = new DocumentScope('seller-a', 'test');

            ['legalDocBefore' => $legalDocBefore] = $this->setupIntegratedAccRst12Fixture($app, $path);

            $unusedDriver = new SimulatedFiscalDriver(
                id: 'unused-fiscal-driver',
                version: 'fiscal-driver.v1',
                supportedRoutes: [FiscalRoute::FiscalDriver],
            );
            $app->instance(FiscalDrivers::class, new SimulatedFiscalDrivers([$unusedDriver]));
            $fiscal = $app->make(FiscalOrchestrator::class);

            foreach ([FiscalOperationKind::Charge, FiscalOperationKind::Refund] as $opKind) {
                $fiscal->configurePolicy(new FiscalPolicyConfig(
                    scope: $execScope,
                    executionProfile: 'embedded-commerz',
                    operationKind: $opKind,
                    receiptRequired: true,
                    fiscalRoute: FiscalRoute::ProjectionOnly,
                ));
            }

            // embedded-commerz MUST refuse intent creation/provider dispatch.
            HistoricalMerchantRefundTest::problem(
                ProblemCode::CapabilityUnsupported,
                static fn () => $fiscal->syncActualEffectsForRoot($execScope, 'root-a', 'embedded-commerz'),
            );

            // Without authenticated external fiscal outcomes, embedded-commerz records 0 projections (never fabricates registered).
            self::assertSame([], $fiscal->syncActualProjectionsForRoot($execScope, 'root-a', 'embedded-commerz'));
            self::assertSame(0, $this->rows('billium_fiscal_projection'));

            // Supply genuine authenticated external fiscal outcomes for capture (100) + two confirmed refunds (70 + 70).
            $extracted = $app->make(ActualEffectExtractor::class)->extractForRoot($execScope, 'root-a', 'embedded-commerz');
            self::assertCount(3, $extracted);
            $externalOutcomes = [];
            foreach ($extracted as $effect) {
                $extRef = match ($effect->operationRef) {
                    'root-a' => 'commerz-acc12-sale-100',
                    'replacement' => 'commerz-acc12-refund-replacement-70',
                    default => 'commerz-acc12-refund-old-70',
                };
                $externalOutcomes[] = new FiscalExternalOutcome(
                    operationRef: $effect->operationRef,
                    economicEffectRef: $effect->economicEffectRef,
                    receiptKind: $effect->receiptKind,
                    fiscalStatus: 'registered',
                    evidenceDigest: 'sha256:'.hash('sha256', $extRef.':'.$effect->economicEffectRef),
                    externalFiscalRef: $extRef,
                    correctionRevision: 0,
                    sourcePayload: [
                        'fiscalProvider' => 'commerz-ofd',
                        'externalReceiptNo' => $extRef,
                    ],
                );
            }

            // Sync projections (and replay idempotently in reversed order): 1 sale (100) + 2 refunds (70 + 70 = 140), 0 fiscal intents, 0 provider sends.
            $projections = $fiscal->syncActualProjectionsForRoot($execScope, 'root-a', 'embedded-commerz', $externalOutcomes);
            $replayed = $fiscal->syncActualProjectionsForRoot($execScope, 'root-a', 'embedded-commerz', array_reverse($externalOutcomes));
            self::assertCount(3, $projections);
            self::assertCount(3, $replayed);

            $refundProjections = array_values(array_filter(
                $projections,
                static fn (FiscalProjectionRecord $p): bool => $p->receiptKind === FiscalReceiptKind::Refund,
            ));
            self::assertCount(2, $refundProjections);
            self::assertSame(140, array_sum(array_map(static fn (FiscalProjectionRecord $p): int => $p->amount->minor, $refundProjections)));
            self::assertCount(1, array_filter($refundProjections, static fn (FiscalProjectionRecord $p): bool => $p->exceptional));
            foreach ($projections as $projection) {
                self::assertSame('registered', $projection->fiscalStatus);
                self::assertNotNull($projection->externalFiscalRef);
                self::assertStringStartsWith('commerz-acc12-', $projection->externalFiscalRef);
            }

            self::assertSame(3, $this->rows('billium_fiscal_projection'));
            self::assertSame(0, $this->rows('billium_fiscal_projection', "external_fiscal_ref LIKE 'source-receipt:%'"));
            self::assertSame(2, $this->rows('billium_fiscal_projection', "receipt_kind='refund' AND execution_profile='embedded-commerz'"));
            self::assertSame(0, $this->rows('billium_fiscal_intent'));
            self::assertSame(0, $unusedDriver->registerCalls);
            self::assertSame(0, $unusedDriver->lookupCalls);
            self::assertSame(3, UnknownResultTest::events($path, 'provider.call'));
            self::assertEquals($legalDocBefore, $app->make(LegalDocumentQueries::class)->find($docScope, $legalDocBefore->legalDocumentRef));
            self::assertSame(1, $this->rows('billium_payment', "operation_ref='root-a' AND captured_minor=100 AND refunded_minor=70 AND financial_hold"));
        } finally {
            $this->cleanPath($path);
        }
    }

    /**
     * Sets up the full integrated ACC-RST-12 fixture across Documents + Ledger + Execution:
     * legal invoice 100 (1.00 RUB), capture 100, confirmed refund `replacement` (70) + late confirmed refund `old` (70).
     *
     * @return array{
     *     legalDocBefore: LegalDocumentView,
     *     captureEvidence: ProviderEvidence,
     *     replacementEvidence: ProviderEvidence,
     *     lateEvidence: ProviderEvidence,
     *     lateReduced: ReductionResult
     * }
     */
    private function setupIntegratedAccRst12Fixture(Application $app, string $path): array
    {
        $execScope = Fixture::scope();
        $docScope = new DocumentScope('seller-a', 'test');
        $account = new BillingAccountScope('seller-a', 'test', 'billing-a');
        $ledger = $app->make(Ledger::class);

        // 1. Legal invoice issued for 100 minor (1.00 RUB).
        $app->make(DocumentProfileRegistry::class)->register(new RegisterDocumentProfile(
            scope: $docScope,
            profileRef: 'prof-acc12',
            version: 1,
            jurisdictionRef: 'RU-MOW',
            supportedTypes: [DocumentType::Invoice, DocumentType::CreditNote, DocumentType::Correction],
            numberingPolicy: ['seriesRef' => 'INV', 'periodPolicy' => SeriesPeriodPolicy::Yearly->value],
            taxSnapshotSchema: 'v1',
            correctionPolicy: ['taxTreatment' => 'original_rate'],
            requiredFields: ['seller.legalName', 'seller.taxId', 'buyer.legalName'],
            retentionPolicyRef: 'ret-10y',
            sellerSnapshot: ['legalName' => 'Acme Seller LLC', 'taxId' => 'RU-7700000001'],
        ));
        $app->make(DocumentSeriesRegistry::class)->createSeries(new CreateDocumentSeries(
            scope: $docScope,
            seriesRef: 'INV',
            documentType: DocumentType::Invoice,
            periodKey: '2026',
            periodPolicy: SeriesPeriodPolicy::Yearly,
            timezone: 'UTC',
            prefix: 'INV',
            padding: 6,
            legalEntityRevision: 1,
        ));

        $ledger->recordObligation(new RecordObligation(
            commandKey: 'acc12-ob-1',
            scope: $account,
            obligationRef: 'ob-acc12',
            basisRef: 'purchase:acc12:comp-1',
            customerRef: 'cust-acc12',
            componentRef: 'comp-1',
            money: new Money(100, Currency::of('RUB')),
            termsDigest: 'sha256:'.str_repeat('a', 64),
        ))->orThrow();
        $ledger->createInvoice(new CreateInvoice(
            commandKey: 'acc12-create-inv',
            scope: $account,
            invoiceRef: 'inv-acc12',
            customerRef: 'cust-acc12',
            lines: [new InvoiceLineInput('ob-acc12', 0, ['name' => 'Micro Service'])],
            snapshot: ['buyer' => 'ACC-RST-12 Buyer LLC'],
        ))->orThrow();

        $finalizations = $app->make(DocumentFinalizationService::class);
        $finalizations->createDraft(new CreateDocumentDraft(
            scope: $docScope,
            finalizationRef: 'fin-acc12',
            documentType: DocumentType::Invoice,
            economicRef: 'inv-acc12',
            basisRevision: 1,
            basisDigest: 'sha256:'.str_repeat('b', 64),
            profileRef: 'prof-acc12',
            profileVersion: 1,
            currency: 'RUB',
            currencyExponent: 2,
            convention: TaxConvention::Exclusive,
            roundingMode: 'half_up',
            discountTaxOrder: DiscountTaxOrder::DiscountBeforeTax,
            lines: [new DocumentLineBasis('line-1', 'ob-acc12', 'comp-1', '1', '1.00', 100, 0, 'VAT0', '0.00', 'Micro Service')],
            buyerSnapshot: ['legalName' => 'ACC-RST-12 Buyer LLC', 'taxId' => 'RU-7712345678'],
            termsSnapshot: ['offerRef' => 'offer-acc12', 'roundingPolicyVersion' => 'half_up/1'],
        ));
        $started = $finalizations->startFinalization($docScope, 'fin-acc12');
        $ready = $finalizations->attachTaxSnapshot(new AttachTaxSnapshot(
            scope: $docScope,
            finalizationRef: 'fin-acc12',
            finalizationRevision: $started->finalizationRevision,
            inputDigest: $started->inputDigest,
            snapshot: new DocumentTaxSnapshot(
                taxSnapshotRef: 'tsnap-acc12',
                taxPolicyVersion: 'v1',
                taxPoint: '2026-11-19',
                convention: TaxConvention::Exclusive,
                currency: 'RUB',
                currencyExponent: 2,
                subtotalMinor: 100,
                taxMinor: 0,
                totalMinor: 100,
                lines: [new DocumentTaxLineSnapshot('line-1', 'VAT0', '0.00', 100, 0, 100)],
                inputDigest: $started->inputDigest,
                evidenceDigest: 'sha256:'.str_repeat('c', 64),
                resolver: 'acc12-tax-port',
                resolverVersion: '1.0',
            ),
        ))->finalization;

        $issued = $app->make(LegalDocumentIssuer::class)->issueInvoiceDocument(new IssueLegalDocument(
            commandKey: 'acc12-issue-legal',
            scope: $account,
            invoiceRef: 'inv-acc12',
            economicRef: 'inv-acc12',
            documentRevision: 1,
            finalizationRef: 'fin-acc12',
            approvedSnapshotDigest: $ready->approvedSnapshotDigest ?? '',
            seriesRef: 'INV',
            periodKey: '2026',
        ));
        self::assertTrue($issued->accepted);
        self::assertSame('INV-2026-000001', $issued->body['legalNumber'] ?? null);
        $legalDocRef = self::text($issued->body['legalDocumentRef'] ?? null);
        $legalDocBefore = $app->make(LegalDocumentQueries::class)->find($docScope, $legalDocRef);
        self::assertNotNull($legalDocBefore);

        // 2. Declare settlement intent for ob-acc12 so payment observation projects onto billium_payment.
        $participants = $app->make(LedgerParticipants::class);
        $spec = $this->spec(financialScope: new FinancialScope(self::INSTALLATION, 'seller-a', 'test', 'source-a', 'remote-billium'));
        $this->runner()->run($spec, static fn (TransactionContext $ctx) => $participants->declareSettlementIntent(
            $ctx,
            new DeclareSettlementIntent('acc12-intent', $account, 'intent-a', 'RUB', ['ob-acc12']),
        ))->orThrow();

        // 3. Capture 100 minor (1.00 RUB) and project onto Ledger.
        $captureEvidence = Inputs::dispatched($app, $path);
        $captureReduced = $app->make(EvidenceReducer::class)->reduce(Inputs::work($captureEvidence));
        self::assertSame(ReductionDisposition::Applied, $captureReduced->disposition);
        $this->deliverObservations($app, $path, $captureReduced);

        // 4. Admit refund `old` (70) -> declined at provider so reservation releases -> admit & confirm `replacement` (70).
        $commands = $app->make(RefundCommands::class);
        $commands->admit($execScope, Refunds::refund('old', 70, 2))->orThrow();
        Fixture::host($app, $path, mode: ResponseMode::Declined);
        $app->make(AttemptWorker::class)->dispatch($execScope, Refunds::attemptRef('old'), 'worker-old', new DateTimeImmutable('+1 minute'))->orThrow();
        [$oldDeclinedEvidence, $oldDeclinedReduced] = $this->reduceSignedWork($app, 'old');
        $this->deliverObservations($app, $path, $oldDeclinedReduced);
        Fixture::host($app, $path);

        $commands->admit($execScope, Refunds::refund('replacement', 70, 3))->orThrow();
        $app->make(AttemptWorker::class)->dispatch($execScope, Refunds::attemptRef('replacement'), 'worker-replacement', new DateTimeImmutable('+1 minute'))->orThrow();
        [$replacementEvidence, $replacementReduced] = $this->reduceSignedWork($app, 'replacement');
        $this->deliverObservations($app, $path, $replacementReduced);

        // 5. Late authenticated provider evidence confirms `old` (70) also succeeded => observed 140, exposure 40, HeldDiscrepancy.
        $lateEvidence = Inputs::signed($oldDeclinedEvidence, [
            'revision' => '18',
            'status' => 'succeeded',
            'operationRef' => 'old',
            'reference' => self::text($this->value("SELECT provider_reference FROM billium_execution_attempt WHERE operation_ref='old'")),
            'refunded' => ['amount' => '1.40', 'currency' => 'RUB'],
            'operationAmount' => ['amount' => '0.70', 'currency' => 'RUB'],
        ]);
        $app->make(CallbackCustody::class)->receive($execScope, 'merchant-a', 'old', $lateEvidence)->orThrow();
        $lateReduced = $app->make(EvidenceReducer::class)->reduce(Inputs::work($lateEvidence));
        self::assertSame(ReductionDisposition::HeldDiscrepancy, $lateReduced->disposition);
        self::assertNotNull($lateReduced->discrepancyRef);
        $this->deliverObservations($app, $path, $lateReduced);

        return [
            'legalDocBefore' => $legalDocBefore,
            'captureEvidence' => $captureEvidence,
            'replacementEvidence' => $replacementEvidence,
            'lateEvidence' => $lateEvidence,
            'lateReduced' => $lateReduced,
        ];
    }

    /** @return list<AccountingSourceRecord> */
    private function outboxSourceRecords(): array
    {
        $rows = $this->table('SELECT event_id, type, schema_version, aggregate_ref, aggregate_revision, payload, occurred_at FROM billium_outbox_event ORDER BY id');
        $records = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode(self::text($row['payload']), true, 64, JSON_THROW_ON_ERROR);
            $records[] = new AccountingSourceRecord(
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
                deliveryAttemptRef: self::text($row['event_id']),
            );
        }

        return $records;
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
}
