<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Commands;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Core\Contracts\Runner\TransactionContext;
use Billium\Core\Data\Runner\Receipt;
use Billium\Ledger\Contracts\LedgerParticipants;
use Billium\Ledger\Data\Invoices\CreditNoteLine;
use Billium\Ledger\Data\Invoices\CreditNoteMode;
use Billium\Ledger\Data\Invoices\DiscardInvoice;
use Billium\Ledger\Data\Invoices\IssueCreditNote;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use PHPUnit\Framework\Attributes\Group;

/** issueCreditNote (174): open_only, reverse_allocations with LIFO release, refusals, replay. */
#[Group('pg')]
final class CreditNoteCommandsTest extends LedgerTestCase
{
    use AllocationFixtures;
    use InvoiceFixtures;

    private function issued(int $amount = 10000): void
    {
        $this->obligation('obligation-a', $amount);
        $this->draft();
        $this->issue();
    }

    public function test_open_only_credit_reduces_open_freezes_the_invoice_and_replays(): void
    {
        $this->issued();
        $snapshot = $this->invoiceRow()['snapshot'];
        $note = $this->creditNote('cn-1', ['obligation-a' => '20.00']);
        self::assertTrue($note->accepted);
        self::assertSame('ledger.credit_note', $note->receiptClass);
        self::assertSame(['credit-note:cn-1', 'open_only', 'invoice-a'], [$note->body['receipt_ref'] ?? null, $note->body['mode'] ?? null, $note->body['invoice_ref'] ?? null]);
        self::assertSame(['allocated' => 0, 'credited' => 2000, 'open' => 8000, 'status' => 'open'], $this->obligationState('obligation-a'));
        $invoice = $this->invoiceRow();
        self::assertSame(['partially_paid', 2000, 10000, 8000], [$invoice['status'], self::integer($invoice['credited_minor']), self::integer($invoice['total_minor']), self::integer($invoice['open_minor'])]);
        self::assertSame($snapshot, $invoice['snapshot'], 'the issued document is never rewritten');
        self::assertSame(['cn-1', 'goodwill', 'open_only', null], array_values($this->row('SELECT credit_note_ref,reason,mode,legal_document_ref FROM billium_credit_note')));
        self::assertSame(1, $this->rows('billium_outbox_event', "type='credit_note.issued.v1' AND aggregate_ref='invoice-a'"));

        self::assertSame($note->body, $this->creditNote('cn-1', ['obligation-a' => '20.00'], key: 'another-key')->body);
        try {
            $this->creditNote('cn-1', ['obligation-a' => '25.00'], key: 'third-key');
            self::fail('changed credit note');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
        }
        self::assertSame(1, $this->rows('billium_credit_note'));
        self::assertSame(1, $this->rows('billium_outbox_event', "type='credit_note.issued.v1'"));

        // The rest of the receivable can be credited, but never beyond it.
        self::assertSame(ProblemCode::AllocationExceedsOpen, $this->creditNote('cn-2', ['obligation-a' => '80.01'])->problem);
        self::assertTrue($this->creditNote('cn-3', ['obligation-a' => '80.00'])->accepted);
        self::assertSame('paid', $this->invoiceStatus());
        self::assertSame('settled', $this->obligationState('obligation-a')['status']);
    }

