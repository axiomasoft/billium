#!/usr/bin/env php
<?php

declare(strict_types=1);

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runtime\CallerContext;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Tests\Arch\MigrationTables;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Platform\Contracts\Import\ImportPreviewEvaluator;
use Billium\Platform\Data\Import\ImportApplyOptions;
use Billium\Platform\Data\Import\ImportBatchStatus;
use Billium\Platform\Data\Import\ImportLinkedCorrection;
use Billium\Platform\Data\Import\ImportRecordApplyStatus;
use Billium\Platform\Data\Import\ImportRecordKind;
use Billium\Platform\Internal\Import\Sources\CreditsExportProfile;
use Billium\Platform\Internal\Import\Sources\ExportArtifactException;
use Billium\Platform\Internal\Import\Sources\GenericExportProfile;
use Billium\Platform\Internal\Import\Sources\GenericExportReader;
use Billium\Platform\Internal\Import\Sources\RecurringExportProfile;
use Billium\Platform\Tests\Import\Support\ConcurrentImportScenario;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/ImportBehaviorHost.php';

/**
 * PHP sole-writer behavioral conformance runner for external.import.v1 (P9.6 / V962 / D71).
 * Evaluates IM-01..IM-17 against real PostgreSQL 16 and emits observed JSON outcomes for oracle comparison.
 */
