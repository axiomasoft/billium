<?php

declare(strict_types=1);

namespace Billium\Core\Tests\P6;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Configuration\BilliumOptions;
use Billium\Core\Plugins\PlatformPlugin;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Allocations\ReverseAllocation;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\CreditNoteLine;
use Billium\Ledger\Data\Invoices\CreditNoteMode;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Invoices\MarkUncollectible;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Platform\Contracts\Accounting\AccountingConsumer;
use Billium\Platform\Contracts\Accounting\AccountingMapper;
use Billium\Platform\Data\Accounting\AccountingFactCategory;
use Billium\Platform\Data\Accounting\AccountingFactKind;
use Billium\Platform\Data\Accounting\AccountingMappingStatus;
use Billium\Platform\Data\Accounting\AccountingSourceRecord;
use Billium\Platform\Data\Accounting\FinBridgeExportEnvelope;
use Billium\Platform\Internal\Accounting\AccountingFactMapper;
use Billium\Platform\Laravel\PlatformCapabilities;
use Billium\Platform\Tests\PlatformTestCase;
use Billium\Testkit\Accounting\AccountingExportExtension;
use Billium\Testkit\Accounting\FakeFinBridgeConsumer;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

#[Group('pg')]
final class AccountingFactsTest extends PlatformTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activeSession();
    }

    public function test_golden_vector_and_platform_mapper_work_without_documents_module(): void
    {
        $app = $this->application();
        $options = $app->make(BilliumOptions::class);
        self::assertNotContains('documents', $options->modules->enabled);

        $mapper = $app->make(AccountingMapper::class);
        self::assertInstanceOf(AccountingFactMapper::class, $mapper);
        self::assertArrayHasKey(AccountingMapper::class, PlatformCapabilities::map());
        self::assertArrayHasKey(AccountingMapper::class, (new PlatformPlugin)->definedCapabilities());
        self::assertArrayHasKey(AccountingMapper::class, (new PlatformPlugin)->providedCapabilities());

        $vectorPath = dirname(__DIR__, 4).'/fixtures/vectors/accounting/facts-and-exposure.json';
        self::assertFileExists($vectorPath);
        /** @var array{
         *     id: string,
         *     given: array{example: array{
         *         scope: array{installationRef: string, sellerRef: string, environment: string, providerAccountRef: string, currency: string},
         *         expectedFacts: array<string, array{sourceFactRef: string, factKind: string, category: string, amountMinor: int, originalFactRef: ?string, linkedCashFactRefs: list<string>, exceptional: bool}>,
         *         expectedCategoryTotalsMinor: array<string, int>,
         *         expectedCategoryCounts: array<string, int>
         *     }},
         *     script: list<array{op: string, args: array{json_text: string}}>,
         *     expect: list<array{ok: array{digest: string}}>
         * } $vector
         */
        $vector = json_decode((string) file_get_contents($vectorPath), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('accounting/facts-and-exposure', $vector['id']);

        $codec = new Codec;
        self::assertSame(
            $vector['expect'][0]['ok']['digest'],
            $codec->digest($vector['given']['example']),
        );

        $scope = $vector['given']['example']['scope'];
        $at = new DateTimeImmutable('2026-11-19T12:00:00Z');
        $consumer = new FakeFinBridgeConsumer;

        $baseRecords = [
            'invoice' => new AccountingSourceRecord(
                'billium.event.v1', 'invoice.issued.v1', $scope['installationRef'], $scope['sellerRef'], $scope['environment'], 'ledger:core', 'inv-100', 1, $at,
                ['invoice_ref' => 'inv-100', 'billing_account_ref' => 'billing-a', 'total_minor' => 100, 'currency' => $scope['currency']],
            ),
            'capture' => new AccountingSourceRecord(
                'billium.event.v2', 'payment.effect.outcome.v1', $scope['installationRef'], $scope['sellerRef'], $scope['environment'], 'execution:remote-billium', 'capture-1', 1, $at,
                ['operationRef' => 'capture-1', 'originalOperationRef' => 'root-a', 'operationKind' => 'capture', 'status' => 'succeeded', 'money' => ['amount' => '1.00', 'currency' => $scope['currency']], 'effectTotal' => '1.00', 'merchantAccountRef' => $scope['providerAccountRef'], 'providerEffectRef' => 'prov-cap-100', 'providerEvidenceRef' => 'ev-cap-100'],
            ),
            'alloc1' => new AccountingSourceRecord(
                'billium.event.v1', 'allocation.applied.v1', $scope['installationRef'], $scope['sellerRef'], $scope['environment'], 'ledger:core', 'ob-1', 1, $at,
                ['receipt_ref' => 'allocation:alloc-1', 'obligation_ref' => 'ob-1', 'amount_minor' => '60', 'currency' => $scope['currency'], 'source_kind' => 'payment', 'source_ref' => 'root-a'],
            ),
            'alloc2' => new AccountingSourceRecord(
                'billium.event.v1', 'allocation.applied.v1', $scope['installationRef'], $scope['sellerRef'], $scope['environment'], 'ledger:core', 'ob-2', 1, $at,
                ['receipt_ref' => 'allocation:alloc-2', 'obligation_ref' => 'ob-2', 'amount_minor' => '40', 'currency' => $scope['currency'], 'source_kind' => 'payment', 'source_ref' => 'root-a'],
            ),
            'creditNote' => new AccountingSourceRecord(
                'billium.event.v1', 'credit_note.issued.v1', $scope['installationRef'], $scope['sellerRef'], $scope['environment'], 'ledger:core', 'inv-100', 2, $at,
                ['credit_note_ref' => 'cn-30', 'invoice_ref' => 'inv-100', 'currency' => $scope['currency'], 'lines' => [['obligation_ref' => 'ob-1', 'amount_minor' => 30]]],
            ),
            'writeOff' => new AccountingSourceRecord(
                'accounting.source.v1', 'write_off.recorded.v1', $scope['installationRef'], $scope['sellerRef'], $scope['environment'], 'ledger:core', 'inv-100', 3, $at,
                ['write_off_ref' => 'wo-10', 'invoice_ref' => 'inv-100', 'amount_minor' => 10, 'currency' => $scope['currency'], 'reason' => 'uncollectible'],
            ),
        ];

        $mapped = [];
        foreach ($baseRecords as $key => $record) {
            $res = $consumer->consume($record, $mapper, "vec-attempt:{$key}");
            self::assertSame(AccountingMappingStatus::Mapped, $res->status);
            self::assertNotNull($res->fact);
            $mapped[$key] = $res->fact;
        }

        $captureRef = $mapped['capture']->sourceFactRef;
        $ref1Res = $consumer->consume(new AccountingSourceRecord(
            'accounting.source.v1', 'refund.succeeded.v1', $scope['installationRef'], $scope['sellerRef'], $scope['environment'], 'execution:remote-billium', 'refund-1', 1, $at,
            ['operationRef' => 'refund-1', 'originalOperationRef' => 'root-a', 'status' => 'succeeded', 'providerAccountRef' => $scope['providerAccountRef'], 'providerEffectRef' => 'prov-ref-1', 'effectMoney' => ['amount' => '0.70', 'currency' => $scope['currency']], 'originalFactRef' => $captureRef],
        ), $mapper, 'vec-attempt:refund1');
        $ref2Res = $consumer->consume(new AccountingSourceRecord(
            'accounting.source.v1', 'refund.succeeded.v1', $scope['installationRef'], $scope['sellerRef'], $scope['environment'], 'execution:remote-billium', 'refund-2', 2, $at,
            ['operationRef' => 'refund-2', 'originalOperationRef' => 'root-a', 'status' => 'succeeded', 'providerAccountRef' => $scope['providerAccountRef'], 'providerEffectRef' => 'prov-ref-2', 'effectMoney' => ['amount' => '0.70', 'currency' => $scope['currency']], 'originalFactRef' => $captureRef, 'exceptional' => true],
        ), $mapper, 'vec-attempt:refund2');

        self::assertNotNull($ref1Res->fact);
        self::assertNotNull($ref2Res->fact);
        $mapped['refund1'] = $ref1Res->fact;
        $mapped['refund2'] = $ref2Res->fact;

        $expRes = $consumer->consume(new AccountingSourceRecord(
            'accounting.source.v1', 'exposure.over_refund.v1', $scope['installationRef'], $scope['sellerRef'], $scope['environment'], 'execution:remote-billium', 'root-a', 3, $at,
            [
                'rootOperationRef' => 'root-a',
                'reason' => 'over_refund',
                'exposureMoney' => ['amount' => '0.40', 'currency' => $scope['currency']],
                'originalFactRef' => $captureRef,
                'linkedCashFactRefs' => [$mapped['refund2']->sourceFactRef, $mapped['refund1']->sourceFactRef],
                'contributingEffects' => [
                    ['providerEffectRef' => 'prov-ref-2', 'revision' => 2, 'digest' => 'sha256:'.str_repeat('3', 64)],
                    ['providerEffectRef' => 'prov-cap-100', 'revision' => 1, 'digest' => 'sha256:'.str_repeat('1', 64)],
                    ['providerEffectRef' => 'prov-ref-1', 'revision' => 1, 'digest' => 'sha256:'.str_repeat('2', 64)],
                ],
            ],
        ), $mapper, 'vec-attempt:exposure');
        self::assertNotNull($expRes->fact);
        $mapped['exposure'] = $expRes->fact;

        foreach ($vector['given']['example']['expectedFacts'] as $key => $expected) {
            $fact = $mapped[$key];
            self::assertSame($expected['sourceFactRef'], $fact->sourceFactRef, "sourceFactRef mismatch for {$key}");
            self::assertSame($expected['factKind'], $fact->factKind->value);
            self::assertSame($expected['category'], $fact->category->value);
            self::assertSame($expected['amountMinor'], $fact->money->minor);
            self::assertSame($expected['originalFactRef'], $fact->originalFactRef);
            self::assertSame($expected['linkedCashFactRefs'], $fact->linkedCashFactRefs);
            self::assertSame($expected['exceptional'], $fact->exceptional);
        }

        foreach (AccountingFactCategory::cases() as $category) {
            self::assertSame(
                $vector['given']['example']['expectedCategoryTotalsMinor'][$category->value],
                $consumer->totalMinorByCategory($category, $scope['currency']),
            );
            self::assertSame(
                $vector['given']['example']['expectedCategoryCounts'][$category->value],
                $consumer->appliedCount($category),
            );
        }
        self::assertSame(0, $consumer->glWriteCount());
    }

    public function test_multiple_allocations_on_one_capture_yield_one_cash_fact_and_linked_corrections_from_real_ledger_outbox(): void
    {
        $app = $this->application();
        $ledger = $app->make(Ledger::class);
        $mapper = $app->make(AccountingMapper::class);
        $consumer = new FakeFinBridgeConsumer;
        $account = new BillingAccountScope('seller-a', 'test', 'account-a');

        $ledger->recordObligation(new RecordObligation('ob-1', $account, 'ob-1', 'basis-1', 'cust-a', 'comp-1', new Money(6000, Currency::of('RUB')), 'sha256:'.str_repeat('a', 64)))->orThrow();
        $ledger->recordObligation(new RecordObligation('ob-2', $account, 'ob-2', 'basis-2', 'cust-a', 'comp-2', new Money(4000, Currency::of('RUB')), 'sha256:'.str_repeat('b', 64)))->orThrow();
        $ledger->createInvoice(new CreateInvoice('inv-create', $account, 'inv-1', 'cust-a', [
            new InvoiceLineInput('ob-1'),
            new InvoiceLineInput('ob-2'),
        ]))->orThrow();
        $ledger->issueInvoice(new IssueInvoice('inv-issue', $account, 'inv-1'))->orThrow();

        // Seed one captured payment in ledger projection (100.00 RUB) and allocate across 2 distinct obligations, then reverse 1 allocation and issue a credit note + write-off.
        $this->owner()->insert(<<<'SQL'
            INSERT INTO billium_payment(installation_ref,seller_ref,environment,billing_account_ref,currency,payment_ref,source_authority,source_lineage,operation_ref,kind,captured_minor)
            VALUES('inst-test','seller-a','test','account-a','RUB','pay-1','authority-a','lineage-a','op-pay-1','charge',10000)
            SQL);
        $ledger->allocate(new Allocate('alloc-k1', $account, 'alloc-1', 'ob-1', new Money(6000, Currency::of('RUB')), paymentRef: 'pay-1'))->orThrow();
        $ledger->allocate(new Allocate('alloc-k2', $account, 'alloc-2', 'ob-2', new Money(4000, Currency::of('RUB')), paymentRef: 'pay-1'))->orThrow();
        $ledger->reverseAllocation(new ReverseAllocation('rev-k1', $account, 'rev-1', 'alloc-2', 'customer_adjustment'))->orThrow();
        $ledger->issueCreditNote(new IssueCreditNote('cn-k1', $account, 'cn-1', 'inv-1', CreditNoteMode::OpenOnly, [
            new CreditNoteLine('ob-2', new Money(2000, Currency::of('RUB'))),
        ], 'partial_credit'))->orThrow();
        $ledger->markUncollectible(new MarkUncollectible('wo-k1', $account, 'inv-1', 'bad_debt', 'auth-op-1'))->orThrow();

        // Feed the single actual capture effect + replay it 4 times with different delivery attempts & aggregate revisions.
        $at = new DateTimeImmutable('2026-11-19T12:30:00Z');
        $captureFactRefs = [];
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $captureRecord = new AccountingSourceRecord(
                schemaVersion: 'billium.event.v2',
                eventType: 'payment.effect.outcome.v1',
                installationRef: self::INSTALLATION,
                sellerRef: 'seller-a',
                environment: 'test',
                sourceLineage: 'execution:remote-billium',
                aggregateRef: 'op-pay-1',
                aggregateRevision: $attempt,
                occurredAt: $at,
                payload: [
                    'operationRef' => 'cap-1',
                    'originalOperationRef' => 'op-pay-1',
                    'operationKind' => 'capture',
                    'status' => 'succeeded',
                    'money' => ['amount' => '100.00', 'currency' => 'RUB'],
                    'effectTotal' => '100.00',
                    'merchantAccountRef' => 'merchant-a',
                    'providerEffectRef' => 'prov-cap-001',
                    'providerEvidenceRef' => 'ev-cap-001',
                ],
                deliveryAttemptRef: "cap-delivery-{$attempt}",
            );
            $res = $consumer->consume($captureRecord, $mapper);
            self::assertSame(AccountingMappingStatus::Mapped, $res->status);
            self::assertNotNull($res->fact);
            $captureFactRefs[] = $res->fact->sourceFactRef;
        }

        // Exact same sourceFactRef regardless of delivery attempt or aggregate revision.
        self::assertCount(1, array_unique($captureFactRefs));
        $captureFactRef = $captureFactRefs[0];

        // Feed real outbox rows from PostgreSQL (invoice.issued.v1, allocation.applied.v1 x2, allocation reversal, credit_note.issued.v1) twice (replay idempotency).
        $outboxRows = $this->table('SELECT event_id, type, schema_version, aggregate_ref, aggregate_revision, payload, occurred_at FROM billium_outbox_event ORDER BY id');
        self::assertNotEmpty($outboxRows);
        for ($pass = 1; $pass <= 2; $pass++) {
            foreach ($outboxRows as $row) {
                /** @var array<string, mixed> $payload */
                $payload = json_decode(self::text($row['payload']), true, 64, JSON_THROW_ON_ERROR);
                $record = new AccountingSourceRecord(
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
                );
                $consumer->consume($record, $mapper);
            }
        }

        // Also map write-off fact from the uncollectible invoice remainder (20.00 RUB).
        $writeOffRes = $consumer->consume(new AccountingSourceRecord(
            'accounting.source.v1', 'write_off.recorded.v1', self::INSTALLATION, 'seller-a', 'test', 'ledger:core', 'inv-1', 5, $at,
            ['write_off_ref' => 'wo-inv-1', 'invoice_ref' => 'inv-1', 'amount_minor' => 2000, 'currency' => 'RUB', 'reason' => 'bad_debt'],
            deliveryAttemptRef: 'wo-delivery-1',
        ), $mapper);
        self::assertSame(AccountingMappingStatus::Mapped, $writeOffRes->status);

        // One capture with 2 allocations + 1 reversal => strictly ONE CashIn fact (10000), separate NoncashAllocation facts!
        self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::CashIn));
        self::assertSame(10000, $consumer->totalMinorByCategory(AccountingFactCategory::CashIn, 'RUB'));
        self::assertSame(2, $consumer->appliedCount(AccountingFactCategory::NoncashAllocation, AccountingFactKind::AllocationApplied));
        self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::NoncashAllocation, AccountingFactKind::AllocationReversed));
        self::assertSame(10000, $consumer->totalMinorByKind(AccountingFactKind::AllocationApplied, 'RUB'));
        self::assertSame(4000, $consumer->totalMinorByKind(AccountingFactKind::AllocationReversed, 'RUB'));

        // Receivables: 1 invoice (10000), 1 credit note (2000) linked to invoice, 1 write-off (2000) linked to invoice.
        self::assertSame(3, $consumer->appliedCount(AccountingFactCategory::Receivable));
        $invoiceEnv = $consumer->appliedEnvelopes(AccountingFactCategory::Receivable, AccountingFactKind::InvoiceIssued)[0];
        $creditNoteEnv = $consumer->appliedEnvelopes(AccountingFactCategory::Receivable, AccountingFactKind::CreditNoteIssued)[0];
        $writeOffEnv = $consumer->appliedEnvelopes(AccountingFactCategory::Receivable, AccountingFactKind::WriteOffRecorded)[0];
        self::assertSame($invoiceEnv->sourceFactRef, $creditNoteEnv->originalFactRef);
        self::assertSame($invoiceEnv->sourceFactRef, $writeOffEnv->originalFactRef);
        self::assertNotSame($invoiceEnv->sourceFactRef, $creditNoteEnv->sourceFactRef);

        // Allocation reversal links to original alloc-2 fact.
        $reversalEnv = $consumer->appliedEnvelopes(AccountingFactCategory::NoncashAllocation, AccountingFactKind::AllocationReversed)[0];
        $allocAppliedRefs = array_map(
            static fn (FinBridgeExportEnvelope $e): string => $e->sourceFactRef,
            $consumer->appliedEnvelopes(AccountingFactCategory::NoncashAllocation, AccountingFactKind::AllocationApplied),
        );
        self::assertNotNull($reversalEnv->originalFactRef);
        self::assertContains($reversalEnv->originalFactRef, $allocAppliedRefs);

        // Separate delivery ACKs vs applied receipts: 4 delivery ACKs for capture, strictly 1 applied receipt!
        self::assertSame(4, $consumer->deliveryAckCount($captureFactRef));
        self::assertNotNull($consumer->receiptFor($captureFactRef));
        self::assertFalse($consumer->receiptFor($captureFactRef)->replayed);
        $replayedReceipt = $consumer->apply($consumer->appliedEnvelopes(AccountingFactCategory::CashIn)[0]);
        self::assertTrue($replayedReceipt->replayed);
        self::assertSame($consumer->receiptFor($captureFactRef)->receiptRef, $replayedReceipt->receiptRef);
        self::assertSame(0, $consumer->glWriteCount());
    }

    public function test_consumer_backpressure_and_missing_consumer_never_block_ledger_commits(): void
    {
        $app = $this->application();
        self::assertFalse($app->bound(AccountingConsumer::class));

        $ledger = $app->make(Ledger::class);
        $mapper = $app->make(AccountingMapper::class);
        $consumer = new FakeFinBridgeConsumer;
        $extension = new AccountingExportExtension;
        $account = new BillingAccountScope('seller-a', 'test', 'account-bp');

        // Put consumer into outage/backpressure BEFORE committing financial operations.
        $consumer->setBackpressure(true, 'finbridge_outage');

        $ledger->recordObligation(new RecordObligation('bp-ob-1', $account, 'bp-ob-1', 'basis-bp', 'cust-bp', 'comp-bp', new Money(5000, Currency::of('RUB')), 'sha256:'.str_repeat('c', 64)))->orThrow();
        $ledger->createInvoice(new CreateInvoice('bp-inv-create', $account, 'bp-inv-1', 'cust-bp', [new InvoiceLineInput('bp-ob-1')]))->orThrow();
        $issued = $ledger->issueInvoice(new IssueInvoice('bp-inv-issue', $account, 'bp-inv-1'))->orThrow();
        self::assertTrue($issued->accepted);

        // Core ledger state and outbox row committed cleanly despite consumer outage.
        self::assertSame(1, $this->rows('billium_invoice', "invoice_ref='bp-inv-1' AND status='issued'"));
        self::assertSame(1, $this->rows('billium_outbox_event', "type='invoice.issued.v1' AND aggregate_ref='bp-inv-1'"));

        $row = $this->row("SELECT event_id, type, schema_version, aggregate_ref, aggregate_revision, payload, occurred_at FROM billium_outbox_event WHERE type='invoice.issued.v1' AND aggregate_ref='bp-inv-1'");
        /** @var array<string, mixed> $payload */
        $payload = json_decode(self::text($row['payload']), true, 64, JSON_THROW_ON_ERROR);
        $source = new AccountingSourceRecord(
            self::text($row['schema_version']),
            self::text($row['type']),
            self::INSTALLATION,
            'seller-a',
            'test',
            'ledger:core',
            self::text($row['aggregate_ref']),
            self::integer($row['aggregate_revision']),
            new DateTimeImmutable(self::text($row['occurred_at'])),
            $payload,
            deliveryAttemptRef: 'bp-attempt-1',
        );

        // Direct consumer call throws under backpressure; extension catches and defers in extension-owned state.
        try {
            $consumer->consume($source, $mapper);
            self::fail('Expected RuntimeException during consumer backpressure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('finbridge_outage', $e->getMessage());
        }
        self::assertFalse($extension->export($source, $mapper, $consumer));
        self::assertSame(1, $extension->deferredCount());
        self::assertSame(0, $consumer->appliedCount());
        self::assertGreaterThanOrEqual(2, $consumer->rejectedDeliveryCount());

        // Core ledger remains unaffected while consumer recovers; draining deferred delivers exactly once.
        self::assertSame(1, $this->rows('billium_invoice', "invoice_ref='bp-inv-1' AND status='issued'"));
        $consumer->setBackpressure(false);
        self::assertSame(1, $extension->drainDeferred($mapper, $consumer));
        self::assertSame(0, $extension->deferredCount());
        self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::Receivable));
        self::assertSame(5000, $consumer->totalMinorByCategory(AccountingFactCategory::Receivable, 'RUB'));
    }

    public function test_unknown_schema_is_quarantined_and_recovered_on_upgrade_without_duplicates(): void
    {
        $mapper = $this->application()->make(AccountingMapper::class);
        $consumer = new FakeFinBridgeConsumer;
        $at = new DateTimeImmutable('2026-11-19T13:00:00Z');

        $futureRecord = new AccountingSourceRecord(
            schemaVersion: 'accounting.source.v99',
            eventType: 'payment.captured.v1',
            installationRef: self::INSTALLATION,
            sellerRef: 'seller-a',
            environment: 'test',
            sourceLineage: 'execution:remote-billium',
            aggregateRef: 'future-cap-1',
            aggregateRevision: 1,
            occurredAt: $at,
            payload: [
                'status' => 'succeeded',
                'providerAccountRef' => 'merchant-a',
                'providerEffectRef' => 'prov-future-1',
                'effectMoney' => ['amount' => '12.50', 'currency' => 'RUB'],
            ],
            deliveryAttemptRef: 'future-attempt-1',
        );

        $first = $consumer->consume($futureRecord, $mapper);
        self::assertSame(AccountingMappingStatus::QuarantinedUnsupportedSchema, $first->status);
        self::assertNotNull($first->quarantined);
        self::assertNull($first->fact);
        self::assertCount(1, $consumer->quarantined());
        self::assertSame(0, $consumer->appliedCount());

        // Replaying the same unsupported record preserves quarantine without creating a second quarantine row or dropping it.
        $second = $consumer->consume($futureRecord, $mapper, 'future-attempt-2');
        self::assertSame(AccountingMappingStatus::QuarantinedUnsupportedSchema, $second->status);
        self::assertSame($first->quarantined->quarantineRef, $second->quarantined?->quarantineRef);
        self::assertCount(1, $consumer->quarantined());

        // After schema upgrade registers accounting.source.v99, replay resolves quarantine cleanly with zero duplicates.
        $upgradedMapper = $mapper->withAdditionalInputSchemas(['accounting.source.v99']);
        self::assertTrue($upgradedMapper->supportsInputSchema('accounting.source.v99'));
        self::assertFalse($mapper->supportsInputSchema('accounting.source.v99'));

        $recovered = $consumer->replayQuarantined($upgradedMapper);
        self::assertCount(1, $recovered);
        self::assertSame(AccountingMappingStatus::Mapped, $recovered[0]->status);
        self::assertCount(0, $consumer->quarantined());
        self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::CashIn));
        self::assertSame(1250, $consumer->totalMinorByCategory(AccountingFactCategory::CashIn, 'RUB'));

        // Replaying the record again after recovery produces zero duplicate applied effects.
        $replayed = $consumer->consume($futureRecord, $upgradedMapper, 'future-attempt-3');
        self::assertSame(AccountingMappingStatus::Mapped, $replayed->status);
        self::assertSame(1, $consumer->appliedCount(AccountingFactCategory::CashIn));
        self::assertSame(1250, $consumer->totalMinorByCategory(AccountingFactCategory::CashIn, 'RUB'));
        self::assertSame(2, $consumer->deliveryAckCount($replayed->fact?->sourceFactRef));
    }

    public function test_non_confirmed_zero_or_ambiguous_inputs_never_invent_cash_facts_and_cov299_extension_negotiates(): void
    {
        $mapper = $this->application()->make(AccountingMapper::class);
        $consumer = new FakeFinBridgeConsumer;
        $at = new DateTimeImmutable('2026-11-19T14:00:00Z');

        $ignoredInputs = [
            // Unknown refund status
            new AccountingSourceRecord('accounting.source.v1', 'refund.succeeded.v1', self::INSTALLATION, 'seller-a', 'test', 'execution:remote-billium', 'r-unk', 1, $at, [
                'status' => 'accepted-unknown', 'providerAccountRef' => 'merchant-a', 'providerEffectRef' => 'prov-unk', 'effectMoney' => ['amount' => '1.00', 'currency' => 'RUB'],
            ]),
            // Failed-terminal outcome
            new AccountingSourceRecord('billium.event.v2', 'payment.effect.outcome.v1', self::INSTALLATION, 'seller-a', 'test', 'execution:remote-billium', 'c-fail', 1, $at, [
                'operationKind' => 'capture', 'status' => 'failed-terminal', 'merchantAccountRef' => 'merchant-a', 'providerEffectRef' => 'prov-fail', 'money' => ['amount' => '1.00', 'currency' => 'RUB'], 'effectTotal' => '0.00',
            ]),
            // Void authorization (non-cash)
            new AccountingSourceRecord('billium.event.v2', 'payment.effect.outcome.v1', self::INSTALLATION, 'seller-a', 'test', 'execution:remote-billium', 'v-1', 1, $at, [
                'operationKind' => 'void_authorization', 'status' => 'succeeded', 'merchantAccountRef' => 'merchant-a', 'providerEffectRef' => 'prov-void', 'money' => ['amount' => '1.00', 'currency' => 'RUB'], 'effectTotal' => '1.00',
            ]),
            // Zero amount
            new AccountingSourceRecord('accounting.source.v1', 'payment.captured.v1', self::INSTALLATION, 'seller-a', 'test', 'execution:remote-billium', 'c-zero', 1, $at, [
                'status' => 'succeeded', 'providerAccountRef' => 'merchant-a', 'providerEffectRef' => 'prov-zero', 'effectMoney' => ['amount' => '0.00', 'currency' => 'RUB'],
            ]),
            // Missing authoritative providerEffectRef
            new AccountingSourceRecord('accounting.source.v1', 'refund.succeeded.v1', self::INSTALLATION, 'seller-a', 'test', 'execution:remote-billium', 'r-noid', 1, $at, [
                'status' => 'succeeded', 'providerAccountRef' => 'merchant-a', 'effectMoney' => ['amount' => '0.70', 'currency' => 'RUB'],
            ]),
            // Ambiguous aggregate evidence / bounded refundedTotal derivation
            new AccountingSourceRecord('accounting.source.v1', 'refund.succeeded.v1', self::INSTALLATION, 'seller-a', 'test', 'execution:remote-billium', 'r-agg', 1, $at, [
                'status' => 'succeeded', 'providerAccountRef' => 'merchant-a', 'providerEffectRef' => 'prov-agg', 'ambiguous' => true, 'refundedTotal' => ['amount' => '1.40', 'currency' => 'RUB'],
            ]),
            new AccountingSourceRecord('accounting.source.v1', 'refund.succeeded.v1', self::INSTALLATION, 'seller-a', 'test', 'execution:remote-billium', 'r-bounded', 1, $at, [
                'status' => 'succeeded', 'providerAccountRef' => 'merchant-a', 'providerEffectRef' => 'prov-bounded', 'derivedFrom' => 'bounded_refunded_total', 'effectMoney' => ['amount' => '0.70', 'currency' => 'RUB'],
            ]),
            // Unbounded / held receivable projection
            new AccountingSourceRecord('accounting.source.v1', 'invoice.issued.v1', self::INSTALLATION, 'seller-a', 'test', 'ledger:core', 'inv-held', 1, $at, [
                'invoice_ref' => 'inv-held', 'total_minor' => 500, 'currency' => 'RUB', 'projectionState' => 'held_inconsistent',
            ]),
        ];

        foreach ($ignoredInputs as $input) {
            $result = $consumer->consume($input, $mapper);
            self::assertSame(AccountingMappingStatus::IgnoredNonMonetary, $result->status, "Expected ignored for {$input->aggregateRef}");
        }
        self::assertSame(0, $consumer->appliedCount());
        self::assertSame(0, $consumer->deliveryAckCount());

        // Minimal accounting export extension capability/version negotiation without touching core financial migrations/ownership.
        $extension = new AccountingExportExtension;
        $plugin = new PlatformPlugin;
        $migrationsBefore = $this->rows('public.migrations');
        $coreTablesBefore = $plugin->tableNouns();

        $ok = $extension->negotiate($plugin->providedCapabilities(), FinBridgeExportEnvelope::SCHEMA_V1);
        self::assertTrue($ok['negotiated']);
        self::assertSame(FinBridgeExportEnvelope::SCHEMA_V1, $extension->requireNegotiated($plugin->providedCapabilities()));
        self::assertFalse($extension->altersCoreMigrations());
        self::assertSame([], $extension->coreFinancialTablesWritten());

        $missingCap = $extension->negotiate([], FinBridgeExportEnvelope::SCHEMA_V1);
        self::assertFalse($missingCap['negotiated']);
        self::assertSame([AccountingMapper::class], $missingCap['missingCapabilities']);

        try {
            $extension->requireNegotiated($plugin->providedCapabilities(), 'finbridge.fact.export.v99');
            self::fail('Expected ProblemException for unsupported FinBridge export contract version');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::SchemaUnsupported, $e->problem);
        }

        // Core migrations and platform table ownership remain completely unchanged.
        self::assertSame($migrationsBefore, $this->rows('public.migrations'));
        self::assertSame($coreTablesBefore, $plugin->tableNouns());
    }
}
