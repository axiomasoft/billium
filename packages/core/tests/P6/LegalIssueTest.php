<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runner\OperationSpec;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Enums\Modules\ModuleOperationClass;
use Billium\Core\Enums\Runtime\FinancialMode;
use Billium\Core\Tests\P6\Support\DocumentsTestCase;
use Billium\Core\Tests\P6\Support\LegalIssueScenario;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\TestEnvironment;
use Billium\Documents\Contracts\DocumentsNumberingParticipant;
use Billium\Documents\Contracts\LedgerIssuePolicy;
use Billium\Documents\Data\AllocateLegalNumberCommand;
use Billium\Documents\Data\DocumentScope;
use Billium\Documents\Data\LegalDocumentView;
use Billium\Documents\Data\UpdateLegalDocumentDisplayMetadata;
use Billium\Documents\Data\VoidLegalDocument;
use Billium\Documents\Enums\DocumentType;
use Billium\Documents\Enums\LegalDocumentStatus;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Closure;
use PDOException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
final class LegalIssueTest extends DocumentsTestCase
{
    public function test_atomic_issue_replay_void_metadata_and_db_immutability_backstops(): void
    {
        $this->registerCompleteProfile();
        $this->registerSeries();
        $fin1 = $this->prepareFinalizedInvoiceBasis(suffix: 'doc1');

        $cmd1 = $this->issueCommand('cmd-issue-1', 'doc1', $fin1->approvedSnapshotDigest ?? '');
        $first = $this->issuer()->issueInvoiceDocument($cmd1);
        self::assertTrue($first->accepted);
        self::assertFalse($first->replayed);
        self::assertSame('INV-2026-000001', $first->body['legalNumber'] ?? null);
        self::assertSame(1, $first->body['sequenceNumber'] ?? null);

        // Invoice updated atomically with deterministic legalDocumentRef.
        $legalRef = (string) ($first->body['legalDocumentRef'] ?? '');
        self::assertSame('issued', $this->invoiceStatus('inv-doc1'));
        self::assertSame($legalRef, $this->invoiceRow('inv-doc1')['legal_document_ref']);

        // Queries & number journal match.
        $view = $this->docQueries()->find($this->docScope(), $legalRef);
        self::assertNotNull($view);
        self::assertSame('INV-2026-000001', $view->legalNumber);
        self::assertSame(LegalDocumentStatus::Issued, $view->status);
        self::assertSame(10000, $view->subtotalMinor);
        self::assertSame(2000, $view->taxMinor);
        self::assertSame(12000, $view->totalMinor);
        self::assertSame('half_up/1', $view->taxSnapshot['roundingPolicyVersion'] ?? null);
        self::assertSame('half_up/1', $view->linesSnapshot[0]['roundingPolicyVersion'] ?? null);

        $byEconomic = $this->docQueries()->findByEconomicRef($this->docScope(), DocumentType::Invoice, 'inv-doc1', 1);
        self::assertNotNull($byEconomic);
        self::assertSame($legalRef, $byEconomic->legalDocumentRef);

        // Lost-reply replay with same commandKey returns stored outcome without allocating another number.
        $replayed = $this->issuer()->issueInvoiceDocument($cmd1);
        self::assertTrue($replayed->accepted);
        self::assertTrue($replayed->replayed);
        self::assertSame('INV-2026-000001', $replayed->body['legalNumber'] ?? null);
        self::assertSame(2, $this->value("SELECT next_number FROM billium_document_series WHERE series_ref='INV'"));

        // Allow-listed display metadata update succeeds; disallowed keys fail explicitly.
        $metaOutcome = $this->issuer()->updateDisplayMetadata(new UpdateLegalDocumentDisplayMetadata(
            commandKey: 'meta-update-1',
            scope: $this->docScope(),
            legalDocumentRef: $legalRef,
            displayMetadata: ['displayLabel' => 'Duplicate Copy', 'locale' => 'en-US'],
        ));
        self::assertTrue($metaOutcome->accepted);
        $metaActual = $this->docQueries()->find($this->docScope(), $legalRef)?->displayMetadata;
        self::assertIsArray($metaActual);
        ksort($metaActual);
        self::assertSame(['displayLabel' => 'Duplicate Copy', 'locale' => 'en-US'], $metaActual);

        try {
            $this->issuer()->updateDisplayMetadata(new UpdateLegalDocumentDisplayMetadata(
                commandKey: 'meta-bad-key',
                scope: $this->docScope(),
                legalDocumentRef: $legalRef,
                displayMetadata: ['totalMinor' => 0],
            ));
            self::fail('Non-allow-listed display metadata key must be refused');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
        }

        // Voiding does NOT decrement or reuse number #1; next invoice takes #2.
        $voided = $this->issuer()->voidLegalDocument(new VoidLegalDocument(
            commandKey: 'void-doc1',
            scope: $this->docScope(),
            legalDocumentRef: $legalRef,
            reason: 'duplicate_customer_order',
        ));
        self::assertTrue($voided->accepted);
        self::assertSame('voided', $voided->body['status'] ?? null);

        $fin2 = $this->prepareFinalizedInvoiceBasis(suffix: 'doc2');
        $second = $this->issuer()->issueInvoiceDocument($this->issueCommand('cmd-issue-2', 'doc2', $fin2->approvedSnapshotDigest ?? ''));
        self::assertTrue($second->accepted);
        self::assertSame('INV-2026-000002', $second->body['legalNumber'] ?? null);
        self::assertSame(2, $second->body['sequenceNumber'] ?? null);
        self::assertSame(3, $this->value("SELECT next_number FROM billium_document_series WHERE series_ref='INV'"));

        $journal = $this->docQueries()->numberJournal($this->docScope(), 'INV', '2026');
        self::assertCount(3, $journal);
        self::assertSame(['allocated', 'voided', 'allocated'], array_map(static fn ($e): string => $e->eventKind, $journal));

        // PostgreSQL immutability backstop triggers block raw SQL mutation even from owner/runtime.
        $ownerPdo = TestEnvironment::pdo(owner: true);
        foreach ([
            "UPDATE public.billium_document_legal SET legal_number = 'TAMPERED' WHERE legal_document_ref = '{$legalRef}'",
            "UPDATE public.billium_document_legal SET total_minor = 1 WHERE legal_document_ref = '{$legalRef}'",
            "UPDATE public.billium_document_legal SET status = 'issued' WHERE legal_document_ref = '{$legalRef}'",
            "DELETE FROM public.billium_document_legal WHERE legal_document_ref = '{$legalRef}'",
            "UPDATE public.billium_document_series SET next_number = 1 WHERE series_ref = 'INV'",
            'DELETE FROM public.billium_document_number_journal',
        ] as $sql) {
            try {
                $ownerPdo->exec($sql);
                self::fail('Immutability trigger should have rejected SQL: '.$sql);
            } catch (PDOException $exception) {
                $sqlState = $exception->errorInfo[0] ?? null;
                self::assertContains($sqlState, ['23514', '55000'], $sql);
            }
        }
    }