    public function test_open_only_cannot_take_allocated_value_but_reverse_allocations_releases_it_last_first(): void
    {
        $this->issued();
        $this->payWith();
        $this->allocateFrom('a1', 'obligation-a', '60.00');
        $this->allocateFrom('a2', 'obligation-a', '40.00');
        self::assertSame('paid', $this->invoiceStatus());
        self::assertSame(ProblemCode::AllocationExceedsOpen, $this->creditNote('cn-open', ['obligation-a' => '50.00'])->problem);

        // Need 50.00: reverse a2 (40.00) and a1 (60.00) in full, place 50.00 of a1 again, then credit 50.00.
        $note = $this->creditNote('cn-rev', ['obligation-a' => '50.00'], CreditNoteMode::ReverseAllocations);
        self::assertTrue($note->accepted);
        $line = self::arr(self::arr($note->body['lines'] ?? null)[0] ?? null);
        self::assertSame('5000', $line['released_minor'] ?? null);
        self::assertSame([6000, 4000, -4000, -6000, 5000], array_map(static fn (array $r): int => self::integer($r['amount_minor']), $this->table('SELECT amount_minor FROM billium_allocation ORDER BY id')));
        self::assertSame(2, $this->rows('billium_allocation', 'reverses_id IS NOT NULL'));
        self::assertSame(['allocated' => 5000, 'credited' => 5000, 'open' => 0, 'status' => 'settled'], $this->obligationState('obligation-a'));
        self::assertSame(['allocated' => 5000, 'flag' => false], $this->paymentState(), 'released value is unallocated on the payment again');
        self::assertSame('paid', $this->invoiceStatus());

        // Even reverse_allocations credits at most due + adjusted − credited.
        self::assertSame(ProblemCode::AllocationExceedsOpen, $this->creditNote('cn-over', ['obligation-a' => '50.01'], CreditNoteMode::ReverseAllocations)->problem);
        self::assertSame(5, $this->rows('billium_allocation'));
    }

