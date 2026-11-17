<?php

declare(strict_types=1);

namespace Billium\Execution\Tests\Feature;

use Billium\Contracts\Data\Currency;
use Billium\Contracts\Data\Money;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runtime\FinancialScope;
use Billium\Core\Tests\ExecutionTestCase;
use Billium\Execution\Contracts\AttemptWorker;
use Billium\Execution\Contracts\EvidenceReducer;
use Billium\Execution\Contracts\RefundCommands;
use Billium\Execution\Contracts\RootPaymentCommands;
use Billium\Execution\Enums\ReductionDisposition;
use Billium\Execution\Tests\Concurrency\AdmissionIdentityTest as Fixture;
use Billium\Execution\Tests\Concurrency\OutcomeDeliveryTest;
use Billium\Execution\Tests\Concurrency\RefundCapacityTest;
use Billium\Execution\Tests\Drill\UnknownResultTest;
use Billium\Ledger\Contracts\Ledger;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Credits\RecordCustomerCredit;
use Billium\Ledger\Data\Invoices\CreateInvoice;
use Billium\Ledger\Data\Invoices\CreditNoteLine;
use Billium\Ledger\Data\Invoices\CreditNoteMode;
use Billium\Ledger\Data\Invoices\InvoiceLineInput;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Billium\Ledger\Data\Invoices\IssueInvoice;
use Billium\Ledger\Data\Obligations\RecordObligation;
use Billium\Ledger\Data\Runtime\BillingAccountScope;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Testkit\Drivers\ProviderRecord;
use Billium\Testkit\Fixtures\DurableJournal;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;

#[Group('pg')]
#[Group('COV-172')]
#[Group('COV-175')]
#[Group('COV-176')]
final class ExecutionValueTraceTest extends ExecutionTestCase
{
    public function test_provider_payment_allocation_credit_note_refund_and_customer_credit_reconcile_separately(): void
    {
        $path = Fixture::tempPath();
        try {
            $app = $this->application();
            $account = new BillingAccountScope('seller-a', 'test', 'billing-a');
            $ledger = $app->make(Ledger::class);
            $this->issueInvoiceAndDeclareSettlement($account, $ledger);
            $ledger->recordCustomerCredit(new RecordCustomerCredit('cash-credit', $account, 'cash-credit',
                'independent-cash-source', self::money(700)))->orThrow();
            Fixture::seedMerchant($app);
            Fixture::host($app, $path);

            $scope = Fixture::scope();
            $app->make(RootPaymentCommands::class)->admit($scope, Fixture::wire(['money' => self::money(10000)->toWire()]))->orThrow();
            $this->dispatchAndDeliver($path, 'root-a', 1);
            self::assertSame([10000, 0, 6000, 4000], $this->paymentAmounts());
            self::assertSame(1, $this->rows('billium_allocation', 'amount_minor=6000'));
            self::assertSame(1, $this->rows('billium_invoice', "invoice_ref='invoice-a' AND total_minor=10000 AND allocated_minor=6000 AND credited_minor=0 AND open_minor=4000"));

            $note = $ledger->issueCreditNote(new IssueCreditNote('credit-note', $account, 'credit-note-a', 'invoice-a',
                CreditNoteMode::OpenOnly, [new CreditNoteLine('adjustable', self::money(2000))], 'goodwill'))->orThrow();
            self::assertSame('ledger.credit_note', $note->receiptClass);
            self::assertSame(1, $this->rows('billium_credit_note', "credit_note_ref='credit-note-a' AND amount_minor=2000"));
            self::assertSame([10000, 0, 6000, 4000], $this->paymentAmounts());
            self::assertSame(1, UnknownResultTest::events($path, 'provider.call'));

            $refund = RefundCapacityTest::refund('refund-a', 1000, 2);
            $app->make(RefundCommands::class)->admit($scope, $refund)->orThrow();
            self::assertSame(1, $this->rows('billium_execution_reservation', "kind='refund' AND state='held' AND requested_minor=1000"));
            $this->dispatchAndDeliver($path, 'refund-a', 2);

            self::assertSame([10000, 1000, 6000, 3000], $this->paymentAmounts());
            self::assertSame(1, $this->rows('billium_invoice', "invoice_ref='invoice-a' AND total_minor=10000 AND allocated_minor=6000 AND credited_minor=2000 AND open_minor=2000"));
            self::assertSame(1, $this->rows('billium_obligation', "obligation_ref='payable' AND allocated_minor=6000 AND credited_minor=0 AND open_minor=0"));
            self::assertSame(1, $this->rows('billium_obligation', "obligation_ref='adjustable' AND allocated_minor=0 AND credited_minor=2000 AND open_minor=2000"));
            self::assertSame(1, $this->rows('billium_allocation'));
            self::assertSame(1, $this->rows('billium_credit_note'));
            self::assertSame(1, $this->rows('billium_allocation_receipt', "kind='refund_detail'"));

            $effects = array_values(array_filter(new DurableJournal($path)->records(), static fn (object $row): bool => $row instanceof ProviderRecord));
            self::assertSame([10000, -1000], array_map(static fn (ProviderRecord $row): int => ($row->effectAmounts['captured'] ?? 0)
                - ($row->effectAmounts['refunded'] ?? 0), $effects));
            self::assertSame(2, UnknownResultTest::events($path, 'provider.call'));
            self::assertSame(0, UnknownResultTest::events($path, 'provider.lookup'));

            self::assertSame(1, $this->rows('billium_customer_credit', 'remaining_minor=700'));
            $ledger->allocate(new Allocate('spend-cash-credit', $account, 'credit-spend', 'separate', self::money(300), customerCreditRef: 'cash-credit'))->orThrow();
            self::assertSame(1, $this->rows('billium_customer_credit', 'remaining_minor=400'));
            self::assertSame(2, $this->rows('billium_allocation'));
            self::assertSame([10000, 1000, 6000, 3000], $this->paymentAmounts());
        } finally {
            UnknownResultTest::clean($path);
            if (is_file($path.'.origins')) {
                unlink($path.'.origins');
            }
        }
    }