    public function test_twenty_concurrent_workers_same_basis_allocate_single_number(): void
    {
        $this->registerCompleteProfile();
        $this->registerSeries();
        $fin = $this->prepareFinalizedInvoiceBasis(suffix: 'race-same');

        $results = ConcurrencyStand::run(LegalIssueScenario::class, 20, [
            'mode' => 'same_basis',
            'invoiceRef' => 'inv-race-same',
            'finalizationRef' => 'fin-race-same',
            'digest' => $fin->approvedSnapshotDigest,
            'seriesRef' => 'INV',
            'periodKey' => '2026',
        ], 60);

        self::assertCount(20, $results);
        $legalNumbers = [];
        foreach ($results as $workerResult) {
            self::assertArrayHasKey('ok', $workerResult, json_encode($workerResult, JSON_THROW_ON_ERROR));
            $ok = $workerResult['ok'];
            self::assertTrue($ok['accepted'] ?? false, json_encode($ok, JSON_THROW_ON_ERROR));
            $legalNumbers[] = $ok['legalNumber'] ?? null;
        }
        self::assertSame(['INV-2026-000001'], array_values(array_unique($legalNumbers)));
        self::assertSame(1, $this->rows('billium_document_legal'));
        self::assertSame(1, $this->rows('billium_document_number_journal'));
        self::assertSame(2, $this->value("SELECT next_number FROM billium_document_series WHERE series_ref='INV'"));
    }

