<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Import;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Core\Contracts\Runner\OperationRunner;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runner\Receipt;
use Billium\Core\Data\Runner\StoredOutcome;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Exceptions\Runner\LockPlanChanged;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Obligations\AdjustObligation;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Settlements\CloseSettlementIntentNoEffect;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Platform\Contracts\Import\ImportBatchApplier;
use Billium\Platform\Contracts\Import\ImportIdentityStore;
use Billium\Platform\Contracts\Import\ImportPreviewEvaluator;
use Billium\Platform\Data\Import\ImportApplyOptions;
use Billium\Platform\Data\Import\ImportBatchStatus;
use Billium\Platform\Data\Import\ImportCurrencyTotal;
use Billium\Platform\Data\Import\ImportEvidenceRef;
use Billium\Platform\Data\Import\ImportManifest;
use Billium\Platform\Data\Import\ImportRecordApplyStatus;
use Billium\Platform\Data\Import\ImportRecordInput;
use Billium\Platform\Data\Import\ImportRecordKind;
use Billium\Platform\Data\Import\ImportSourceIdentity;
use Billium\Platform\Data\Import\ImportValidationStatus;
use Billium\Platform\Tests\PlatformTestCase;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('P9.3')]
#[Group('D60')]
#[Group('D71')]
#[Group('pg')]
final class ImportParticipantAtomicityTest extends PlatformTestCase
{
    private const string DIGEST_PRICE = 'sha256:1111111111111111111111111111111111111111111111111111111111111111';

    private const string DIGEST_PAY = 'sha256:2222222222222222222222222222222222222222222222222222222222222222';

