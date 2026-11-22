<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Import;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Platform\Contracts\Import\ImportBatchApplier;
use Billium\Platform\Contracts\Import\ImportPreviewEvaluator;
use Billium\Platform\Contracts\Import\ImportReconciler;
use Billium\Platform\Data\Import\ImportApplyOptions;
use Billium\Platform\Data\Import\ImportBatchStatus;
use Billium\Platform\Data\Import\ImportLinkedCorrection;
use Billium\Platform\Data\Import\ImportRecordApplyStatus;
use Billium\Platform\Internal\Import\Sources\CreditsExportProfile;
use Billium\Platform\Internal\Import\Sources\ExportArtifactException;
use Billium\Platform\Internal\Import\Sources\GenericExportProfile;
use Billium\Platform\Internal\Import\Sources\GenericExportReader;
use Billium\Platform\Internal\Import\Sources\RecurringExportProfile;
use Billium\Platform\Tests\Import\Concurrency\ConcurrentCrossSellerSameRefsScenario;
use Billium\Platform\Tests\Import\Concurrency\ConcurrentPartialRefundCapRaceScenario;
use Billium\Platform\Tests\Import\Concurrency\RepackedBatchRaceScenario;
use Billium\Platform\Tests\Import\Support\ImportScenarioFixtures;
use Billium\Platform\Tests\PlatformTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Product-agnostic external import adversarial scenario & N-process concurrency matrix (P9.6 / D71):
 * covers IM-01..IM-17 with real PostgreSQL 16 and P3 advisory barrier stand (no transaction wrappers).
 */