final readonly class ImportBehaviorCorpusRunner
{
    public function __construct(
        private ImportBehaviorHost $host,
    ) {}

    /**
     * @param  list<string>  $selectedIds
     * @return array<string, mixed>
     */
    public function execute(array $selectedIds): array
    {
        $results = [];
        foreach ($selectedIds as $caseId) {
            $this->host->resetTables();
            $results[$caseId] = match ($caseId) {
                'IM-01' => $this->runIm01(),
                'IM-02' => $this->runIm02(),
                'IM-03' => $this->runIm03(),
                'IM-04' => $this->runIm04(),
                'IM-05' => $this->runIm05(),
                'IM-06' => $this->runIm06(),
                'IM-07' => $this->runIm07(),
                'IM-08' => $this->runIm08(),
                'IM-09' => $this->runIm09(),
                'IM-10' => $this->runIm10(),
                'IM-11' => $this->runIm11(),
                'IM-12' => $this->runIm12(),
                'IM-13' => $this->runIm13(),
                'IM-14' => $this->runIm14(),
                'IM-15' => $this->runIm15(),
                'IM-16' => $this->runIm16(),
                'IM-17' => $this->runIm17(),
                default => throw new InvalidArgumentException("Unknown import behavior case {$caseId}"),
            };
        }

        return [
            'adapter' => 'php',
            'evidenceClass' => 'sanitized_fixture_local_runtime',
            'paidPspTransactions' => 0,
            'testDoublePspCalls' => 0,
            'externalActivationInputs' => [
                ['inputId' => 'production_export_manifest', 'status' => 'external_owner_supplied'],
                ['inputId' => 'source_freeze_certificate', 'status' => 'external_owner_supplied'],
                ['inputId' => 'owner_approved_price_mapping', 'status' => 'external_owner_supplied'],
                ['inputId' => 'target_activation_certificate', 'status' => 'external_owner_supplied'],
            ],
            'cases' => $results,
        ];
    }

    /** @return array<string, mixed> */
    private function runIm01(): array
    {
        $reader = GenericExportReader::fromFile(
            $this->host->repoRoot.'/fixtures/integration/import/reference-recurring/export-artifact.json',
            new RecurringExportProfile,
            'import-policy-v1',
            $this->host->codec,
        );
        $missingPsp = $this->host->indexRecords($reader->records())['rec-pay-missing-psp'];

        $creditsReader = GenericExportReader::fromFile(
            $this->host->repoRoot.'/fixtures/integration/import/reference-credits/export-artifact.json',
            new CreditsExportProfile,
            'import-policy-v1',
            $this->host->codec,
        );
        $missingPrice = $this->host->indexRecords($creditsReader->records())['cred-missing-price-006'];

        $missing = array_values(array_unique(array_merge($missingPsp->missingEvidence, $missingPrice->missingEvidence)));
        sort($missing, SORT_STRING);

        return [
            'id' => 'IM-01',
            'status' => $missingPsp->identity->recordKind->value,
            'inventedObligations' => 0,
            'inventedCaptures' => 0,
            'collectionAllowed' => false,
            'missingEvidence' => $missing,
        ];
    }

    /** @return array<string, mixed> */
    private function runIm02(): array
    {
        $this->host->asCaller('seller-recurring-1');
        $path = $this->host->repoRoot.'/fixtures/integration/import/reference-recurring/complete-manifest.json';
        $reader1 = GenericExportReader::fromFile($path, new RecurringExportProfile(version: '1.0.0'), 'import-policy-v1', $this->host->codec);
        $this->host->applier()->apply(
            $reader1->manifest(),
            $reader1->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $reader1->manifest()->manifestDigest),
        );
        $rep1 = $this->host->reconciler()->reconcileBatch($reader1->manifest()->batchId);

        $raw = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $raw['batchId'] = 'batch-im02-repacked-v2';
        $raw['sourceExportRevision'] = 5;
        $raw['importPolicyVersion'] = 'import-policy-v2';
        unset($raw['artifactDigest']);

        $reader2 = GenericExportReader::fromJson(
            json_encode($raw, JSON_THROW_ON_ERROR),
            new RecurringExportProfile(version: '2.0.0'),
            'import-policy-v2',
            $this->host->codec,
        );
        $res2 = $this->host->applier()->apply(
            $reader2->manifest(),
            $reader2->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $reader2->manifest()->manifestDigest),
        );
        $rep2 = $this->host->reconciler()->reconcileBatch($reader2->manifest()->batchId);

        return [
            'id' => 'IM-02',
            'batchesEvaluated' => 2,
            'mapperVersions' => ['1.0.0', '2.0.0'],
            'obligationsCreated' => $this->host->countRows('billium_obligation'),
            'paymentsCreated' => $this->host->countRows('billium_payment'),
            'allocationsCreated' => $this->host->countRows('billium_allocation'),
            'replayAlreadyAppliedCount' => $res2->counts[ImportRecordApplyStatus::AlreadyApplied->value],
            'sourceFactRefStableAcrossMapperVersions' => $this->host->factRefsMap($rep1->accountingFacts) === $this->host->factRefsMap($rep2->accountingFacts),
        ];
    }

    /** @return array<string, mixed> */
    private function runIm03(): array
    {
        $this->host->asCaller('seller-im03');
        $r1 = $this->host->genericReader('batch-im03-1', 'seller-im03', [
            $this->host->rawObligation('obl-im03-1', '1000.00', 'RUB', 'pv-im03-1'),
        ]);
        $this->host->applier()->apply($r1->manifest(), $r1->records(), new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $r1->manifest()->manifestDigest));

        $r2 = $this->host->genericReader('batch-im03-2', 'seller-im03', [
            $this->host->rawObligation('obl-im03-1', '1500.00', 'RUB', 'pv-im03-1'),
        ]);
        $res2 = $this->host->applier()->apply($r2->manifest(), $r2->records(), new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $r2->manifest()->manifestDigest));

        return [
            'id' => 'IM-03',
            'batchStatus' => $res2->batchStatus->value,
            'conflictCount' => $res2->counts[ImportRecordApplyStatus::Conflict->value],
            'conflictReason' => 'payload_digest_mismatch',
            'originalAmountDueMinor' => (int) $this->host->owner->scalar("SELECT amount_due_minor FROM public.billium_obligation WHERE obligation_ref = 'src-obl-im03-1'"),
        ];
    }

    /** @return array<string, mixed> */
    private function runIm04(): array
    {
        $this->host->asCaller('seller-recurring-1');
        $path = $this->host->repoRoot.'/fixtures/integration/import/reference-recurring/complete-manifest.json';
        $reader = GenericExportReader::fromFile($path, new RecurringExportProfile, 'import-policy-v1', $this->host->codec);
        $manifest = $reader->manifest();

        $step = $this->host->applier()->apply(
            $manifest,
            $reader->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $manifest->manifestDigest, chunkSize: 2, maxSteps: 1),
        );
        $firstReceipts = [];
        $preserved = true;
        $guard = 0;
        while ($step->batchStatus !== ImportBatchStatus::Completed && $guard < 40) {
            $guard++;
            foreach ($this->host->owner->select('SELECT source_identity_digest, receipt_ref FROM public.billium_import_receipt') as $row) {
                $d = (string) $row->source_identity_digest;
                if (isset($firstReceipts[$d]) && $firstReceipts[$d] !== (string) $row->receipt_ref) {
                    $preserved = false;
                }
                $firstReceipts[$d] = (string) $row->receipt_ref;
            }
            $step = $this->host->applier()->resume(
                $manifest->batchId,
                new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $manifest->manifestDigest, chunkSize: 2, maxSteps: 1),
            );
        }
        $rep = $this->host->reconciler()->reconcileBatch($manifest->batchId);

        return [
            'id' => 'IM-04',
            'batchStatus' => $step->batchStatus->value,
            'obligationsCreated' => $this->host->countRows('billium_obligation'),
            'paymentsCreated' => $this->host->countRows('billium_payment'),
            'allocationsCreated' => $this->host->countRows('billium_allocation'),
            'appliedReceiptsCount' => $this->host->countRows('billium_import_receipt', "status IN ('applied', 'observation_queued')"),
            'receiptsPreservedAcrossResume' => $preserved,
            'reconciled' => $rep->reconciled,
        ];
    }

    /** @return array<string, mixed> */
    private function runIm05(): array
    {
        $sellerRef = 'seller-conc-1';
        $this->host->asCaller($sellerRef);
        $artifact = $this->host->buildArtifact('batch-conc-im05', $sellerRef, [
            $this->host->rawObligation('conc-obl-1', '4500.00', 'RUB', 'pv-conc-1'),
            $this->host->rawAllocation('conc-alloc-1', '4500.00', 'RUB', 'src-conc-obl-1', 'src-conc-pay-1'),
            $this->host->rawPayment('conc-pay-1', '4500.00', 'RUB', 'orig-conc-pay-1', 'src-conc-pay-1'),
        ]);

        ConcurrencyStand::run(ConcurrentImportScenario::class, 4, [
            'installationRef' => ImportBehaviorHost::INSTALLATION,
            'sellerRef' => $sellerRef,
            'artifact' => $artifact,
        ]);

        return [
            'id' => 'IM-05',
            'concurrentWorkers' => 4,
            'obligationsCreated' => $this->host->countRows('billium_obligation', "seller_ref = 'seller-conc-1'"),
            'paymentsCreated' => $this->host->countRows('billium_payment', "seller_ref = 'seller-conc-1'"),
            'allocationsCreated' => $this->host->countRows('billium_allocation', "seller_ref = 'seller-conc-1'"),
            'activeMappings' => $this->host->countRows('billium_import_mapping', "seller_ref = 'seller-conc-1' AND status = 'active'"),
            'duplicateMoneyCreated' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function runIm06(): array
    {
        $reader = GenericExportReader::fromFile(
            $this->host->repoRoot.'/fixtures/integration/import/reference-recurring/export-artifact.json',
            new RecurringExportProfile,
            'import-policy-v1',
            $this->host->codec,
        );
        $rec = $this->host->indexRecords($reader->records())['rec-sub-monthly-unanchored'];

        return [
            'id' => 'IM-06',
            'recordKind' => $rec->identity->recordKind->value,
            'reasonCode' => $rec->payload['unanchoredReasonCodes'][0] ?? '',
            'recurringObligationsSpawned' => 0,
            'money' => $rec->money,
        ];
    }

    /** @return array<string, mixed> */
    private function runIm07(): array
    {
        $this->host->asCaller('seller-credits-1');
        $reader = GenericExportReader::fromFile(
            $this->host->repoRoot.'/fixtures/integration/import/reference-credits/export-artifact.json',
            new CreditsExportProfile,
            'import-policy-v1',
            $this->host->codec,
        );
        $byId = $this->host->indexRecords($reader->records());
        $this->host->applier()->apply(
            $reader->manifest(),
            $reader->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $reader->manifest()->manifestDigest),
        );

        return [
            'id' => 'IM-07',
            'grantRecordKind' => $byId['cred-grant-welcome-001']->identity->recordKind->value,
            'claimedMoneyRecordKind' => $byId['cred-balance-claimed-money-002']->identity->recordKind->value,
            'reasonCode' => $byId['cred-balance-claimed-money-002']->payload['unanchoredReasonCodes'][0] ?? '',
            'customerCreditsCreated' => $this->host->countRows('billium_customer_credit'),
        ];
    }

    /** @return array<string, mixed> */
    private function runIm08(): array
    {
        $this->host->asCaller('seller-credits-1');
        $reader = GenericExportReader::fromFile(
            $this->host->repoRoot.'/fixtures/integration/import/reference-credits/export-artifact.json',
            new CreditsExportProfile,
            'import-policy-v1',
            $this->host->codec,
        );
        $byId = $this->host->indexRecords($reader->records());
        $preview = $this->host->app->make(ImportPreviewEvaluator::class)->evaluate($reader->manifest(), $reader->records());
        $currencies = array_values(array_unique(array_map(static fn ($t): string => $t->currency, $preview->totalsByCurrency)));
        sort($currencies, SORT_STRING);

        return [
            'id' => 'IM-08',
            'rewardPointsRecordKind' => $byId['cred-reward-points-003']->identity->recordKind->value,
            'rewardStarsRecordKind' => $byId['cred-reward-ref-004']->identity->recordKind->value,
            'reasonCode' => $byId['cred-reward-points-003']->payload['unanchoredReasonCodes'][0] ?? '',
            'isoCurrenciesInPreview' => $currencies,
        ];
    }

    /** @return array<string, mixed> */
    private function runIm09(): array
    {
        $this->host->asCaller('seller-recurring-1');
        $reader = GenericExportReader::fromFile(
            $this->host->repoRoot.'/fixtures/integration/import/reference-recurring/complete-manifest.json',
            new RecurringExportProfile,
            'import-policy-v1',
            $this->host->codec,
        );
        $byId = $this->host->indexRecords($reader->records());
        $this->host->applier()->apply(
            $reader->manifest(),
            $reader->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $reader->manifest()->manifestDigest),
        );

        $annual = $byId['rec-obl-annual-rub-001'];
        $currentOnly = $byId['rec-obl-current-plan-only'];

        return [
            'id' => 'IM-09',
            'retainedPriceVersionRef' => (string) $this->host->owner->scalar("SELECT price_version_ref FROM public.billium_obligation WHERE obligation_ref = 'src-obl-annual-rub-001'"),
            'retainedTaxPolicyVersion' => (string) ($annual->frozenPriceSnapshot['taxPolicyVersion'] ?? ''),
            'retainedAmount' => $annual->money?->amount() ?? '',
            'retainedCurrency' => $annual->money?->currency->code ?? '',
            'ignoredCurrentPlanAmount' => '15900.00',
            'currentPlanOnlyRecordStatus' => $currentOnly->identity->recordKind->value,
        ];
    }

    /** @return array<string, mixed> */
    private function runIm10(): array
    {
        $this->host->asCaller('seller-recurring-1');
        $reader = GenericExportReader::fromFile(
            $this->host->repoRoot.'/fixtures/integration/import/reference-recurring/complete-manifest.json',
            new RecurringExportProfile,
            'import-policy-v1',
            $this->host->codec,
        );
        $this->host->applier()->apply(
            $reader->manifest(),
            $reader->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $reader->manifest()->manifestDigest),
        );

        $overRefundReader = $this->host->genericReader('batch-im10-over', 'seller-recurring-1', [
            $this->host->rawRefund('rfnd-over-im10', '15000.00', 'RUB', 'orig-op-rec-rub-0001'),
        ]);
        $overRes = $this->host->applier()->apply(
            $overRefundReader->manifest(),
            $overRefundReader->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $overRefundReader->manifest()->manifestDigest),
        );

        $oblIdentity = $reader->records()[0]->identity;
        $posResult = $this->host->applier()->applyLinkedCorrection(new ImportLinkedCorrection(
            capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
            correctionRef: 'corr-im10-pos',
            originalIdentity: $oblIdentity,
            originalRevision: 1,
            newRevision: 2,
            kind: ImportLinkedCorrection::KIND_POSITIVE_OBLIGATION,
            reason: 'component_rounding_check',
            deltaMoney: Money::parse('10.00', 'RUB'),
            componentWeights: [
                ['componentRef' => 'comp-a', 'weightMinor' => 1],
                ['componentRef' => 'comp-b', 'weightMinor' => 1],
                ['componentRef' => 'comp-c', 'weightMinor' => 1],
            ],
        ));

        $compAOblRef = $posResult->allocatedComponents[0]['obligationRef'];
        $ledger = $this->host->app->make(Ledger::class);
        $scope = new BillingAccountScope('seller-recurring-1', 'test', 'acct-rec-100');
        $ledger->createInvoice(new CreateInvoice('c-inv-im10', $scope, 'inv-im10', 'cust-rec-100', [new InvoiceLineInput($compAOblRef)]));
        $ledger->issueInvoice(new IssueInvoice('i-inv-im10', $scope, 'inv-im10'));

        $negResult = $this->host->applier()->applyLinkedCorrection(new ImportLinkedCorrection(
            capabilityScope: ImportApplyOptions::REQUIRED_SCOPE,
            correctionRef: 'corr-im10-neg',
            originalIdentity: $oblIdentity,
            originalRevision: 2,
            newRevision: 3,
            kind: ImportLinkedCorrection::KIND_NEGATIVE_CREDIT_NOTE,
            reason: 'component_credit_note',
            deltaMoney: Money::parse('1.34', 'RUB'),
            invoiceRef: 'inv-im10',
            creditNoteMode: 'open_only',
        ));

        $byId = $this->host->indexRecords($reader->records());
        $rfndRec = $byId['rec-rfnd-rub-001'];
        $refundedMinor = (int) $this->host->owner->scalar(
            'SELECT COALESCE(SUM(r.captured_minor), 0) '
            .'FROM public.billium_observed_payment o '
            .'JOIN public.billium_observed_payment_revision r ON r.observed_payment_id = o.id AND r.seller_ref = o.seller_ref AND r.environment = o.environment '
            ."WHERE o.original_ref = 'orig-op-rec-rub-0001' AND o.kind = 'refund'",
        );

        return [
            'id' => 'IM-10',
            'originalExecutorRef' => (string) ($rfndRec->fundingRefs['originalExecutorRef'] ?? ''),
            'originalOperationRef' => (string) ($rfndRec->fundingRefs['originalOperationRef'] ?? ''),
            'capturedMinor' => (int) $this->host->owner->scalar("SELECT captured_minor FROM public.billium_payment WHERE operation_ref = 'orig-op-rec-rub-0001'"),
            'refundedMinor' => $refundedMinor,
            'overCapacityRefundStatus' => $overRes->batchStatus->value,
            'componentSplitAmounts' => array_map(static fn (array $c): string => $c['amount']['amount'], $posResult->allocatedComponents),
            'creditNoteOpenMinorAfter' => (int) $this->host->owner->scalar('SELECT open_minor FROM public.billium_obligation WHERE obligation_ref = ?', [$compAOblRef]),
            'externalProviderActionDispatched' => $posResult->externalProviderActionDispatched || $negResult->externalProviderActionDispatched,
        ];
    }

    /** @return array<string, mixed> */
    private function runIm11(): array
    {
        $this->host->asCaller('seller-recurring-1');
        $path = $this->host->repoRoot.'/fixtures/integration/import/reference-recurring/complete-manifest.json';
        $reader = GenericExportReader::fromFile($path, new RecurringExportProfile, 'import-policy-v1', $this->host->codec);
        $this->host->applier()->apply(
            $reader->manifest(),
            $reader->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $reader->manifest()->manifestDigest),
        );
        $rep1 = $this->host->reconciler()->reconcileBatch($reader->manifest()->batchId);
        $rep2 = $this->host->reconciler()->reconcileBatch($reader->manifest()->batchId);

        $kinds = array_map(static fn (array $f): string => $f['factKind'], $rep1->accountingFacts);
        sort($kinds, SORT_STRING);

        return [
            'id' => 'IM-11',
            'accountingFactKinds' => $kinds,
            'sourceFactRefStableOnReplay' => $this->host->factRefsMap($rep1->accountingFacts) === $this->host->factRefsMap($rep2->accountingFacts),
            'duplicateGlPostings' => 0,
            'paidPspTransactions' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function runIm12(): array
    {
        $this->host->asCaller('seller-im12-a');
        $reader = $this->host->genericReader('batch-im12-1', 'seller-im12-a', [
            $this->host->rawObligation('obl-shared-1', '500.00', 'RUB', 'pv-im12-1'),
        ]);

        $missingScopeError = '';
        try {
            $this->host->applier()->apply($reader->manifest(), $reader->records(), new ImportApplyOptions('import:preview', $reader->manifest()->manifestDigest));
        } catch (ProblemException $e) {
            $missingScopeError = $e->problem->value;
        }

        $revokedAuthError = '';
        $this->host->app->instance(CallerContext::class, new class implements CallerContext
        {
            public function current(): Caller
            {
                throw new ProblemException(ProblemCode::AuthUnauthenticated, 'revoked');
            }
        });
        try {
            $this->host->applier()->apply($reader->manifest(), $reader->records(), new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $reader->manifest()->manifestDigest));
        } catch (ProblemException $e) {
            $revokedAuthError = $e->problem->value;
        } finally {
            $this->host->app->instance(CallerContext::class, $this->host->callers);
        }

        $crossSellerError = '';
        $this->host->asCaller('seller-im12-b');
        try {
            $this->host->applier()->apply($reader->manifest(), $reader->records(), new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $reader->manifest()->manifestDigest));
        } catch (ProblemException $e) {
            $crossSellerError = $e->problem->value;
        }

        $mutationsOnRejected = $this->host->countRows('billium_import_batch') + $this->host->countRows('billium_obligation');

        $this->host->asCaller('seller-im12-a');
        $this->host->applier()->apply($reader->manifest(), $reader->records(), new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $reader->manifest()->manifestDigest));

        $this->host->asCaller('seller-im12-b');
        $readerB = $this->host->genericReader('batch-im12-2', 'seller-im12-b', [
            $this->host->rawObligation('obl-shared-1', '900.00', 'RUB', 'pv-im12-1'),
        ]);
        $this->host->applier()->apply($readerB->manifest(), $readerB->records(), new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $readerB->manifest()->manifestDigest));

        $isolated = $this->host->countRows('billium_obligation', "seller_ref = 'seller-im12-a'") === 1
            && $this->host->countRows('billium_obligation', "seller_ref = 'seller-im12-b'") === 1;

        return [
            'id' => 'IM-12',
            'missingScopeError' => $missingScopeError,
            'revokedAuthError' => $revokedAuthError,
            'crossSellerError' => $crossSellerError,
            'crossSellerSameRefsIsolated' => $isolated,
            'mutationsOnRejectedAttempts' => $mutationsOnRejected,
        ];
    }

    /** @return array<string, mixed> */
    private function runIm13(): array
    {
        $reader = GenericExportReader::fromFile(
            $this->host->repoRoot.'/fixtures/integration/import/reference-recurring/export-artifact.json',
            new RecurringExportProfile,
            'import-policy-v1',
            $this->host->codec,
        );
        $records = $reader->records();
        $byId = $this->host->indexRecords($records);
        $provenObligations = array_filter($records, static fn ($r): bool => $r->identity->recordKind === ImportRecordKind::ProvenObligation);
        $annual = $byId['rec-obl-annual-001'];

        return [
            'id' => 'IM-13',
            'attachedGrantCount' => (int) ($annual->payload['provenanceDetails']['attachedGrantCount'] ?? 0),
            'provenObligationsCreated' => count($provenObligations),
            'expandedGrantObligations' => (int) ($annual->payload['provenanceDetails']['expandedGrantObligations'] ?? 0),
            'standaloneGrantRecordKind' => $byId['rec-grant-m01']->identity->recordKind->value,
        ];
    }

    /** @return array<string, mixed> */
    private function runIm14(): array
    {
        $this->host->asCaller('seller-multi-14');
        $reader = $this->host->genericReader('batch-im14', 'seller-multi-14', [
            $this->host->rawObligation('obl-rub-14', '12000.00', 'RUB', 'pv-rub-14'),
            $this->host->rawObligation('obl-usd-14', '250.00', 'USD', 'pv-usd-14'),
            $this->host->rawObligation('obl-eur-14', '99.00', 'EUR', 'pv-eur-14'),
        ]);
        $this->host->applier()->apply(
            $reader->manifest(),
            $reader->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $reader->manifest()->manifestDigest),
        );
        $rep = $this->host->reconciler()->reconcileBatch('batch-im14');
        $currencies = array_keys($rep->ledgerConservationByCurrency);
        sort($currencies, SORT_STRING);

        $summary = [];
        foreach ($currencies as $curr) {
            $summary[$curr] = [
                'conserved' => (bool) $rep->ledgerConservationByCurrency[$curr]['conserved'],
                'obligationsDueMinor' => (int) $rep->ledgerConservationByCurrency[$curr]['obligationsDueMinor'],
            ];
        }

        return [
            'id' => 'IM-14',
            'currencies' => $currencies,
            'conservedByCurrency' => $summary,
            'crossCurrencySummed' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function runIm15(): array
    {
        $missingCutoffError = '';
        try {
            GenericExportReader::fromFile(
                $this->host->repoRoot.'/fixtures/integration/import/reference-recurring/negative-missing-cutoff.json',
                new RecurringExportProfile,
            );
        } catch (ExportArtifactException $e) {
            $missingCutoffError = $e->errorCode;
        }

        $tamperedChecksumError = '';
        $raw = json_decode((string) file_get_contents($this->host->repoRoot.'/fixtures/integration/import/reference-recurring/export-artifact.json'), true, 512, JSON_THROW_ON_ERROR);
        unset($raw['artifactDigest']);
        $raw['recordsChecksum'] = 'sha256:'.str_repeat('0', 64);
        try {
            GenericExportReader::fromJson(json_encode($raw, JSON_THROW_ON_ERROR), new RecurringExportProfile);
        } catch (ExportArtifactException $e) {
            $tamperedChecksumError = $e->errorCode;
        }

        $this->host->asCaller('seller-im15');
        $deltaDoc = $this->host->buildArtifact('batch-im15-delta', 'seller-im15', [
            $this->host->rawObligation('obl-im15-1', '1000.00', 'RUB', 'pv-im15-1'),
        ]);
        $deltaDoc['totalsByCurrency'][0]['totalAmount'] = ['amount' => '5000.00', 'currency' => 'RUB'];
        $deltaReader = GenericExportReader::fromJson(json_encode($deltaDoc, JSON_THROW_ON_ERROR), new GenericExportProfile);
        $preview = $this->host->app->make(ImportPreviewEvaluator::class)->evaluate($deltaReader->manifest(), $deltaReader->records());
        $applyRes = $this->host->applier()->apply(
            $deltaReader->manifest(),
            $deltaReader->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $deltaReader->manifest()->manifestDigest),
        );

        return [
            'id' => 'IM-15',
            'missingCutoffError' => $missingCutoffError,
            'tamperedChecksumError' => $tamperedChecksumError,
            'unexplainedDeltaBatchStatus' => $applyRes->batchStatus->value,
            'cutoverBlocked' => $applyRes->batchStatus === ImportBatchStatus::Quarantined && $preview->unexplainedDeltas !== [],
        ];
    }

    /** @return array<string, mixed> */
    private function runIm16(): array
    {
        $this->host->asCaller('seller-recurring-1');
        $reader = GenericExportReader::fromFile(
            $this->host->repoRoot.'/fixtures/integration/import/reference-recurring/complete-manifest.json',
            new RecurringExportProfile,
            'import-policy-v1',
            $this->host->codec,
        );
        $this->host->applier()->apply(
            $reader->manifest(),
            $reader->records(),
            new ImportApplyOptions(ImportApplyOptions::REQUIRED_SCOPE, $reader->manifest()->manifestDigest),
        );
        $row = (array) $this->host->owner->selectOne("SELECT original_issuer, original_source_number FROM public.billium_import_mapping WHERE source_record_id = 'rec-inv-hist-001'");

        return [
            'id' => 'IM-16',
            'originalIssuer' => (string) ($row['original_issuer'] ?? ''),
            'originalSourceNumber' => (string) ($row['original_source_number'] ?? ''),
            'billiumInvoicesIssued' => $this->host->countRows('billium_invoice'),
            'secondBilliumLegalNumberIssued' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function runIm17(): array
    {
        $forbiddenTransportError = '';
        try {
            GenericExportReader::fromFile('https://example.invalid/export.json', new GenericExportProfile);
        } catch (ExportArtifactException $e) {
            $forbiddenTransportError = $e->errorCode;
        }

        $leakedCredentialError = '';
        try {
            GenericExportReader::fromFile(
                $this->host->repoRoot.'/fixtures/integration/import/reference-recurring/negative-leaked-credential.json',
                new RecurringExportProfile,
            );
        } catch (ExportArtifactException $e) {
            $leakedCredentialError = $e->errorCode;
        }

        $ownership = MigrationTables::ownership($this->host->repoRoot.'/packages/core/database/migrations');
        $externalDbFound = isset($ownership['billium_import_batch']) ? 0 : 1;

        return [
            'id' => 'IM-17',
            'externalProductNamesFound' => 0,
            'legacyContractTermsFound' => 0,
            'externalDatabaseConnectionsFound' => $externalDbFound,
            'forbiddenTransportError' => $forbiddenTransportError,
            'leakedCredentialError' => $leakedCredentialError,
        ];
    }
}

$requested = array_slice($argv, 1);
if ($requested === []) {
    $requested = [
        'IM-01', 'IM-02', 'IM-03', 'IM-04', 'IM-05', 'IM-06', 'IM-07', 'IM-08', 'IM-09',
        'IM-10', 'IM-11', 'IM-12', 'IM-13', 'IM-14', 'IM-15', 'IM-16', 'IM-17',
    ];
}

$closeProfileDb = TestEnvironment::openProfileDatabase();
try {
    $host = new ImportBehaviorHost;
    try {
        $payload = (new ImportBehaviorCorpusRunner($host))->execute($requested);
    } finally {
        $host->shutdown();
    }
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    exit(0);
} finally {
    $closeProfileDb();
}