    private const string DIGEST_ALLOC = 'sha256:3333333333333333333333333333333333333333333333333333333333333333';

    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
        $this->callers->set(new Caller(
            sellerRef: 'seller-a',
            environment: 'test',
            installationRef: self::INSTALLATION,
            actorRef: 'actor-import-1',
            sourceAuthorities: [ImportApplyOptions::DEFAULT_MIGRATION_SOURCE_AUTHORITY],
        ));
    }

    public function test_obligation_participant_mapping_receipt_outbox_and_commit_log_share_one_root_tx_without_public_ledger(): void
    {
        // Guard public root-only Ledger interface to prove import never calls nested public Ledger commands.
        $this->application()->bind(Ledger::class, static function (): Ledger {
            throw new LogicException('Import apply must use typed LedgerParticipants, never nested root-only Ledger');
        });

        [$manifest, $records] = $this->singleObligationBatch('batch-atom-tx-1', 'obl-atom-100', 5000);
        $tables = [
            'billium_import_mapping',
            'billium_import_receipt',
            'billium_obligation',
            'billium_outbox_event',
            'billium_idempotency_receipt',
            'billium_money_commit_log',
        ];
        $this->owner()->unprepared(<<<'SQL'
            CREATE FUNCTION public.billium_test_import_tx() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                INSERT INTO public.billium_test_effect(seller_ref, environment, ref, note)
                VALUES ('seller-a', 'test', TG_TABLE_NAME, txid_current()::text);
                RETURN NEW;
            END $$;
            SQL);
        foreach ($tables as $table) {
            $this->owner()->unprepared("CREATE TRIGGER billium_test_import_tx AFTER INSERT ON {$table} FOR EACH ROW EXECUTE FUNCTION public.billium_test_import_tx()");
        }

        try {
            $result = $this->applier()->apply(
                $manifest,
                $records,
                new ImportApplyOptions(
                    capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                    expectedManifestDigest: $manifest->manifestDigest,
                ),
            );
            self::assertSame(ImportBatchStatus::Completed, $result->batchStatus);
            self::assertSame(1, $this->value('SELECT count(DISTINCT note) FROM billium_test_effect'));
            foreach ($tables as $table) {
                self::assertGreaterThan(0, $this->rows('billium_test_effect', 'ref = ?', [$table]), "Missing write in {$table}");
            }
            self::assertSame(1, $this->rows('billium_money_commit_log', "kind = 'platform.import.obligation'"));
        } finally {
            foreach ($tables as $table) {
                $this->owner()->unprepared("DROP TRIGGER IF EXISTS billium_test_import_tx ON {$table}");
            }
            $this->owner()->unprepared('DROP FUNCTION IF EXISTS public.billium_test_import_tx()');
        }
    }

    public function test_fault_after_obligation_participant_or_in_mapping_receipt_outbox_rolls_back_all_rows_atomically(): void
    {
        [$manifest, $records] = $this->singleObligationBatch('batch-atom-fault-1', 'obl-atom-200', 7500);
        $realParticipants = $this->application()->make(LedgerParticipants::class);
        $fault = new class($realParticipants) implements LedgerParticipants
        {
            public int $calls = 0;

            public bool $shouldFail = true;

            public function __construct(private readonly LedgerParticipants $inner) {}

            public function recordObligation(TransactionContext $context, RecordObligation $command): Receipt
            {
                $this->calls++;
                $receipt = $this->inner->recordObligation($context, $command);
                if ($this->shouldFail) {
                    throw new RuntimeException('simulated crash after LedgerParticipants::recordObligation');
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
        $this->application()->instance(LedgerParticipants::class, $fault);

        try {
            $this->applier()->apply(
                $manifest,
                $records,
                new ImportApplyOptions(
                    capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                    expectedManifestDigest: $manifest->manifestDigest,
                ),
            );
            self::fail('Expected RuntimeException from participant fault');
        } catch (RuntimeException $error) {
            self::assertSame('simulated crash after LedgerParticipants::recordObligation', $error->getMessage());
        }

        self::assertSame(1, $fault->calls);
        self::assertSame(0, $this->rows('billium_obligation'));
        self::assertSame(0, $this->rows('billium_import_mapping'));
        self::assertSame(0, $this->rows('billium_import_receipt'));
        self::assertSame(0, $this->rows('billium_outbox_event'));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind = 'platform.import.obligation.v1'"));
        self::assertSame(0, $this->rows('billium_money_commit_log'));

        // Also test trigger faults on billium_import_receipt and billium_outbox_event after participant completes.
        $fault->shouldFail = false;
        foreach (['billium_import_receipt', 'billium_outbox_event'] as $faultTable) {
            $this->owner()->unprepared("CREATE FUNCTION public.billium_test_import_fault() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'simulated {$faultTable} fault'; END $$");
            $this->owner()->unprepared("CREATE TRIGGER billium_test_import_fault AFTER INSERT ON {$faultTable} FOR EACH ROW EXECUTE FUNCTION public.billium_test_import_fault()");
            try {
                $this->applier()->resume(
                    $manifest->batchId,
                    new ImportApplyOptions(
                        capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                        expectedManifestDigest: $manifest->manifestDigest,
                    ),
                );
                self::fail("Expected trigger fault on {$faultTable}");
            } catch (QueryException $qe) {
                self::assertStringContainsString("simulated {$faultTable} fault", $qe->getMessage());
            } finally {
                $this->owner()->unprepared("DROP TRIGGER IF EXISTS billium_test_import_fault ON {$faultTable}; DROP FUNCTION IF EXISTS public.billium_test_import_fault()");
            }
            self::assertSame(0, $this->rows('billium_obligation'));
            self::assertSame(0, $this->rows('billium_import_mapping'));
            self::assertSame(0, $this->rows('billium_import_receipt'));
            self::assertSame(0, $this->rows('billium_outbox_event'));
        }

        // Resume after fault clears: succeeds cleanly with exactly 1 obligation, 1 mapping and 1 applied receipt.
        $resumed = $this->applier()->resume(
            $manifest->batchId,
            new ImportApplyOptions(
                capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                expectedManifestDigest: $manifest->manifestDigest,
            ),
        );
        self::assertSame(ImportBatchStatus::Completed, $resumed->batchStatus);
        self::assertSame(1, $this->rows('billium_obligation'));
        self::assertSame(1, $this->rows('billium_import_mapping'));
        self::assertSame(1, $this->rows('billium_import_receipt', "status = 'applied'"));
    }

    public function test_forged_stale_and_foreign_contexts_and_lock_retry_preserve_atomicity(): void
    {
        [$manifest, $records] = $this->singleObligationBatch('batch-atom-ctx-1', 'obl-atom-300', 4200);
        $realRunner = $this->runner();
        $spy = new class($realRunner) implements OperationRunner
        {
            public int $roots = 0;

            public ?TransactionContext $replacement = null;

            public ?OperationRunner $assertionAuthority = null;

            /** @var list<TransactionContext> */
            public array $contexts = [];

            /** @var list<string> */
            public array $assertions = [];

            public function __construct(private readonly OperationRunner $inner) {}

            public function run(OperationSpec $spec, Closure $body): StoredOutcome
            {
                $this->roots++;
                if ($this->replacement !== null) {
                    return StoredOutcome::accepted($body($this->replacement));
                }

                return $this->inner->run($spec, function (TransactionContext $ctx) use ($body): Receipt {
                    $this->contexts[] = $ctx;

                    return $body($ctx);
                });
            }

            public function assertCurrent(TransactionContext $context, ?string $moduleId = null): void
            {
                $this->assertions[] = $moduleId ?? '';
                ($this->assertionAuthority ?? $this->inner)->assertCurrent($context, $moduleId);
            }
        };
        $this->application()->instance(OperationRunner::class, $spy);

        // Forged context rejected before any write.
        $spy->replacement = $this->createStub(TransactionContext::class);
        try {
            $this->applier()->apply(
                $manifest,
                $records,
                new ImportApplyOptions(
                    capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                    expectedManifestDigest: $manifest->manifestDigest,
                ),
            );
            self::fail('Forged context was accepted');
        } catch (LogicException) {
            self::assertSame(['platform'], $spy->assertions);
        }
        self::assertSame(0, $this->rows('billium_import_mapping'));
        self::assertSame(0, $this->rows('billium_obligation'));

        // Foreign runner rejected before any write.
        $spy->replacement = null;
        $spy->assertions = [];
        $spy->assertionAuthority = clone $realRunner;
        try {
            $this->applier()->resume(
                $manifest->batchId,
                new ImportApplyOptions(
                    capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                    expectedManifestDigest: $manifest->manifestDigest,
                ),
            );
            self::fail('Foreign runner context was accepted');
        } catch (LogicException) {
            self::assertContains('platform', $spy->assertions);
        }
        self::assertSame(0, $this->rows('billium_import_mapping'));
        self::assertSame(0, $this->rows('billium_obligation'));

        // Real execution with one LockPlanChanged retry uses fresh context on attempt 2 and commits once.
        $spy->assertionAuthority = null;
        $realParticipants = $this->application()->make(LedgerParticipants::class);
        $retryOnce = new class($realParticipants) implements LedgerParticipants
        {
            public int $calls = 0;

            /** @var list<int> */
            public array $attempts = [];

            public function __construct(private readonly LedgerParticipants $inner) {}

            public function recordObligation(TransactionContext $context, RecordObligation $command): Receipt
            {
                $this->calls++;
                $this->attempts[] = $context->attempt();
                $receipt = $this->inner->recordObligation($context, $command);
                if ($this->calls === 1) {
                    throw new LockPlanChanged('simulated lock plan change');
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
        $this->application()->instance(LedgerParticipants::class, $retryOnce);

        $completed = $this->applier()->resume(
            $manifest->batchId,
            new ImportApplyOptions(
                capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                expectedManifestDigest: $manifest->manifestDigest,
            ),
        );
        self::assertSame(ImportBatchStatus::Completed, $completed->batchStatus);
        self::assertSame([1, 2], $retryOnce->attempts);
        self::assertSame(1, $this->rows('billium_obligation'));

        // Stale closed context from completed run is also rejected before any write on a new batch.
        $staleContext = $spy->contexts[array_key_last($spy->contexts)];
        [$manifest2, $records2] = $this->singleObligationBatch('batch-atom-ctx-2', 'obl-atom-301', 3100);
        $spy->replacement = $staleContext;
        $spy->assertions = [];
        try {
            $this->applier()->apply(
                $manifest2,
                $records2,
                new ImportApplyOptions(
                    capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                    expectedManifestDigest: $manifest2->manifestDigest,
                ),
            );
            self::fail('Stale context was accepted');
        } catch (LogicException) {
            self::assertSame(['platform'], $spy->assertions);
        }
        self::assertSame(1, $this->rows('billium_obligation'));
    }

    public function test_queued_observation_never_claims_applied_and_lost_adoption_recovers_original_allocation_receipt_by_query(): void
    {
        [$manifest, $records] = $this->obligationAllocationAndPaymentBatch('batch-obs-two-root-1', 'obl-400', 'pay-400', 9500);

        // Phase 1: Stop immediately after import custody queueing (reduceObservations=false, adoptReceipts=false).
        $queuedResult = $this->applier()->apply(
            $manifest,
            $records,
            new ImportApplyOptions(
                capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                expectedManifestDigest: $manifest->manifestDigest,
                reduceObservations: false,
                adoptReceipts: false,
            ),
        );

        // Batch MUST remain 'applying' — queued observation NEVER claims 'applied', 'already_applied', 'completed' or 'paid'.
        self::assertSame(ImportBatchStatus::Applying, $queuedResult->batchStatus);
        self::assertSame(1, $queuedResult->counts[ImportRecordApplyStatus::ObservationQueued->value]);
        self::assertSame(0, $this->rows('billium_payment'));
        self::assertSame(0, $this->rows('billium_allocation'));
        self::assertSame(1, $this->rows('billium_inbox_message', "channel = 'payment.observation' AND status = 'ready'"));
        self::assertSame(1, $this->rows('billium_outbox_command', "command_type = 'import.observation.drain'"));
        self::assertSame(1, $this->rows('billium_import_receipt', "status = 'observation_queued'"));
        self::assertSame('ready', $this->value("SELECT validation_status FROM billium_import_record WHERE source_record_id = 'pay-400'"));

        $payIdentity = $records[2]->identity;
        $mappingWhileQueued = $this->store()->findMapping($payIdentity, 1);
        self::assertNotNull($mappingWhileQueued);
        self::assertNull($mappingWhileQueued->billiumTargetRef);

        // Preview while queued MUST still report Ready (pending adoption), NEVER AlreadyApplied!
        $previewWhileQueued = $this->application()->make(ImportPreviewEvaluator::class)->evaluate($manifest, $records);
        $payPreview = array_values(array_filter(
            $previewWhileQueued->records,
            static fn ($r): bool => $r->identity->sourceRecordId === 'pay-400',
        ))[0];
        self::assertSame(ImportValidationStatus::Ready, $payPreview->status);
        self::assertContains('import.observation_queued_pending_adoption', $payPreview->reasonCodes);

        // Phase 2: Observation owner reduces in its own independent root Tx, then crash BEFORE import adopts the receipt!
        $reducedNoAdopt = $this->applier()->resume(
            $manifest->batchId,
            new ImportApplyOptions(
                capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                expectedManifestDigest: $manifest->manifestDigest,
                reduceObservations: true,
                adoptReceipts: false,
            ),
        );
        self::assertSame(ImportBatchStatus::Applying, $reducedNoAdopt->batchStatus);
        self::assertSame(1, $this->rows('billium_payment'));
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertNull($this->store()->findMapping($payIdentity, 1)?->billiumTargetRef);

        $originalReceiptRef = self::text($this->value("SELECT receipt_ref FROM billium_allocation_receipt WHERE kind = 'payment_observation'"));
        $originalPaymentId = self::integer($this->value('SELECT id FROM billium_payment'));
        $inboxCountAfterReduce = $this->rows('billium_inbox_message');
        $commitLogBeforeAdopt = $this->rows('billium_money_commit_log');

        // Phase 3: Recover lost adoption (reduceObservations=false, adoptReceipts=true) -> recovers original AllocationReceipt by query alone!
        $recovered = $this->applier()->resume(
            $manifest->batchId,
            new ImportApplyOptions(
                capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
                expectedManifestDigest: $manifest->manifestDigest,
                reduceObservations: false,
                adoptReceipts: true,
            ),
        );
        self::assertSame(ImportBatchStatus::Completed, $recovered->batchStatus);
        self::assertSame(1, $this->rows('billium_payment'));
        self::assertSame($originalPaymentId, self::integer($this->value('SELECT id FROM billium_payment')));
        self::assertSame($inboxCountAfterReduce, $this->rows('billium_inbox_message'));
        self::assertSame($commitLogBeforeAdopt, $this->rows('billium_money_commit_log'));

        $adoptedMapping = $this->store()->findMapping($payIdentity, 1);
        self::assertNotNull($adoptedMapping);
        self::assertSame($originalReceiptRef, $adoptedMapping->billiumTargetRef);
        self::assertSame('payment_observation', $adoptedMapping->billiumTargetKind);

        $queriedReceipt = $this->application()->make(LedgerQueries::class)->allocationReceipt($originalReceiptRef);
        self::assertNotNull($queriedReceipt);
        self::assertSame($originalReceiptRef, $queriedReceipt->receiptRef);
    }

    private function applier(): ImportBatchApplier
    {
        return $this->application()->make(ImportBatchApplier::class);
    }

    private function store(): ImportIdentityStore
    {
        return $this->application()->make(ImportIdentityStore::class);
    }

    /**
     * @return array{0: ImportManifest, 1: list<ImportRecordInput>}
     */
    private function singleObligationBatch(string $batchId, string $sourceRecordId, int $amountMinor): array
    {
        $codec = new Codec;
        $money = new Money($amountMinor, Currency::of('RUB'));
        $cutoff = new DateTimeImmutable('2026-10-20T00:00:00Z');
        $totals = [
            new ImportCurrencyTotal(
                sellerRef: 'seller-a',
                currency: 'RUB',
                recordKind: ImportRecordKind::ProvenObligation,
                count: 1,
                totalAmount: $money,
                authoritativeCurrentBalance: false,
            ),
        ];

        $manifestBody = [
            'schema' => 'external.import.manifest.v1',
            'batchId' => $batchId,
            'sourceSystem' => 'host-billing/export',
            'sourceInstallation' => 'src-inst-1',
            'sourceBook' => 'book-main',
            'sourceExportRevision' => 1,
            'exportCutoff' => '2026-10-20T00:00:00Z',
            'installationRef' => self::INSTALLATION,
            'environment' => 'test',
            'sellerRef' => 'seller-a',
            'recordCount' => 1,
            'recordsChecksum' => self::DIGEST_PRICE,
            'totalsByCurrency' => [$totals[0]->toWire()],
            'sanitizationPolicy' => ['piiRedacted' => true, 'secretsStripped' => true, 'policyVersion' => 'v1'],
        ];
        $manifestDigest = $codec->digest($manifestBody);

        $manifest = new ImportManifest(
            batchId: $batchId,
            installationRef: self::INSTALLATION,
            environment: 'test',
            sellerRef: 'seller-a',
            sourceSystem: 'host-billing/export',
            sourceInstallation: 'src-inst-1',
            sourceBook: 'book-main',
            sourceExportRevision: 1,
            exportCutoff: $cutoff,
            recordCount: 1,
            recordsChecksum: self::DIGEST_PRICE,
            manifestDigest: $manifestDigest,
            sanitizationPolicy: ['piiRedacted' => true, 'secretsStripped' => true, 'policyVersion' => 'v1'],
            totalsByCurrency: $totals,
        );

        $identity = new ImportSourceIdentity(
            installationRef: self::INSTALLATION,
            environment: 'test',
            sellerRef: 'seller-a',
            sourceSystem: 'host-billing/export',
            sourceInstallation: 'src-inst-1',
            sourceBook: 'book-main',
            sourceRecordId: $sourceRecordId,
            recordKind: ImportRecordKind::ProvenObligation,
        );
        $frozen = [
            'priceVersionRef' => 'pv-hist-rub-1',
            'priceDigest' => self::DIGEST_PRICE,
            'currency' => 'RUB',
            'termsRef' => 'terms-v1',
            'taxPolicyVersion' => 'tax-v1',
        ];
        $origRefs = [
            'customerRef' => 'cust-1',
            'billingAccountRef' => 'acct-1',
            'obligationRef' => 'src-'.$sourceRecordId,
        ];
        $payload = [
            'originalBusinessRefs' => $origRefs,
            'frozenPriceSnapshot' => $frozen,
            'evidenceRefs' => [[
                'evidenceRef' => 'ev-1',
                'kind' => 'price_snapshot',
                'digest' => self::DIGEST_PRICE,
                'sanitized' => true,
            ]],
        ];

        $record = new ImportRecordInput(
            identity: $identity,
            batchId: $batchId,
            sourceExportRevision: 1,
            exportCutoff: $cutoff,
            manifestDigest: $manifestDigest,
            sourceRecordRevision: 1,
            eventBusinessDate: new DateTimeImmutable('2025-11-01T00:00:00Z'),
            payloadDigest: $codec->digest($payload),
            originalBusinessRefs: $origRefs,
            evidenceRefs: [new ImportEvidenceRef('ev-1', 'price_snapshot', self::DIGEST_PRICE, true)],
            money: $money,
            frozenPriceSnapshot: $frozen,
            sourceFactRef: $identity->stableSourceFactRef(1, $codec),
            payload: $payload,
        );

        return [$manifest, [$record]];
    }

    /**
     * @return array{0: ImportManifest, 1: list<ImportRecordInput>}
     */
    private function obligationAllocationAndPaymentBatch(
        string $batchId,
        string $oblRecordId,
        string $payRecordId,
        int $amountMinor,
    ): array {
        $codec = new Codec;
        $money = new Money($amountMinor, Currency::of('RUB'));
        $cutoff = new DateTimeImmutable('2026-10-20T00:00:00Z');
        $totals = [
            new ImportCurrencyTotal('seller-a', 'RUB', ImportRecordKind::ProvenAllocation, 1, $money, false),
            new ImportCurrencyTotal('seller-a', 'RUB', ImportRecordKind::ProvenObligation, 1, $money, false),
            new ImportCurrencyTotal('seller-a', 'RUB', ImportRecordKind::ProvenPaymentEvidence, 1, $money, false),
        ];

        $manifestBody = [
            'schema' => 'external.import.manifest.v1',
            'batchId' => $batchId,
            'sourceSystem' => 'host-billing/export',
            'sourceInstallation' => 'src-inst-1',
            'sourceBook' => 'book-main',
            'sourceExportRevision' => 1,
            'exportCutoff' => '2026-10-20T00:00:00Z',
            'installationRef' => self::INSTALLATION,
            'environment' => 'test',
            'sellerRef' => 'seller-a',
            'recordCount' => 3,
            'recordsChecksum' => self::DIGEST_PAY,
            'totalsByCurrency' => array_map(static fn (ImportCurrencyTotal $t): array => $t->toWire(), $totals),
            'sanitizationPolicy' => ['piiRedacted' => true, 'secretsStripped' => true, 'policyVersion' => 'v1'],
        ];
        $manifestDigest = $codec->digest($manifestBody);

        $manifest = new ImportManifest(
            batchId: $batchId,
            installationRef: self::INSTALLATION,
            environment: 'test',
            sellerRef: 'seller-a',
            sourceSystem: 'host-billing/export',
            sourceInstallation: 'src-inst-1',
            sourceBook: 'book-main',
            sourceExportRevision: 1,
            exportCutoff: $cutoff,
            recordCount: 3,
            recordsChecksum: self::DIGEST_PAY,
            manifestDigest: $manifestDigest,
            sanitizationPolicy: ['piiRedacted' => true, 'secretsStripped' => true, 'policyVersion' => 'v1'],
            totalsByCurrency: $totals,
        );

        $oblIdentity = new ImportSourceIdentity(
            self::INSTALLATION,
            'test',
            'seller-a',
            'host-billing/export',
            'src-inst-1',
            'book-main',
            $oblRecordId,
            ImportRecordKind::ProvenObligation,
        );
        $frozen = ['priceVersionRef' => 'pv-rub-1', 'priceDigest' => self::DIGEST_PRICE, 'currency' => 'RUB'];
        $oblRefs = ['customerRef' => 'cust-1', 'billingAccountRef' => 'acct-1', 'obligationRef' => 'src-'.$oblRecordId];
        $oblPayload = ['originalBusinessRefs' => $oblRefs, 'frozenPriceSnapshot' => $frozen];
        $oblRecord = new ImportRecordInput(
            identity: $oblIdentity,
            batchId: $batchId,
            sourceExportRevision: 1,
            exportCutoff: $cutoff,
            manifestDigest: $manifestDigest,
            sourceRecordRevision: 1,
            eventBusinessDate: new DateTimeImmutable('2025-11-01T09:00:00Z'),
            payloadDigest: $codec->digest($oblPayload),
            originalBusinessRefs: $oblRefs,
            evidenceRefs: [new ImportEvidenceRef('ev-obl', 'price_snapshot', self::DIGEST_PRICE, true)],
            money: $money,
            frozenPriceSnapshot: $frozen,
            sourceFactRef: $oblIdentity->stableSourceFactRef(1, $codec),
            payload: $oblPayload,
        );

        $allocIdentity = new ImportSourceIdentity(
            self::INSTALLATION,
            'test',
            'seller-a',
            'host-billing/export',
            'src-inst-1',
            'book-main',
            'alloc-'.$payRecordId,
            ImportRecordKind::ProvenAllocation,
        );
        $allocRefs = [
            'customerRef' => 'cust-1',
            'billingAccountRef' => 'acct-1',
            'obligationRef' => 'src-'.$oblRecordId,
            'paymentRef' => 'src-'.$payRecordId,
        ];
        $allocPayload = ['originalBusinessRefs' => $allocRefs];
        $allocRecord = new ImportRecordInput(
            identity: $allocIdentity,
            batchId: $batchId,
            sourceExportRevision: 1,
            exportCutoff: $cutoff,
            manifestDigest: $manifestDigest,
            sourceRecordRevision: 1,
            eventBusinessDate: new DateTimeImmutable('2025-11-01T09:01:00Z'),
            payloadDigest: $codec->digest($allocPayload),
            originalBusinessRefs: $allocRefs,
            evidenceRefs: [new ImportEvidenceRef('ev-alloc', 'allocation_lineage', self::DIGEST_ALLOC, true)],
            money: $money,
            sourceFactRef: $allocIdentity->stableSourceFactRef(1, $codec),
            payload: $allocPayload,
        );

        $payIdentity = new ImportSourceIdentity(
            self::INSTALLATION,
            'test',
            'seller-a',
            'host-billing/export',
            'src-inst-1',
            'book-main',
            $payRecordId,
            ImportRecordKind::ProvenPaymentEvidence,
        );
        $funding = [
            'sourceAuthority' => 'source-gateway',
            'fundingStatus' => 'captured',
            'providerAccountRef' => 'pa-acct-1',
            'providerOperationRef' => 'psp-op-'.$payRecordId,
            'originalOperationRef' => 'orig-'.$payRecordId,
            'observedAt' => '2025-11-01T09:02:00Z',
        ];
        $payRefs = ['customerRef' => 'cust-1', 'billingAccountRef' => 'acct-1', 'paymentRef' => 'src-'.$payRecordId];
        $payPayload = ['originalBusinessRefs' => $payRefs, 'fundingRefs' => $funding];
        $payRecord = new ImportRecordInput(
            identity: $payIdentity,
            batchId: $batchId,
            sourceExportRevision: 1,
            exportCutoff: $cutoff,
            manifestDigest: $manifestDigest,
            sourceRecordRevision: 1,
            eventBusinessDate: new DateTimeImmutable('2025-11-01T09:02:00Z'),
            payloadDigest: $codec->digest($payPayload),
            originalBusinessRefs: $payRefs,
            evidenceRefs: [new ImportEvidenceRef('ev-pay', 'payment_provider_fact', self::DIGEST_PAY, true)],
            money: $money,
            fundingRefs: $funding,
            sourceFactRef: $payIdentity->stableSourceFactRef(1, $codec),
            payload: $payPayload,
        );

        return [$manifest, [$oblRecord, $allocRecord, $payRecord]];
    }
}