    private function issueInvoiceAndDeclareSettlement(BillingAccountScope $account, Ledger $ledger): void
    {
        $participants = $this->application()->make(LedgerParticipants::class);
        $scope = Fixture::scope();
        $selected = new FinancialScope($scope->installationRef, $scope->sellerRef, $scope->environment, $scope->intentOwnerNamespace, 'remote-billium');
        $spec = $this->spec(financialScope: $selected);
        foreach (['payable' => 6000, 'adjustable' => 4000, 'separate' => 300] as $ref => $due) {
            $this->runner()->run($spec, static fn (TransactionContext $ctx) => $participants->recordObligation($ctx,
                new RecordObligation($ref, $account, $ref, 'basis-'.$ref, 'buyer', 'component', self::money($due), 'sha256:'.str_repeat('f', 64))))->orThrow();
        }
        $ledger->createInvoice(new CreateInvoice('invoice-draft', $account, 'invoice-a', 'buyer', [
            new InvoiceLineInput('payable', 0, ['name' => 'payable']),
            new InvoiceLineInput('adjustable', 1, ['name' => 'adjustable']),
        ], ['buyer' => 'Buyer']))->orThrow();
        $ledger->issueInvoice(new IssueInvoice('invoice-issue', $account, 'invoice-a'))->orThrow();
        $this->runner()->run($spec, static fn (TransactionContext $ctx) => $participants->declareSettlementIntent($ctx,
            new DeclareSettlementIntent('settlement', $account, 'intent-a', 'RUB', ['payable'])))->orThrow();
    }

    private function dispatchAndDeliver(string $path, string $operation, int $observationCount): void
    {
        $app = $this->application();
        $app->make(AttemptWorker::class)->dispatch(Fixture::scope(), RefundCapacityTest::attemptRef($operation), 'sender', new DateTimeImmutable('+1 minute'))->orThrow();
        $work = self::text($this->value("SELECT work_ref FROM billium_execution_provider_work WHERE operation_hint=? AND state='due'", [$operation]));
        $result = $app->make(EvidenceReducer::class)->reduce($work);
        self::assertSame(ReductionDisposition::Applied, $result->disposition);
        self::assertCount($observationCount, $result->observationCommandRefs);
        foreach ($result->observationCommandRefs as $ref) {
            OutcomeDeliveryTest::attest($app, $path, $ref);
        }
        self::assertSame($observationCount, OutcomeDeliveryTest::relay($app, $path)->delivered);
        foreach ($result->observationCommandRefs as $ref) {
            OutcomeDeliveryTest::duplicateDelivery($app, $ref);
        }
    }

    /** @return list<int> */
    private function paymentAmounts(): array
    {
        $view = $this->application()->make(LedgerQueries::class)->paymentStatus('root-a', 'remote-billium');
        $funds = self::arr($view['applied']);

        return array_map(static fn (string $field): int => Money::fromWire($funds[$field])->minor,
            ['capturedTotal', 'refundedTotal', 'allocated', 'unallocated']);
    }

    private static function money(int $minor): Money
    {
        return new Money($minor, Currency::of('RUB'));
    }
}