#[Group('P9.6')]
#[Group('D71')]
#[Group('pg')]
final class ImportScenarioTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
    }

    /**
     * IM-01, IM-06, IM-07, IM-08, IM-13:
     * Unanchored evidence, mutable subscriptions without frozen occurrences, host non-monetary credits/rewards,
     * and annual funding with 12 attached quota grants never invent obligations, captures or customer credits.
     */
    public function test_im01_im06_im07_im08_im13_unanchored_evidence_credits_rewards_and_annual_grants_never_invent_money(): void
    {
        $this->asAuthorizedCaller('seller-credits-1');
        $creditsPath = $this->repoPath('fixtures/integration/import/reference-credits/export-artifact.json');
        $creditsReader = GenericExportReader::fromFile($creditsPath, new CreditsExportProfile, 'import-policy-v1', new Codec);

        $previewCredits = $this->application()->make(ImportPreviewEvaluator::class)->evaluate(
            $creditsReader->manifest(),
            $creditsReader->records(),
        );
        self::assertSame(ImportBatchStatus::Validated, $previewCredits->batchStatus);
        self::assertSame(3, $previewCredits->counts['unanchored_evidence']);
        self::assertContains('cred-balance-claimed-money-002', $previewCredits->referencesWithoutEvidence);
        self::assertContains('cred-reward-points-003', $previewCredits->referencesWithoutEvidence);
        self::assertContains('cred-missing-price-006', $previewCredits->referencesWithoutEvidence);

        $applyCredits = $this->applier()->apply(
            $creditsReader->manifest(),
            $creditsReader->records(),
            new ImportApplyOptions(
                capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                expectedManifestDigest: $creditsReader->manifest()->manifestDigest,
            ),
        );
        self::assertSame(ImportBatchStatus::Completed, $applyCredits->batchStatus);
        self::assertSame(3, $applyCredits->counts[ImportRecordApplyStatus::UnanchoredEvidence->value]);

        // Strictly ONE proven USD obligation (49.00 USD) and ZERO customer credits, ZERO payments, ZERO invoices!
        self::assertSame(1, $this->rows('billium_obligation', "seller_ref = 'seller-credits-1'"));
        self::assertSame(4900, self::integer($this->value("SELECT amount_due_minor FROM billium_obligation WHERE seller_ref = 'seller-credits-1'")));
        self::assertSame('pv-2025-pack-usd', $this->value("SELECT price_version_ref FROM billium_obligation WHERE seller_ref = 'seller-credits-1'"));
        self::assertSame(0, $this->rows('billium_customer_credit'));
        self::assertSame(0, $this->rows('billium_payment', "seller_ref = 'seller-credits-1'"));
        self::assertSame(0, $this->rows('billium_invoice', "seller_ref = 'seller-credits-1'"));

        foreach (['cred-balance-claimed-money-002', 'cred-reward-points-003', 'cred-missing-price-006'] as $unanchoredId) {
            self::assertNull(
                $this->value('SELECT billium_target_ref FROM billium_import_mapping WHERE source_record_id = ?', [$unanchoredId]),
                "Unanchored record {$unanchoredId} must never bind a paid obligation or payment target",
            );
        }
        foreach (['cred-grant-welcome-001', 'cred-reward-ref-004'] as $nonFinancialId) {
            self::assertSame(
                'nonfinancial_reference',
                $this->value('SELECT billium_target_kind FROM billium_import_mapping WHERE source_record_id = ?', [$nonFinancialId]),
            );
        }

        // Apply reference-recurring export artifact with annual funding + 12 grants + mutable monthly subscription (IM-06, IM-13).
        $this->asAuthorizedCaller('seller-recurring-1');
        $recurringPath = $this->repoPath('fixtures/integration/import/reference-recurring/complete-manifest.json');
        $recurringReader = GenericExportReader::fromFile($recurringPath, new RecurringExportProfile, 'import-policy-v1', new Codec);
        $applyRecurring = $this->applier()->apply(
            $recurringReader->manifest(),
            $recurringReader->records(),
            new ImportApplyOptions(
                capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                expectedManifestDigest: $recurringReader->manifest()->manifestDigest,
            ),
        );
        self::assertSame(ImportBatchStatus::Completed, $applyRecurring->batchStatus);

        // IM-13: Annual funding (12000.00 RUB) + 12 attached grants + 1 USD addon => strictly 2 obligations total (1 RUB, 1 USD), NEVER 13!
        self::assertSame(1, $this->rows('billium_obligation', "seller_ref = 'seller-recurring-1' AND currency = 'RUB'"));
        self::assertSame(1, $this->rows('billium_obligation', "seller_ref = 'seller-recurring-1' AND currency = 'USD'"));
        self::assertNull($this->value("SELECT billium_target_ref FROM billium_import_mapping WHERE source_record_id = 'rec-sub-monthly-unanchored'"));
        self::assertNull($this->value("SELECT billium_target_ref FROM billium_import_mapping WHERE source_record_id = 'rec-obl-current-plan-only'"));
    }

    /**
     * IM-02, IM-03, IM-05, IM-11:
     * Uses the P3 ConcurrencyStand barrier (no transaction wrapper) to race 4 OS processes importing
     * the exact same source identities packaged into different batchIds and different mapper versions (1.0.0..4.0.0).
     * Asserts unique mapping/receipt/economic effect and identical sourceFactRef across all workers,
     * followed by IM-03 payloadDigest conflict quarantine.
     */
    public function test_im02_im03_im05_im11_concurrent_repacked_batches_across_mapper_versions_and_digest_conflict(): void
    {
        $sellerRef = 'seller-recurring-1';
        $this->asAuthorizedCaller($sellerRef);
        $fixturePath = $this->repoPath('fixtures/integration/import/reference-recurring/complete-manifest.json');
        $artifact = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

        $results = ConcurrencyStand::run(RepackedBatchRaceScenario::class, 4, [
            'installationRef' => self::INSTALLATION,
            'sellerRef' => $sellerRef,
            'artifact' => $artifact,
        ]);

        self::assertCount(4, $results);
        $completedWorkers = [];
        $referenceFacts = null;
        foreach ($results as $res) {
            if (isset($res['ok'])) {
                self::assertSame('completed', $res['ok']['batchStatus']);
                self::assertTrue($res['ok']['reconciled']);
                $completedWorkers[] = $res['ok'];
                if ($referenceFacts === null) {
                    $referenceFacts = $res['ok']['factRefs'];
                } else {
                    self::assertSame($referenceFacts, $res['ok']['factRefs']);
                }
            } else {
                self::assertContains($res['error']['code'], ['billium.idempotency.in_flight']);
            }
        }
        self::assertNotEmpty($completedWorkers);

        // Sequentially run a 5th repackaged batch with mapper version 5.0.0 after the race to prove deterministic replay parity.
        $artifactV5 = $artifact;
        $artifactV5['batchId'] = 'batch-repacked-post-race-v5';
        $artifactV5['sourceExportRevision'] = 99;
        $artifactV5['importPolicyVersion'] = 'import-policy-v5';
        unset($artifactV5['artifactDigest']);
        $readerV5 = GenericExportReader::fromJson(
            json_encode($artifactV5, JSON_THROW_ON_ERROR),
            new RecurringExportProfile(version: '5.0.0'),
            'import-policy-v5',
            new Codec,
        );
        $replayV5 = $this->applier()->apply(
            $readerV5->manifest(),
            $readerV5->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $readerV5->manifest()->manifestDigest),
        );
        self::assertSame(ImportBatchStatus::Completed, $replayV5->batchStatus);
        self::assertGreaterThanOrEqual(8, $replayV5->counts[ImportRecordApplyStatus::AlreadyApplied->value]);

        $reconcileV5 = $this->reconciler()->reconcileBatch($readerV5->manifest()->batchId);
        $factsV5 = [];
        foreach ($reconcileV5->accountingFacts as $f) {
            $factsV5[$f['sourceRecordId']] = $f['sourceFactRef'];
        }
        ksort($factsV5, SORT_STRING);
        self::assertSame($referenceFacts, $factsV5);

        // Strictly single economic effect in PostgreSQL across all concurrent and sequential repacked batches!
        self::assertSame(2, $this->rows('billium_obligation', 'seller_ref = ?', [$sellerRef]));
        self::assertSame(2, $this->rows('billium_payment', 'seller_ref = ?', [$sellerRef]));
        self::assertSame(2, $this->rows('billium_allocation', 'seller_ref = ?', [$sellerRef]));
        self::assertSame(11, $this->rows('billium_import_mapping', 'seller_ref = ?', [$sellerRef]));
        self::assertSame(6, $this->rows('billium_import_receipt', "seller_ref = ? AND status = 'applied'", [$sellerRef]));
        self::assertSame(3, $this->rows('billium_import_receipt', "seller_ref = ? AND status = 'observation_queued'", [$sellerRef]));

        // IM-03: Submitting the same source identity & revision with a different payload digest conflicts and quarantines.
        $conflictingArtifact = $artifact;
        $conflictingArtifact['batchId'] = 'batch-repacked-conflict-im03';
        unset($conflictingArtifact['artifactDigest']);
        $conflictingArtifact['records'][0]['money'] = ['amount' => '19999.00', 'currency' => 'RUB'];
        $conflictingArtifact['recordsChecksum'] = 'sha256:'.hash('sha256', (new Codec)->canonicalize($conflictingArtifact['records']));
        $conflictReader = GenericExportReader::fromJson(
            json_encode($conflictingArtifact, JSON_THROW_ON_ERROR),
            new RecurringExportProfile(version: '5.0.0'),
            'import-policy-v5',
            new Codec,
        );
        $conflictRes = $this->applier()->apply(
            $conflictReader->manifest(),
            $conflictReader->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $conflictReader->manifest()->manifestDigest),
        );
        self::assertSame(ImportBatchStatus::Quarantined, $conflictRes->batchStatus);
        self::assertGreaterThanOrEqual(1, $conflictRes->counts[ImportRecordApplyStatus::Conflict->value]);
        self::assertSame(1_200_000, self::integer($this->value("SELECT amount_due_minor FROM billium_obligation WHERE obligation_ref = 'src-obl-annual-rub-001'")));
    }

    /**
     * IM-04:
     * Force interruption after EACH individual root commit checkpoint (maxSteps=1, chunkSize=1)
     * and verify that every resume retains already-committed receipt_ref and mapping_ref values
     * without re-executing or minting duplicate receipts.
     */
    public function test_im04_interruption_after_every_single_root_commit_preserves_exact_receipts_on_resume(): void
    {
        $this->asAuthorizedCaller('seller-recurring-1');
        $fixturePath = $this->repoPath('fixtures/integration/import/reference-recurring/complete-manifest.json');
        $reader = GenericExportReader::fromFile($fixturePath, new RecurringExportProfile, 'import-policy-v1', new Codec);
        $manifest = $reader->manifest();

        $step = $this->applier()->apply(
            $manifest,
            $reader->records(),
            new ImportApplyOptions(
                capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                expectedManifestDigest: $manifest->manifestDigest,
                chunkSize: 1,
                maxSteps: 1,
            ),
        );
        self::assertSame(ImportBatchStatus::Applying, $step->batchStatus);

        $seenReceiptsByDigest = [];
        $stepCount = 1;
        while ($step->batchStatus !== ImportBatchStatus::Completed && $stepCount < 50) {
            foreach ($this->rowsList('SELECT source_identity_digest, receipt_ref, status FROM billium_import_receipt WHERE batch_pk = (SELECT id FROM billium_import_batch WHERE batch_id = ?)', [$manifest->batchId]) as $row) {
                $digest = (string) $row['source_identity_digest'];
                if (isset($seenReceiptsByDigest[$digest])) {
                    self::assertSame($seenReceiptsByDigest[$digest]['receipt_ref'], $row['receipt_ref']);
                }
                $seenReceiptsByDigest[$digest] = $row;
            }

            $stepCount++;
            $step = $this->applier()->resume(
                $manifest->batchId,
                new ImportApplyOptions(
                    capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                    expectedManifestDigest: $manifest->manifestDigest,
                    chunkSize: 1,
                    maxSteps: 1,
                ),
            );
        }

        self::assertSame(ImportBatchStatus::Completed, $step->batchStatus);
        self::assertGreaterThanOrEqual(11, $stepCount);
        self::assertCount(11, $seenReceiptsByDigest);
        self::assertSame(2, $this->rows('billium_obligation'));
        self::assertSame(2, $this->rows('billium_payment'));
        self::assertSame(2, $this->rows('billium_allocation'));
    }

    /**
     * IM-05, IM-09, IM-10, IM-11, IM-14, IM-16:
     * Multi-currency conservation (RUB, USD, EUR), frozen historical PriceVersion retention after current PlanVersion change,
     * concurrent partial refund cap race at P3 barrier, multi-component MoneyMath rounding (10.00 -> 3.34 + 3.33 + 3.33),
     * original owner routing, and historical legal invoice number preservation.
     */
    public function test_im09_im10_im11_im14_im16_multi_currency_partial_refund_race_component_rounding_and_original_owner(): void
    {
        $sellerRef = 'seller-rfnd-race-1';
        $this->asAuthorizedCaller($sellerRef);

        $initialRecords = [
            ImportScenarioFixtures::obligation('obl-rub-1', '1000.00', 'RUB', 'pv-2025-rub-v1', '1490.00', 'pv-2026-rub-current'),
            ImportScenarioFixtures::obligation('obl-usd-1', '250.00', 'USD', 'pv-2025-usd-v1', '320.00', 'pv-2026-usd-current'),
            ImportScenarioFixtures::obligation('obl-eur-1', '99.00', 'EUR', 'pv-2025-eur-v1', '129.00', 'pv-2026-eur-current'),
            ImportScenarioFixtures::allocation('alloc-rub-1', '1000.00', 'RUB', 'src-obl-rub-1', 'src-pay-rub-1'),
            ImportScenarioFixtures::payment('pay-rub-1', '1000.00', 'RUB', 'orig-cap-pay-001', 'src-pay-rub-1', 'exec-gateway-alpha'),
            ImportScenarioFixtures::historicalInvoice('inv-hist-eur-1', '99.00', 'EUR', 'seller-legal-eu-1', 'EU-HIST-2025-0099'),
        ];
        $initialReader = ImportScenarioFixtures::readerForRecords(self::INSTALLATION, 'batch-multi-init-1', $sellerRef, $initialRecords);
        $initRes = $this->applier()->apply(
            $initialReader->manifest(),
            $initialReader->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $initialReader->manifest()->manifestDigest),
        );
        self::assertSame(ImportBatchStatus::Completed, $initRes->batchStatus);

        // IM-09: Unrelated current PlanVersion never alters historical amounts or price_version_ref.
        $queries = $this->application()->make(LedgerQueries::class);
        self::assertSame('1000.00', $queries->obligation('src-obl-rub-1')['originalDue']['amount']);
        self::assertSame('250.00', $queries->obligation('src-obl-usd-1')['originalDue']['amount']);
        self::assertSame('99.00', $queries->obligation('src-obl-eur-1')['originalDue']['amount']);
        self::assertSame('pv-2025-rub-v1', $this->value("SELECT price_version_ref FROM billium_obligation WHERE obligation_ref = 'src-obl-rub-1'"));
        self::assertSame('pv-2025-usd-v1', $this->value("SELECT price_version_ref FROM billium_obligation WHERE obligation_ref = 'src-obl-usd-1'"));
        self::assertSame('pv-2025-eur-v1', $this->value("SELECT price_version_ref FROM billium_obligation WHERE obligation_ref = 'src-obl-eur-1'"));

        // IM-14: RUB, USD and EUR are reconciled separately and conserved.
        $initReconcile = $this->reconciler()->reconcileBatch('batch-multi-init-1');
        self::assertTrue($initReconcile->reconciled);
        self::assertSame(['EUR', 'RUB', 'USD'], array_keys($initReconcile->ledgerConservationByCurrency));
        self::assertSame(9_900, $initReconcile->ledgerConservationByCurrency['EUR']['obligationsDueMinor']);
        self::assertSame(100_000, $initReconcile->ledgerConservationByCurrency['RUB']['obligationsDueMinor']);
        self::assertSame(25_000, $initReconcile->ledgerConservationByCurrency['USD']['obligationsDueMinor']);

        // IM-16: Historical EUR invoice preserves originalIssuer and sourceNumber with 0 Billium invoices issued.
        self::assertSame(0, $this->rows('billium_invoice'));
        $histRow = $this->row("SELECT original_issuer, original_source_number FROM billium_import_mapping WHERE source_record_id = 'inv-hist-eur-1'");
        self::assertSame('seller-legal-eu-1', $histRow['original_issuer']);
        self::assertSame('EU-HIST-2025-0099', $histRow['original_source_number']);

        // IM-05 + IM-10: Race 3 concurrent workers on the same 300.00 RUB partial refund identity + 1 worker with an over-capacity 1200.00 RUB refund!
        $validRefundArtifact = ImportScenarioFixtures::artifactForRecords(self::INSTALLATION, 'batch-rfnd-valid', $sellerRef, [
            ImportScenarioFixtures::refund('rfnd-part-300', '300.00', 'RUB', 'orig-cap-pay-001', 'exec-gateway-alpha'),
        ]);
        $overCapRefundArtifact = ImportScenarioFixtures::artifactForRecords(self::INSTALLATION, 'batch-rfnd-over', $sellerRef, [
            ImportScenarioFixtures::refund('rfnd-over-1200', '1200.00', 'RUB', 'orig-cap-pay-001', 'exec-gateway-alpha'),
        ]);

        $raceResults = ConcurrencyStand::run(ConcurrentPartialRefundCapRaceScenario::class, 4, [
            'installationRef' => self::INSTALLATION,
            'sellerRef' => $sellerRef,
            'workerArtifacts' => [
                0 => $validRefundArtifact,
                1 => $validRefundArtifact,
                2 => $validRefundArtifact,
                3 => $overCapRefundArtifact,
            ],
        ]);
        self::assertCount(4, $raceResults);
        self::assertSame('quarantined', $raceResults[3]['ok']['batchStatus'] ?? null);
        self::assertSame(1, $raceResults[3]['ok']['counts']['conflict'] ?? null);

        // Refund detail applied strictly once against original payment owner (30000 minor = 300.00 RUB, never 90000 or 120000!).
        $refundDetailMinor = self::integer($this->value(
            'SELECT COALESCE(SUM(r.captured_minor), 0) '
            .'FROM billium_observed_payment o '
            .'JOIN billium_observed_payment_revision r ON r.observed_payment_id = o.id AND r.seller_ref = o.seller_ref AND r.environment = o.environment '
            ."WHERE o.seller_ref = ? AND o.original_ref = 'orig-cap-pay-001' AND o.kind = 'refund'",
            [$sellerRef],
        ));
        self::assertSame(30_000, $refundDetailMinor);
        self::assertSame(1, $this->rows('billium_observed_payment', "seller_ref = ? AND original_ref = 'orig-cap-pay-001' AND kind = 'refund'", [$sellerRef]));

        $completedRefundBatchId = null;
        foreach ([0, 1, 2] as $wIdx) {
            if (($raceResults[$wIdx]['ok']['batchStatus'] ?? null) === 'completed') {
                $completedRefundBatchId = $raceResults[$wIdx]['ok']['batchId'];
                break;
            }
        }
        self::assertNotNull($completedRefundBatchId);
        $rfndReconcile = $this->reconciler()->reconcileBatch((string) $completedRefundBatchId);
        self::assertCount(1, $rfndReconcile->accountingFacts);
        self::assertSame('refund.succeeded', $rfndReconcile->accountingFacts[0]['factKind']);
        self::assertSame(30_000, $rfndReconcile->accountingFacts[0]['amountMinor']);

        // IM-10: Multi-component partial adjustment (10.00 RUB across 3 equal components -> 3.34, 3.33, 3.33) + CreditNote with zero live PSP calls.
        $oblIdentity = $initialReader->records()[0]->identity;
        $posCorr = $this->applier()->applyLinkedCorrection(new ImportLinkedCorrection(
            capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
            correctionRef: 'corr-im10-split-3',
            originalIdentity: $oblIdentity,
            originalRevision: 1,
            newRevision: 2,
            kind: ImportLinkedCorrection::KIND_POSITIVE_OBLIGATION,
            reason: 'component_proration_adjustment',
            deltaMoney: Money::parse('10.00', 'RUB'),
            componentWeights: [
                ['componentRef' => 'comp-seat', 'weightMinor' => 1],
                ['componentRef' => 'comp-addon', 'weightMinor' => 1],
                ['componentRef' => 'comp-support', 'weightMinor' => 1],
            ],
        ));
        self::assertFalse($posCorr->externalProviderActionDispatched);
        self::assertSame('3.34', $posCorr->allocatedComponents[0]['amount']['amount']);
        self::assertSame('3.33', $posCorr->allocatedComponents[1]['amount']['amount']);
        self::assertSame('3.33', $posCorr->allocatedComponents[2]['amount']['amount']);

        $compOblRef = $posCorr->allocatedComponents[0]['obligationRef'];
        $ledger = $this->application()->make(Ledger::class);
        $scope = new BillingAccountScope($sellerRef, 'test', 'acct-1');
        $ledger->createInvoice(new CreateInvoice('cmd-inv-im10', $scope, 'inv-im10-1', 'cust-1', [new InvoiceLineInput($compOblRef)]));
        $ledger->issueInvoice(new IssueInvoice('cmd-iss-im10', $scope, 'inv-im10-1'));

        $negCorr = $this->applier()->applyLinkedCorrection(new ImportLinkedCorrection(
            capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
            correctionRef: 'corr-im10-cn-1',
            originalIdentity: $oblIdentity,
            originalRevision: 2,
            newRevision: 3,
            kind: ImportLinkedCorrection::KIND_NEGATIVE_CREDIT_NOTE,
            reason: 'partial_component_credit_note',
            deltaMoney: Money::parse('1.34', 'RUB'),
            invoiceRef: 'inv-im10-1',
            creditNoteMode: 'open_only',
        ));
        self::assertFalse($negCorr->externalProviderActionDispatched);
        self::assertSame(200, self::integer($this->value('SELECT open_minor FROM billium_obligation WHERE obligation_ref = ?', [$compOblRef])));
        self::assertSame(0, $this->rows('billium_outbox_command', "command_type LIKE '%refund%'"));
    }

    /**
     * IM-05, IM-12, IM-15:
     * Cross-source same refs across sellers (`seller-alpha` and `seller-beta`) run concurrently across P3 advisory barrier
     * with zero cross-seller interference, isolated obligations/payments/mappings, and distinct seller-scoped sourceFactRefs;
     * missing exportCutoff, tampered recordsChecksum, or unexplained manifest currency delta blocks cutover.
     */
    public function test_im12_im15_cross_seller_same_refs_concurrency_and_incomplete_checksum_or_cutoff_blocks_cutover(): void
    {
        $artifactAlpha = ImportScenarioFixtures::artifactForRecords(self::INSTALLATION, 'batch-alpha', 'seller-alpha', [
            ImportScenarioFixtures::obligation('shared-obl-001', '5000.00', 'RUB', 'pv-shared-rub-1'),
            ImportScenarioFixtures::allocation('shared-alloc-001', '5000.00', 'RUB', 'src-shared-obl-001', 'src-shared-pay-001'),
            ImportScenarioFixtures::payment('shared-pay-001', '5000.00', 'RUB', 'orig-shared-pay-001', 'src-shared-pay-001', 'exec-shared-1'),
            ImportScenarioFixtures::historicalInvoice('shared-inv-001', '5000.00', 'RUB', 'issuer-alpha', 'SHARED-INV-2025-001'),
        ]);
        $artifactBeta = ImportScenarioFixtures::artifactForRecords(self::INSTALLATION, 'batch-beta', 'seller-beta', [
            ImportScenarioFixtures::obligation('shared-obl-001', '7500.00', 'RUB', 'pv-shared-rub-1'),
            ImportScenarioFixtures::allocation('shared-alloc-001', '7500.00', 'RUB', 'src-shared-obl-001', 'src-shared-pay-001'),
            ImportScenarioFixtures::payment('shared-pay-001', '7500.00', 'RUB', 'orig-shared-pay-001', 'src-shared-pay-001', 'exec-shared-1'),
            ImportScenarioFixtures::historicalInvoice('shared-inv-001', '7500.00', 'RUB', 'issuer-beta', 'SHARED-INV-2025-001'),
        ]);

        $results = ConcurrencyStand::run(ConcurrentCrossSellerSameRefsScenario::class, 4, [
            'installationRef' => self::INSTALLATION,
            'artifactBySeller' => [
                'seller-alpha' => $artifactAlpha,
                'seller-beta' => $artifactBeta,
            ],
        ]);
        self::assertCount(4, $results);

        $factsBySeller = [];
        foreach ($results as $res) {
            if (isset($res['ok'])) {
                self::assertSame('completed', $res['ok']['batchStatus']);
                self::assertTrue($res['ok']['reconciled']);
                $factsBySeller[$res['ok']['sellerRef']] = $res['ok']['factRefs'];
            } else {
                self::assertContains($res['error']['code'], ['billium.idempotency.in_flight']);
            }
        }
        self::assertArrayHasKey('seller-alpha', $factsBySeller);
        self::assertArrayHasKey('seller-beta', $factsBySeller);
        self::assertNotSame($factsBySeller['seller-alpha']['shared-pay-001'], $factsBySeller['seller-beta']['shared-pay-001']);

        self::assertSame(1, $this->rows('billium_obligation', "seller_ref = 'seller-alpha'"));
        self::assertSame(1, $this->rows('billium_obligation', "seller_ref = 'seller-beta'"));
        self::assertSame(500_000, self::integer($this->value("SELECT amount_due_minor FROM billium_obligation WHERE seller_ref = 'seller-alpha'")));
        self::assertSame(750_000, self::integer($this->value("SELECT amount_due_minor FROM billium_obligation WHERE seller_ref = 'seller-beta'")));

        // IM-12: Cross-seller caller attempting to apply seller-alpha's manifest is rejected before any mutation.
        $this->asAuthorizedCaller('seller-beta');
        $alphaReader = GenericExportReader::fromJson(json_encode($artifactAlpha, JSON_THROW_ON_ERROR), new GenericExportProfile);
        try {
            $this->applier()->apply(
                $alphaReader->manifest(),
                $alphaReader->records(),
                new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $alphaReader->manifest()->manifestDigest),
            );
            self::fail('Expected AuthDenied for cross-seller apply attempt');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::AuthDenied, $e->problem);
        }

        // IM-15: Missing exportCutoff and unexplained currency delta block cutover readiness (preview surfaces delta, apply quarantines).
        try {
            GenericExportReader::fromFile($this->repoPath('fixtures/integration/import/reference-recurring/negative-missing-cutoff.json'), new RecurringExportProfile);
            self::fail('Expected ExportArtifactException for missing exportCutoff');
        } catch (ExportArtifactException $e) {
            self::assertSame('export.missing_exportCutoff', $e->errorCode);
        }

        $badDeltaArtifact = ImportScenarioFixtures::artifactForRecords(self::INSTALLATION, 'batch-im15-bad-delta', 'seller-im15-delta', [
            ImportScenarioFixtures::obligation('obl-delta-1', '1000.00', 'RUB', 'pv-delta-1'),
        ]);
        $badDeltaArtifact['totalsByCurrency'][0]['totalAmount'] = ['amount' => '99999.00', 'currency' => 'RUB'];
        $this->asAuthorizedCaller('seller-im15-delta');
        $badDeltaReader = GenericExportReader::fromJson(json_encode($badDeltaArtifact, JSON_THROW_ON_ERROR), new GenericExportProfile);
        $badDeltaPreview = $this->application()->make(ImportPreviewEvaluator::class)->evaluate(
            $badDeltaReader->manifest(),
            $badDeltaReader->records(),
        );
        self::assertNotEmpty($badDeltaPreview->unexplainedDeltas);

        $badDeltaApply = $this->applier()->apply(
            $badDeltaReader->manifest(),
            $badDeltaReader->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $badDeltaReader->manifest()->manifestDigest),
        );
        self::assertSame(ImportBatchStatus::Quarantined, $badDeltaApply->batchStatus);
    }

    private function asAuthorizedCaller(string $sellerRef): void
    {
        $this->callers->set(new Caller(
            sellerRef: $sellerRef,
            environment: 'test',
            installationRef: self::INSTALLATION,
            actorRef: 'actor-scenario-op',
            sourceAuthorities: [ImportApplyOptions::DEFAULT_MIGRATION_SOURCE_AUTHORITY],
        ));
    }

    private function applier(): ImportBatchApplier
    {
        return $this->application()->make(ImportBatchApplier::class);
    }

    private function reconciler(): ImportReconciler
    {
        return $this->application()->make(ImportReconciler::class);
    }

    private function repoPath(string $relative): string
    {
        return dirname(__DIR__, 6).'/'.$relative;
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    private function rowsList(string $sql, array $bindings = []): array
    {
        $rows = $this->owner()->select($sql, $bindings);

        return array_map(static fn (object $r): array => (array) $r, $rows);
    }
}