    public function test_twenty_concurrent_workers_different_bases_allocate_unique_contiguous_numbers(): void
    {
        $this->registerCompleteProfile();
        $this->registerSeries();

        $bases = [];
        for ($i = 0; $i < 20; $i++) {
            $suffix = 'd'.$i;
            $fin = $this->prepareFinalizedInvoiceBasis(suffix: $suffix);
            $bases[] = [
                'invoiceRef' => 'inv-'.$suffix,
                'finalizationRef' => 'fin-'.$suffix,
                'digest' => $fin->approvedSnapshotDigest ?? '',
            ];
        }

        $results = ConcurrencyStand::run(LegalIssueScenario::class, 20, [
            'mode' => 'different_basis',
            'seriesRef' => 'INV',
            'periodKey' => '2026',
            'bases' => $bases,
        ], 60);

        self::assertCount(20, $results);
        $sequences = [];
        $numbers = [];
        foreach ($results as $workerResult) {
            self::assertArrayHasKey('ok', $workerResult, json_encode($workerResult, JSON_THROW_ON_ERROR));
            $ok = $workerResult['ok'];
            self::assertTrue($ok['accepted'] ?? false, json_encode($ok, JSON_THROW_ON_ERROR));
            $sequences[] = (int) $ok['sequenceNumber'];
            $numbers[] = (string) $ok['legalNumber'];
        }
        sort($sequences);
        self::assertSame(range(1, 20), $sequences);
        self::assertCount(20, array_unique($numbers));
        self::assertSame(20, $this->rows('billium_document_legal'));
        self::assertSame(20, $this->rows('billium_document_number_journal'));
        self::assertSame(21, $this->value("SELECT next_number FROM billium_document_series WHERE series_ref='INV'"));
    }

    public function test_rollback_after_ledger_issue_leaves_no_legal_document_or_number(): void
    {
        $this->registerCompleteProfile();
        $this->registerSeries();
        $fin = $this->prepareFinalizedInvoiceBasis(suffix: 'rb');

        // Replace numbering participant with one that fails right AFTER ledger issue participant runs.
        $this->application()->instance(DocumentsNumberingParticipant::class, new class implements DocumentsNumberingParticipant
        {
            public function allocateAndRecord(TransactionContext $context, AllocateLegalNumberCommand $command): LegalDocumentView
            {
                throw new RuntimeException('simulated failure after ledger issue');
            }
        });

        try {
            $this->issuer()->issueInvoiceDocument($this->issueCommand('cmd-rb', 'rb', $fin->approvedSnapshotDigest ?? ''));
            self::fail('Simulated numbering crash must roll back whole transaction');
        } catch (RuntimeException $error) {
            self::assertSame('simulated failure after ledger issue', $error->getMessage());
        }

        // Neither ledger invoice issuance nor documents numbering/legal document survived.
        self::assertSame('draft', $this->invoiceStatus('inv-rb'));
        self::assertNull($this->invoiceRow('inv-rb')['legal_document_ref']);
        self::assertSame(0, $this->rows('billium_document_legal'));
        self::assertSame(0, $this->rows('billium_document_number_journal'));
        self::assertSame(1, $this->value("SELECT next_number FROM billium_document_series WHERE series_ref='INV'"));
    }

