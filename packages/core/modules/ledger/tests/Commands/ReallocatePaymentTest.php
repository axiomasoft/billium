<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Commands;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Data\ReallocatePayment;
use Billium\Ledger\Data\ReallocationMode;
use Billium\Ledger\Tests\Schema\LedgerTestCase;

/** allocation.reallocate.v1 (D35, INV-09): LIFO reversal with cause = refund, credit_note vs reversal policy, replay. */
final class ReallocatePaymentTest extends LedgerTestCase
{
    use AllocationFixtures;
    use InvoiceFixtures;

    /** Invoice of o1 = 60.00 and o2 = 40.00 paid by one payment of 100.00 (a1 first, a2 last). */
    private function paidInvoice(): void
    {
        $this->obligation('o1', 6000);
        $this->obligation('o2', 4000);
        $this->draft(['o1' => 0, 'o2' => 0]);
        $this->issue();
        $this->payWith(captured: 10000);
        $this->allocateFrom('a1', 'o1', '60.00');
        $this->allocateFrom('a2', 'o2', '40.00');
        self::assertSame('paid', $this->invoiceStatus());
    }

    public function test_credit_note_mode_reverses_last_first_places_the_overshoot_and_closes_the_debt(): void
    {
        $this->paidInvoice();
        // New spend is blocked between the confirmed refund and the reallocation.
        $this->refunded('payment-a', 3000);
        self::assertTrue($this->paymentState()['flag']);
        $outcome = $this->reallocate(ReallocationMode::CreditNote);
        self::assertTrue($outcome->accepted, json_encode($outcome->result(), JSON_THROW_ON_ERROR));
        self::assertSame(['reallocation:payment-a@3000', '3000', 'refund'], [$outcome->body['receipt_ref'] ?? null, $outcome->body['excess_minor'] ?? null, $outcome->body['cause'] ?? null]);
        self::assertSame([[6000, 'manual'], [4000, 'manual'], [-4000, 'refund'], [1000, 'refund']],
            array_map(static fn (array $r): array => [self::integer($r['amount_minor']), $r['cause']], $this->table('SELECT amount_minor,cause FROM billium_allocation ORDER BY id')));
        self::assertSame(['allocated' => 6000, 'credited' => 0, 'open' => 0, 'status' => 'settled'], $this->obligationState('o1'));
        self::assertSame(['allocated' => 1000, 'credited' => 3000, 'open' => 0, 'status' => 'settled'], $this->obligationState('o2'));
        self::assertSame(['allocated' => 7000, 'flag' => false], $this->paymentState());
        self::assertSame([['reallocation:payment-a@3000#1', 3000, 'refund', 'open_only']],
            array_map(static fn (array $r): array => [$r['credit_note_ref'], self::integer($r['amount_minor']), $r['reason'], $r['mode']], $this->table('SELECT * FROM billium_credit_note')));
        self::assertSame('paid', $this->invoiceStatus());
        self::assertSame(1, $this->rows('billium_outbox_event', "type='credit_note.issued.v1' AND aggregate_ref='payment-a'"));
        self::assertSame(3, $this->rows('billium_outbox_event', "type='allocation.applied.v1'"));

        // Lost reply: another key replays the same receipt; nothing more to reallocate afterwards.
        self::assertSame($outcome->body, $this->reallocate(ReallocationMode::CreditNote, key: 'realloc-again')->body);
        self::assertSame(4, $this->rows('billium_allocation'));
        try {
            $this->reallocate(ReallocationMode::Reversal, key: 'realloc-other-mode');
            self::fail('the same refund state with another policy mode conflicts');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
        }
    }

    public function test_reversal_mode_reopens_the_receivable_without_a_credit_note(): void
    {
        $this->paidInvoice();
        $this->refunded('payment-a', 5000);
        $outcome = $this->reallocate(ReallocationMode::Reversal);
        self::assertTrue($outcome->accepted);
        // a2 (40.00) and a1 (60.00) reversed in full, 50.00 of a1 placed again.
        self::assertSame([6000, 4000, -4000, -6000, 5000], array_map(static fn (array $r): int => self::integer($r['amount_minor']), $this->table('SELECT amount_minor FROM billium_allocation ORDER BY id')));
        self::assertSame(['allocated' => 5000, 'credited' => 0, 'open' => 1000, 'status' => 'open'], $this->obligationState('o1'));
        self::assertSame(['allocated' => 0, 'credited' => 0, 'open' => 4000, 'status' => 'open'], $this->obligationState('o2'));
        self::assertSame(['allocated' => 5000, 'flag' => false], $this->paymentState());
        self::assertSame(0, $this->rows('billium_credit_note'));
        self::assertSame('partially_paid', $this->invoiceStatus());
        self::assertSame(0, $this->rows('billium_outbox_event', "type='credit_note.issued.v1'"));
        self::assertSame([], self::arr($outcome->body['credit_notes'] ?? null));
    }

    public function test_reallocation_refusals(): void
    {
        $this->paidInvoice();
        try {
            $this->reallocate(ReallocationMode::Reversal);
            self::fail('nothing to reallocate before a refund');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }
        $this->refunded('payment-a', 1000);
        self::assertSame(ProblemCode::PolicyUnknown, $this->reallocate(ReallocationMode::Reversal, key: 'k-policy', policy: 'pro_rata_open/1')->problem);
        try {
            $this->reallocate(ReallocationMode::Reversal, 'missing', 'k-missing');
            self::fail('unknown payment');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ReferenceUnknown, $e->problem);
        }
        try {
            $this->ledger()->reallocatePayment(new ReallocatePayment('k-inst', 'other-installation', 'seller-a', 'test', 'payment-a', 'refund/1', ReallocationMode::Reversal));
            self::fail('foreign installation');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::AuthDenied, $e->problem);
        }
        self::assertSame(2, $this->rows('billium_allocation'));
        self::assertTrue($this->paymentState()['flag']);
    }

    public function test_the_wire_command_decodes_through_the_codec(): void
    {
        $this->paidInvoice();
        $this->refunded('payment-a', 2000);
        $json = json_encode(['schema' => 'allocation.reallocate.v1', 'commandKey' => '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d', 'installationRef' => 'inst-test',
            'environment' => 'test', 'sellerRef' => 'seller-a', 'paymentRef' => 'payment-a', 'policyVersion' => 'refund/1', 'mode' => 'credit_note'], JSON_THROW_ON_ERROR);
        $command = ReallocatePayment::fromWire($json);
        self::assertSame([ReallocationMode::CreditNote, 'payment-a', 'refund/1'], [$command->mode, $command->paymentRef, $command->policyVersion]);
        self::assertTrue($this->ledger()->reallocatePayment($command)->accepted);
        self::assertSame(2000, $this->obligationState('o2')['credited']);
        foreach ([
            'unknown mode' => str_replace('credit_note', 'refund_all', $json),
            'unknown field' => substr($json, 0, -1).',"amount":"1"}',
            'another schema' => str_replace('allocation.reallocate.v1', 'payment.find.v1', $json),
        ] as $case => $bad) {
            try {
                ReallocatePayment::fromWire($bad);
                self::fail($case);
            } catch (ProblemException $e) {
                self::assertContains($e->problem, [ProblemCode::ValidationInvalid, ProblemCode::SchemaUnsupported], $case);
            }
        }
    }
}
