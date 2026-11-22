<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Import;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Obligations\AdjustObligation;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Settlements\CloseSettlementIntentNoEffect;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Platform\Contracts\Import\ImportBatchApplier;
use Billium\Platform\Contracts\Import\ImportReconciler;
use Billium\Platform\Data\Import\ImportApplyOptions;
use Billium\Platform\Data\Import\ImportBatchStatus;
use Billium\Platform\Internal\Import\Sources\GenericExportReader;
use Billium\Platform\Internal\Import\Sources\RecurringExportProfile;
use Billium\Platform\Tests\Import\Support\ConcurrentImportScenario;
use Billium\Platform\Tests\PlatformTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('P9.3')]
#[Group('D71')]
#[Group('pg')]
final class ImportResumeTest extends PlatformTestCase
{
    private const string DIGEST_1 = 'sha256:1111111111111111111111111111111111111111111111111111111111111111';

    private const string DIGEST_2 = 'sha256:2222222222222222222222222222222222222222222222222222222222222222';

    private const string DIGEST_3 = 'sha256:3333333333333333333333333333333333333333333333333333333333333333';

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
    }

    /**
     * IM-04: Interrupting import application at EVERY single commit boundary (maxSteps=1 per invocation)
     * and resuming repeatedly produces the exact same obligations, payments, allocations, mappings, receipts
     * and outbox events as an uninterrupted run, with zero duplicates.
     */
    public function test_step_by_step_interruption_at_every_commit_boundary_resumes_to_identical_state_without_duplicates(): void
    {
        $this->asAuthorizedCaller('seller-recurring-1');
        $fixturePath = dirname(__DIR__, 6).'/fixtures/integration/import/reference-recurring/complete-manifest.json';
        $reader = GenericExportReader::fromFile($fixturePath, new RecurringExportProfile, null, new Codec);
        $manifest = $reader->manifest();

        // Step 1: initial apply with maxSteps = 1 stops cleanly in 'applying' after the very first checkpoint.
        $stepResult = $this->applier()->apply(
            $manifest,
            $reader->records(),
            new ImportApplyOptions(
                capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                expectedManifestDigest: $manifest->manifestDigest,
                chunkSize: 2,
                maxSteps: 1,
            ),
        );
        self::assertSame(ImportBatchStatus::Applying, $stepResult->batchStatus);
        self::assertSame(1, $stepResult->processedSteps);
        self::assertGreaterThan(0, $stepResult->remainingSteps);

        // Step 2..N: resume one commit boundary at a time until batch reaches 'completed'.
        $iterations = 0;
        while ($stepResult->batchStatus !== ImportBatchStatus::Completed && $iterations < 40) {
            $iterations++;
            $stepResult = $this->applier()->resume(
                $manifest->batchId,
                new ImportApplyOptions(
                    capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                    expectedManifestDigest: $manifest->manifestDigest,
                    chunkSize: 2,
                    maxSteps: 1,
                ),
            );
        }

        self::assertSame(ImportBatchStatus::Completed, $stepResult->batchStatus);
        self::assertGreaterThanOrEqual(10, $iterations);

        // Verify exact committed row counts: zero duplicate obligations, payments, allocations, mappings or outbox events!
        self::assertSame(2, $this->rows('billium_obligation'));
        self::assertSame(2, $this->rows('billium_payment'));
        self::assertSame(2, $this->rows('billium_allocation'));
        self::assertSame(3, $this->rows('billium_observed_payment'));
        self::assertSame(11, $this->rows('billium_import_mapping'));
        self::assertSame(2, $this->rows('billium_outbox_event', "type = 'obligation.created.v1'"));
        self::assertSame(2, $this->rows('billium_outbox_event', "type = 'payment.outcome.v1'"));
        self::assertSame(2, $this->rows('billium_outbox_event', "type = 'allocation.applied.v1'"));

        $report = $this->application()->make(ImportReconciler::class)->reconcileBatch($manifest->batchId);
        self::assertTrue($report->reconciled);
        self::assertSame([], $report->discrepancies);

        // Calling resume on an already-completed batch is a side-effect-free idempotent no-op.
        $noopResume = $this->applier()->resume(
            $manifest->batchId,
            new ImportApplyOptions(
                capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                expectedManifestDigest: $manifest->manifestDigest,
            ),
        );
        self::assertSame(ImportBatchStatus::Completed, $noopResume->batchStatus);
        self::assertSame(0, $noopResume->processedSteps);
        self::assertSame(2, $this->rows('billium_obligation'));
        self::assertSame(2, $this->rows('billium_payment'));
    }

    /**
     * IM-04: Mid-batch crash after first obligation commits leaves first obligation intact, rolls back second obligation,
     * and subsequent resume completes all remaining stages cleanly without duplicating the first obligation.
     */
    public function test_mid_batch_exception_crash_between_obligations_resumes_cleanly_from_last_committed_checkpoint(): void
    {
        $this->asAuthorizedCaller('seller-recurring-1');
        $fixturePath = dirname(__DIR__, 6).'/fixtures/integration/import/reference-recurring/complete-manifest.json';
        $reader = GenericExportReader::fromFile($fixturePath, new RecurringExportProfile, null, new Codec);
        $manifest = $reader->manifest();

        $realParticipants = $this->application()->make(LedgerParticipants::class);
        $crashOnSecondObligation = new class($realParticipants) implements LedgerParticipants
        {
            public int $obligationCalls = 0;

            public bool $crashEnabled = true;

            public function __construct(private readonly LedgerParticipants $inner) {}

            public function recordObligation(TransactionContext $context, RecordObligation $command): Receipt
            {
                $this->obligationCalls++;
                $receipt = $this->inner->recordObligation($context, $command);
                if ($this->crashEnabled && $this->obligationCalls === 2) {
                    throw new RuntimeException('simulated worker crash on second obligation');
                }

                return $receipt;
            }

            public function declareSettlementIntent(TransactionContext $context, DeclareSettlementIntent $command): Receipt
            {
                return $this->inner->declareSettlementIntent($context, $command);
            }

            public function issueInvoice(TransactionContext $context, IssueInvoice $command, string $legalDocumentRef): Receipt
            {
                return $this->inner->issueInvoice($context, $command, $legalDocumentRef);
            }

            public function issueCreditNote(TransactionContext $context, IssueCreditNote $command): Receipt
            {
                return $this->inner->issueCreditNote($context, $command);
            }

            public function adjustObligation(TransactionContext $context, AdjustObligation $command): Receipt
            {
                return $this->inner->adjustObligation($context, $command);
            }

            public function closeSettlementIntentNoEffect(TransactionContext $context, CloseSettlementIntentNoEffect $command): Receipt
            {
                return $this->inner->closeSettlementIntentNoEffect($context, $command);
            }
        };
        $this->application()->instance(LedgerParticipants::class, $crashOnSecondObligation);

        try {
            $this->applier()->apply(
                $manifest,
                $reader->records(),
                new ImportApplyOptions(
                    capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                    expectedManifestDigest: $manifest->manifestDigest,
                ),
            );
            self::fail('Expected RuntimeException on second obligation');
        } catch (RuntimeException $e) {
            self::assertSame('simulated worker crash on second obligation', $e->getMessage());
        }

        // First obligation committed durably; second obligation rolled back completely; batch remains 'applying'.
        self::assertSame('applying', $this->value('SELECT status FROM billium_import_batch WHERE batch_id = ?', [$manifest->batchId]));
        self::assertSame(1, $this->rows('billium_obligation'));
        self::assertSame(0, $this->rows('billium_payment'));

        // Clear crash and resume -> finishes obligation #2, historical invoice, allocations, observations, adoption & reconcile!
        $crashOnSecondObligation->crashEnabled = false;
        $resumed = $this->applier()->resume(
            $manifest->batchId,
            new ImportApplyOptions(
                capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                expectedManifestDigest: $manifest->manifestDigest,
            ),
        );
        self::assertSame(ImportBatchStatus::Completed, $resumed->batchStatus);
        self::assertSame(2, $this->rows('billium_obligation'));
        self::assertSame(2, $this->rows('billium_payment'));
        self::assertSame(2, $this->rows('billium_allocation'));
    }

    /**
     * IM-05: Concurrent OS processes crossing a PostgreSQL advisory barrier on the same source identities
     * serialize cleanly without creating duplicate obligations, payments, allocations or mappings.
     */
    public function test_concurrent_processes_importing_same_source_identity_never_create_duplicate_money(): void
    {
        $sellerRef = 'seller-conc-1';
        $this->asAuthorizedCaller($sellerRef);

        $rawRecords = [
            [
                'sourceRecordId' => 'conc-obl-1',
                'sourceRecordRevision' => 1,
                'recordType' => 'obligation',
                'eventBusinessDate' => '2025-11-01T09:00:00Z',
                'originalBusinessRefs' => [
                    'customerRef' => 'cust-conc-1',
                    'billingAccountRef' => 'acct-conc-1',
                    'obligationRef' => 'src-conc-obl-1',
                ],
                'partyRoles' => ['subjectRef' => 'subj-conc-1', 'customerRef' => 'cust-conc-1', 'billingAccountRef' => 'acct-conc-1'],
                'evidenceRefs' => [[
                    'evidenceRef' => 'ev-conc-obl-1',
                    'kind' => 'price_snapshot',
                    'digest' => self::DIGEST_1,
                    'sanitized' => true,
                ]],
                'money' => ['amount' => '4500.00', 'currency' => 'RUB'],
                'frozenPriceSnapshot' => [
                    'basis' => 'frozen_historical',
                    'priceVersionRef' => 'pv-conc-rub-1',
                    'priceDigest' => self::DIGEST_1,
                    'currency' => 'RUB',
                ],
            ],
            [
                'sourceRecordId' => 'conc-alloc-1',
                'sourceRecordRevision' => 1,
                'recordType' => 'allocation',
                'eventBusinessDate' => '2025-11-01T09:01:00Z',
                'originalBusinessRefs' => [
                    'customerRef' => 'cust-conc-1',
                    'billingAccountRef' => 'acct-conc-1',
                    'obligationRef' => 'src-conc-obl-1',
                    'paymentRef' => 'src-conc-pay-1',
                ],
                'partyRoles' => ['subjectRef' => 'subj-conc-1', 'customerRef' => 'cust-conc-1', 'billingAccountRef' => 'acct-conc-1'],
                'evidenceRefs' => [[
                    'evidenceRef' => 'ev-conc-alloc-1',
                    'kind' => 'allocation_lineage',
                    'digest' => self::DIGEST_2,
                    'sanitized' => true,
                ]],
                'money' => ['amount' => '4500.00', 'currency' => 'RUB'],
            ],
            [
                'sourceRecordId' => 'conc-pay-1',
                'sourceRecordRevision' => 1,
                'recordType' => 'payment_evidence',
                'eventBusinessDate' => '2025-11-01T09:02:00Z',
                'originalBusinessRefs' => [
                    'customerRef' => 'cust-conc-1',
                    'billingAccountRef' => 'acct-conc-1',
                    'paymentRef' => 'src-conc-pay-1',
                ],
                'partyRoles' => ['subjectRef' => 'subj-conc-1', 'customerRef' => 'cust-conc-1', 'billingAccountRef' => 'acct-conc-1'],
                'evidenceRefs' => [[
                    'evidenceRef' => 'ev-conc-pay-1',
                    'kind' => 'payment_provider_fact',
                    'digest' => self::DIGEST_3,
                    'sanitized' => true,
                ]],
                'money' => ['amount' => '4500.00', 'currency' => 'RUB'],
                'fundingRefs' => [
                    'sourceAuthority' => 'source-gateway',
                    'fundingStatus' => 'captured',
                    'providerAccountRef' => 'pa-conc-1',
                    'providerOperationRef' => 'psp-conc-1',
                    'originalOperationRef' => 'orig-conc-pay-1',
                    'observedAt' => '2025-11-01T09:02:00Z',
                ],
            ],
        ];

        $codec = new Codec;
        $moneyWire = (new Money(450_000, Currency::of('RUB')))->toWire();
        $artifact = [
            'exportSchema' => 'source.export.v1',
            'profile' => 'generic',
            'exportVersion' => '1.0.0',
            'batchId' => 'batch-conc-template',
            'sourceSystem' => 'host-billing/export',
            'sourceInstallation' => 'src-inst-conc',
            'sourceBook' => 'book-conc',
            'sourceExportRevision' => 1,
            'exportCutoff' => '2026-10-20T00:00:00Z',
            'installationRef' => self::INSTALLATION,
            'environment' => 'test',
            'sellerRef' => $sellerRef,
            'sanitizationPolicy' => ['piiRedacted' => true, 'secretsStripped' => true, 'policyVersion' => 'v1'],
            'totalsByCurrency' => [
                ['sellerRef' => $sellerRef, 'currency' => 'RUB', 'recordKind' => 'proven_allocation', 'count' => 1, 'totalAmount' => $moneyWire, 'authoritativeCurrentBalance' => false],
                ['sellerRef' => $sellerRef, 'currency' => 'RUB', 'recordKind' => 'proven_obligation', 'count' => 1, 'totalAmount' => $moneyWire, 'authoritativeCurrentBalance' => false],
                ['sellerRef' => $sellerRef, 'currency' => 'RUB', 'recordKind' => 'proven_payment_evidence', 'count' => 1, 'totalAmount' => $moneyWire, 'authoritativeCurrentBalance' => false],
            ],
            'records' => $rawRecords,
            'recordCount' => count($rawRecords),
            'recordsChecksum' => 'sha256:'.hash('sha256', $codec->canonicalize($rawRecords)),
        ];

        $results = ConcurrencyStand::run(ConcurrentImportScenario::class, 4, [
            'installationRef' => self::INSTALLATION,
            'sellerRef' => $sellerRef,
            'artifact' => $artifact,
        ]);

        self::assertCount(4, $results);
        $completedCount = 0;
        foreach ($results as $res) {
            if (isset($res['ok'])) {
                self::assertSame('completed', $res['ok']['batchStatus']);
                $completedCount++;
            } else {
                self::assertContains($res['error']['code'], ['billium.idempotency.in_flight']);
            }
        }
        self::assertGreaterThanOrEqual(1, $completedCount);

        // Strictly ONE obligation, ONE payment, ONE allocation, and THREE active source mappings regardless of 4 concurrent workers!
        self::assertSame(1, $this->rows('billium_obligation', 'seller_ref = ?', [$sellerRef]));
        self::assertSame(1, $this->rows('billium_payment', 'seller_ref = ?', [$sellerRef]));
        self::assertSame(1, $this->rows('billium_allocation', 'seller_ref = ?', [$sellerRef]));
        self::assertSame(3, $this->rows('billium_import_mapping', 'seller_ref = ?', [$sellerRef]));
        self::assertSame(1, $this->rows('billium_outbox_event', "seller_ref = ? AND type = 'obligation.created.v1'", [$sellerRef]));
    }

    private function asAuthorizedCaller(string $sellerRef): void
    {
        $this->callers->set(new Caller(
            sellerRef: $sellerRef,
            environment: 'test',
            installationRef: self::INSTALLATION,
            actorRef: 'actor-resume-op',
            sourceAuthorities: [ImportApplyOptions::DEFAULT_MIGRATION_SOURCE_AUTHORITY],
        ));
    }

    private function applier(): ImportBatchApplier
    {
        return $this->application()->make(ImportBatchApplier::class);
    }
}