    public function test_credit_note_requires_an_issued_invoice_and_its_own_obligations(): void
    {
        $this->obligation('obligation-a', 1000);
        $this->draft();
        self::assertSame(ProblemCode::InvoiceTransitionInvalid, $this->creditNote('cn-draft', ['obligation-a' => '1.00'])->problem);
        $this->ledger()->discardInvoice(new DiscardInvoice('discard', $this->account(), 'invoice-a', 'cancelled'));
        $this->obligation('obligation-b', 1000);
        $this->draft(['obligation-b' => 0], 'invoice-b');
        $this->issue('invoice-b');
        try {
            $this->creditNote('cn-foreign', ['obligation-a' => '1.00'], invoice: 'invoice-b');
            self::fail('obligation of another invoice');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ReferenceUnknown, $e->problem);
        }
        foreach ([
            'empty lines' => new IssueCreditNote('k1', $this->account(), 'x1', 'invoice-b', CreditNoteMode::OpenOnly, [], 'r'),
            'duplicate line' => new IssueCreditNote('k2', $this->account(), 'x2', 'invoice-b', CreditNoteMode::OpenOnly, [new CreditNoteLine('obligation-b', Money::parse('1.00', 'RUB')), new CreditNoteLine('obligation-b', Money::parse('1.00', 'RUB'))], 'r'),
            'zero amount' => new IssueCreditNote('k3', $this->account(), 'x3', 'invoice-b', CreditNoteMode::OpenOnly, [new CreditNoteLine('obligation-b', Money::parse('0.00', 'RUB'))], 'r'),
            'line suffix in the ref' => new IssueCreditNote('k4', $this->account(), 'x#4', 'invoice-b', CreditNoteMode::OpenOnly, [new CreditNoteLine('obligation-b', Money::parse('1.00', 'RUB'))], 'r'),
        ] as $case => $command) {
            try {
                $this->ledger()->issueCreditNote($command);
                self::fail($case);
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::ValidationInvalid, $e->problem, $case);
            }
        }
        self::assertSame(0, $this->rows('billium_credit_note'));
    }

    public function test_multi_line_credit_note_and_the_payment_allocation_credit_refund_fixture(): void
    {
        $this->obligation('o1', 10000);
        $this->obligation('o2', 5000);
        $this->draft(['o1' => 0, 'o2' => 0]);
        $this->issue();
        // Payment 100, allocation 60, credit note 20, refund 10 are separate facts that reconcile.
        $this->payWith(captured: 10000);
        $this->allocateFrom('a1', 'o1', '60.00');
        $note = $this->creditNote('cn', ['o1' => '20.00', 'o2' => '10.00']);
        self::assertTrue($note->accepted);
        self::assertSame(['cn#1', 'cn#2'], array_column($this->table('SELECT credit_note_ref FROM billium_credit_note ORDER BY id'), 'credit_note_ref'));
        $this->refunded('payment-a', 1000);
        self::assertSame(['allocated' => 6000, 'credited' => 2000, 'open' => 2000, 'status' => 'open'], $this->obligationState('o1'));
        self::assertSame(['allocated' => 0, 'credited' => 1000, 'open' => 4000, 'status' => 'open'], $this->obligationState('o2'));
        $payment = $this->row('SELECT captured_minor - refunded_minor - allocated_minor AS unallocated, reallocation_required FROM billium_payment');
        self::assertSame([3000, false], [self::integer($payment['unallocated']), $payment['reallocation_required']]);
        $invoice = $this->invoiceRow();
        self::assertSame([15000, 6000, 3000, 6000, 'partially_paid'], [self::integer($invoice['total_minor']), self::integer($invoice['allocated_minor']), self::integer($invoice['credited_minor']), self::integer($invoice['open_minor']), $invoice['status']]);
    }

    public function test_the_participant_issues_in_the_callers_root_with_the_legal_reference_and_rolls_back_with_it(): void
    {
        $this->issued();
        $port = $this->application()->make(LedgerParticipants::class);
        $command = new IssueCreditNote('participant', $this->account(), 'cn-p', 'invoice-a', CreditNoteMode::OpenOnly, [new CreditNoteLine('obligation-a', Money::parse('10.00', 'RUB'))], 'late negative delta', 'LEGAL-CN-1');
        try {
            $this->runner()->run($this->spec(key: 'k-cn-fail'), function (TransactionContext $context) use ($port, $command): Receipt {
                $port->issueCreditNote($context, $command);
                throw new \RuntimeException('numbering failed');
            });
            self::fail('root fails');
        } catch (\RuntimeException $e) {
            self::assertSame('numbering failed', $e->getMessage());
        }
        self::assertSame(0, $this->rows('billium_credit_note'));
        self::assertSame(0, $this->obligationState('obligation-a')['credited']);
        $outcome = $this->runner()->run($this->spec(key: 'k-cn'), fn (TransactionContext $context): Receipt => $port->issueCreditNote($context, $command));
        self::assertTrue($outcome->accepted);
        self::assertSame('LEGAL-CN-1', $this->value('SELECT legal_document_ref FROM billium_credit_note'));
        self::assertSame(1000, $this->obligationState('obligation-a')['credited']);
    }

    public function test_a_credit_note_in_another_currency_than_its_invoice_is_refused_and_changes_nothing(): void
    {
        $this->obligation('obligation-a', 10000);
        $this->obligation('obligation-b', 1000);
        $this->draft(['obligation-a' => 0, 'obligation-b' => 0]);
        $this->issue();
        $foreign = [new CreditNoteLine('obligation-a', Money::parse('20.00', 'USD'))];
        $mixed = [new CreditNoteLine('obligation-a', Money::parse('20.00', 'RUB')), new CreditNoteLine('obligation-b', Money::parse('5.00', 'USD'))];
        foreach (['line currency differs from the invoice' => $foreign, 'lines in two currencies' => $mixed] as $case => $lines) {
            // Without the guard the routine books USD minor units as the invoice's RUB and freezes the document.
            self::assertSame(ProblemCode::MoneyCurrencyMismatch, $this->refused(new IssueCreditNote('k-'.$case, $this->account(), 'cn-fx', 'invoice-a', CreditNoteMode::OpenOnly, $lines, 'goodwill')), $case);
        }
        self::assertSame(0, $this->rows('billium_credit_note'));
        self::assertSame(['allocated' => 0, 'credited' => 0, 'open' => 10000, 'status' => 'open'], $this->obligationState('obligation-a'));
        self::assertSame([0, 11000], [self::integer($this->invoiceRow()['credited_minor']), self::integer($this->invoiceRow()['open_minor'])]);
        self::assertSame(0, $this->rows('billium_outbox_event', "type='credit_note.issued.v1'"));
    }

    private function refused(IssueCreditNote $command): ?ProblemCode
    {
        try {
            return $this->ledger()->issueCreditNote($command)->problem;
        } catch (ProblemException $e) {
            return $e->problem;
        }
    }
}