    public function test_forged_context_and_cross_seller_series_are_refused_before_writes(): void
    {
        $this->registerCompleteProfile();
        $series = $this->registerSeries();
        $fin = $this->prepareFinalizedInvoiceBasis(suffix: 'sec');

        $forgedContext = new class implements TransactionContext
        {
            public function caller(): Caller
            {
                return new Caller('seller-a', 'test', 'inst-test', 'forged');
            }

            public function spec(): OperationSpec
            {
                return new OperationSpec(
                    'documents.legal_document.issue.v1',
                    'seller-a',
                    'test',
                    FinancialMode::Administrative,
                    ModuleOperationClass::NewIntent,
                    ['platform', 'ledger', 'documents'],
                );
            }

            public function connectionName(): string
            {
                return 'pgsql';
            }

            public function attempt(): int
            {
                return 1;
            }

            public function rootRef(): string
            {
                return 'forged-root';
            }

            public function sessionEpoch(): int
            {
                return 1;
            }

            /** @return array<string, int> */
            public function admittedModules(): array
            {
                return ['platform' => 1, 'ledger' => 1, 'documents' => 1];
            }

            public function moduleGeneration(string $moduleId): int
            {
                return 1;
            }

            public function isActive(): bool
            {
                return true;
            }

            public function assertActive(?string $connectionName = null): void {}

            public function assertParticipant(string $moduleId, ?string $connectionName = null): void {}

            public function lock(LockRequest ...$requests): array
            {
                return [];
            }

            public function afterCommit(Closure $callback): void {}
        };

        /** @var LedgerIssuePolicy $ledgerPolicy */
        $ledgerPolicy = $this->application()->make(LedgerIssuePolicy::class);
        try {
            $ledgerPolicy->issue($forgedContext, new IssueInvoice('forged-1', $this->account(), 'inv-sec'), 'ldoc-forged');
            self::fail('Forged TransactionContext must be rejected by LedgerIssuePolicy');
        } catch (\Throwable $error) {
            self::assertNotInstanceOf(AssertionFailedError::class, $error);
        }
        self::assertSame('draft', $this->invoiceStatus('inv-sec'));
        self::assertSame(0, $this->rows('billium_document_legal'));

        /** @var DocumentsNumberingParticipant $numbering */
        $numbering = $this->application()->make(DocumentsNumberingParticipant::class);
        try {
            $numbering->allocateAndRecord($forgedContext, new AllocateLegalNumberCommand(
                legalDocumentRef: 'ldoc-forged',
                documentType: DocumentType::Invoice,
                economicRef: 'inv-sec',
                documentRevision: 1,
                seriesId: $series->id,
                profileId: $fin->profileId,
                profileVersion: 1,
                finalizationId: $fin->id,
                basisRevision: 1,
                basisDigest: $fin->basisDigest,
                approvedSnapshotDigest: $fin->approvedSnapshotDigest ?? '',
                currency: 'RUB',
                subtotalMinor: 10000,
                taxMinor: 2000,
                totalMinor: 12000,
                sellerSnapshot: [],
                buyerSnapshot: [],
                taxSnapshot: [],
                termsSnapshot: [],
                linesSnapshot: [],
                displayMetadata: [],
                receiptRef: 'forged-receipt',
            ));
            self::fail('Forged TransactionContext must be rejected by DocumentsNumberingParticipant');
        } catch (\Throwable $error) {
            self::assertNotInstanceOf(AssertionFailedError::class, $error);
        }

        // Cross-seller isolation: Seller B registers its own profile/draft and tries Seller A's series ('INV-SELLER-A-ONLY').
        $this->registerSeries(seriesRef: 'ONLYA', periodKey: '2026');
        $this->callers->as('seller-b');
        $sellerBScope = new DocumentScope('seller-b', 'test');
        $this->registerCompleteProfile('prof-b', 1, $sellerBScope);
        $finB = $this->prepareFinalizedInvoiceBasis(suffix: 'b1', profileRef: 'prof-b');

        try {
            $this->issuer()->issueInvoiceDocument($this->issueCommand('cmd-cross-seller', 'b1', $finB->approvedSnapshotDigest ?? '', seriesRef: 'ONLYA'));
            self::fail('Cross-seller series usage must be refused');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::AuthDenied, $error->problem);
            self::assertSame('billium.documents.series_not_in_scope', $error->detail);
        }

        self::assertSame('draft', $this->invoiceStatus('inv-b1'));
        self::assertSame(0, $this->rows('billium_document_legal'));
    }

    public function test_zero_ledger_tax_with_positive_finalized_tax_refuses_issue(): void
    {
        $this->registerCompleteProfile();
        $this->registerSeries();
        $fin = $this->prepareFinalizedInvoiceBasis(
            suffix: 'notax',
            netMinor: 10000,
            taxMinor: 2000,
            includeLedgerTax: false,
        );

        try {
            $this->issuer()->issueInvoiceDocument($this->issueCommand('cmd-notax', 'notax', $fin->approvedSnapshotDigest ?? ''));
            self::fail('Issuance must refuse a 10000 zero-tax ledger invoice against a 12000 finalized tax snapshot');
        } catch (ProblemException $error) {
            self::assertSame(ProblemCode::ValidationInvalid, $error->problem);
            self::assertSame('billium.documents.basis_changed_under_lock', $error->detail);
        }

        self::assertSame('draft', $this->invoiceStatus('inv-notax'));
        self::assertNull($this->invoiceRow('inv-notax')['legal_document_ref']);
        self::assertSame(0, $this->rows('billium_document_legal'));
        self::assertSame(0, $this->rows('billium_document_number_journal'));
        self::assertSame(1, $this->value("SELECT next_number FROM billium_document_series WHERE series_ref='INV'"));
    }
}
