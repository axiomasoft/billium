<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Commands;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Contracts\Exceptions\ProblemException;
use Billium\Ledger\Data\Allocations\Allocate;
use Billium\Ledger\Data\Allocations\ReverseAllocation;
use Billium\Ledger\Data\Settlements\DeclareSettlementIntent;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use PHPUnit\Framework\Attributes\Group;

/** allocate and reverseAllocation through the public root (INV-09, COV-166, COV-289): receipts, refusals, replay, events. */
#[Group('pg')]
final class AllocationCommandsTest extends LedgerTestCase
{
    use AllocationFixtures;
    use InvoiceFixtures;

    public function test_allocation_moves_counters_in_the_routine_emits_one_event_and_replays_by_identity(): void
    {
        $this->obligation('obligation-a', 10000);
        $this->draft();
        $this->issue();
        $this->payWith();
        $first = $this->allocateFrom('a1', 'obligation-a', '30.00');
        self::assertTrue($first->accepted);
        self::assertSame('ledger.allocation', $first->receiptClass);
        self::assertSame('allocation:a1', $first->body['receipt_ref'] ?? null);
        self::assertSame(['allocated' => 3000, 'credited' => 0, 'open' => 7000, 'status' => 'open'], $this->obligationState('obligation-a'));
        self::assertSame(['allocated' => 3000, 'flag' => false], $this->paymentState());
        self::assertSame('partially_paid', $this->invoiceStatus());
        self::assertSame(1, $this->rows('billium_outbox_event', "type='allocation.applied.v1'"));
        $payload = json_decode(self::text($this->value("SELECT payload FROM billium_outbox_event WHERE type='allocation.applied.v1'")), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(['3000', 'partially_paid', 'payment'], [$payload['amount_minor'] ?? null, $payload['invoice_status'] ?? null, $payload['source_kind'] ?? null]);

        // Same key → stored outcome; same identity under a new key → the same receipt, no second row (ACC-RST-05 shape).
        self::assertSame($first->body, $this->allocateFrom('a1', 'obligation-a', '30.00')->body);
        $twin = $this->allocateFrom('a1', 'obligation-a', '30.00', key: 'another-key');
        self::assertSame($first->body, $twin->body);
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertSame(1, $this->rows('billium_outbox_event', "type='allocation.applied.v1'"));
        try {
            $this->allocateFrom('a1', 'obligation-a', '31.00', key: 'changed-key');
            self::fail('changed payload under the same identity');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
        }

        // REQ-ACC-10: the remaining delta settles the invoice.
        self::assertTrue($this->allocateFrom('a2', 'obligation-a', '70.00')->accepted);
        self::assertSame('paid', $this->invoiceStatus());
        self::assertSame('settled', $this->obligationState('obligation-a')['status']);
        self::assertSame(0, $this->rows('billium_allocation_receipt', "kind='allocation' AND receipt_ref NOT IN ('allocation:a1','allocation:a2')"));
    }

    public function test_business_refusals_are_stored_and_change_nothing(): void
    {
        $this->obligation('o1', 8000);
        $this->obligation('o2', 8000);
        $this->payWith(captured: 10000);
        self::assertTrue($this->allocateFrom('a1', 'o1', '80.00')->accepted);
        $open = $this->allocateFrom('a2', 'o1', '0.01');
        self::assertSame(ProblemCode::AllocationExceedsOpen, $open->problem);
        $available = $this->allocateFrom('a3', 'o2', '20.01');
        self::assertSame(ProblemCode::AllocationExceedsAvailable, $available->problem);
        // The stored refusal replays under its key without a second evaluation.
        self::assertSame(ProblemCode::AllocationExceedsAvailable, $this->allocateFrom('a3', 'o2', '20.01')->problem);
        self::assertTrue($this->allocateFrom('a4', 'o2', '20.00')->accepted);

        // A confirmed refund makes the payment over-allocated: new spend is refused until reallocation (D35).
        $this->refunded('payment-a', 500);
        self::assertSame(['allocated' => 10000, 'flag' => true], $this->paymentState());
        $this->obligation('o3', 100);
        self::assertSame(ProblemCode::AllocationReallocationRequired, $this->allocateFrom('a5', 'o3', '0.01')->problem);
        // financial_hold blocks every new allocation the same way.
        $this->payWith('payment-held', 1000);
        $this->owner()->update("UPDATE billium_payment SET financial_hold=true,projection_status='held' WHERE payment_ref='payment-held'");
        self::assertSame(ProblemCode::AllocationReallocationRequired, $this->allocateFrom('a6', 'o3', '0.01', 'payment-held')->problem);
        self::assertSame(2, $this->rows('billium_allocation'));
        self::assertSame(2, $this->rows('billium_outbox_event', "type='allocation.applied.v1'"));
        self::assertSame(0, $this->obligationState('o3')['allocated']);
    }

    public function test_unknown_foreign_and_invalid_references_refuse_without_a_receipt(): void
    {
        $this->obligation('o1', 1000);
        $this->obligation('foreign', 1000, 'account-b');
        $this->payWith();
        $this->payWith('payment-b', 1000, 'account-b');
        foreach ([
            'unknown obligation' => ['missing', 'payment-a', ProblemCode::ReferenceUnknown],
            'unknown payment' => ['o1', 'missing', ProblemCode::ReferenceUnknown],
            'obligation of another account' => ['foreign', 'payment-a', ProblemCode::ReferenceUnknown],
            'payment of another account' => ['o1', 'payment-b', ProblemCode::ReferenceUnknown],
        ] as $case => [$obligation, $payment, $problem]) {
            try {
                $outcome = $this->allocateFrom('x-'.$case, $obligation, '1.00', $payment);
                self::fail($case.': '.json_encode($outcome->result()));
            } catch (ProblemException $e) {
                self::assertSame($problem, $e->problem, $case);
            }
        }
        foreach ([
            'zero' => fn (): Allocate => new Allocate('k0', $this->account(), 'z', 'o1', Money::parse('0.00', 'RUB'), 'payment-a'),
            'negative' => fn (): Allocate => new Allocate('k1', $this->account(), 'n', 'o1', Money::parse('-1.00', 'RUB'), 'payment-a'),
            'two sources' => fn (): Allocate => new Allocate('k2', $this->account(), 't', 'o1', Money::parse('1.00', 'RUB'), 'payment-a', 'credit-a'),
            'no source' => fn (): Allocate => new Allocate('k3', $this->account(), 's', 'o1', Money::parse('1.00', 'RUB')),
        ] as $case => $make) {
            try {
                $this->ledger()->allocate($make());
                self::fail($case);
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::ValidationInvalid, $e->problem, $case);
            }
        }
        self::assertSame(0, $this->rows('billium_allocation'));
        self::assertSame(0, $this->rows('billium_allocation_receipt', "kind='allocation'"));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind='ledger.allocate'"));
    }

    public function test_prepaid_customer_credit_worked_example(): void
    {
        $this->obligation('o1', 2000);
        $this->obligation('o2', 3000);
        $this->credit('credit-a', 5000);
        self::assertTrue($this->allocateFrom('p1', 'o1', '20.00', credit: 'credit-a')->accepted);
        self::assertTrue($this->allocateFrom('p2', 'o2', '30.00', credit: 'credit-a')->accepted);
        self::assertSame(0, self::integer($this->value("SELECT remaining_minor FROM billium_customer_credit WHERE credit_ref='credit-a'")));
        $this->obligation('o3', 100);
        self::assertSame(ProblemCode::AllocationExceedsAvailable, $this->allocateFrom('p3', 'o3', '0.01', credit: 'credit-a')->problem);
    }

    public function test_a_payment_of_a_settlement_intent_allocates_only_to_its_targets(): void
    {
        $this->obligation('o1', 1000);
        $this->obligation('o2', 1000);
        $this->ledger()->declareSettlementIntent(new DeclareSettlementIntent('intent', $this->account(), 'intent-a', 'RUB', ['o1']));
        $intent = self::integer($this->value('SELECT id FROM billium_settlement_intent'));
        $this->payWith(intent: $intent);
        self::assertTrue($this->allocateFrom('i1', 'o1', '5.00')->accepted);
        self::assertSame($intent, self::integer($this->value('SELECT settlement_intent_id FROM billium_allocation')));
        try {
            $this->allocateFrom('i2', 'o2', '5.00');
            self::fail('o2 is not a target of the intent');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ReferenceUnknown, $e->problem);
        }
        self::assertSame('applied', $this->value('SELECT status FROM billium_settlement_intent'));
    }

    public function test_reversal_is_full_immutable_unique_and_places_a_remainder(): void
    {
        $this->obligation('obligation-a', 10000);
        $this->draft();
        $this->issue();
        $this->payWith();
        $this->allocateFrom('a1', 'obligation-a', '100.00');
        self::assertSame('paid', $this->invoiceStatus());

        // Partial correction: reverse all 100.00, place 60.00 again from the same payment in the same Tx.
        $reversal = $this->reverse('r1', 'a1', '60.00');
        self::assertTrue($reversal->accepted);
        self::assertSame(['10000', '6000'], [$reversal->body['reversed_minor'] ?? null, $reversal->body['remainder_minor'] ?? null]);
        self::assertSame([10000, -10000, 6000], array_map(static fn (array $r): int => self::integer($r['amount_minor']), $this->table('SELECT amount_minor FROM billium_allocation ORDER BY id')));
        self::assertSame(1, $this->rows('billium_allocation', 'reverses_id IS NOT NULL'));
        self::assertSame(['allocated' => 6000, 'credited' => 0, 'open' => 4000, 'status' => 'open'], $this->obligationState('obligation-a'));
        self::assertSame(['allocated' => 6000, 'flag' => false], $this->paymentState());
        self::assertSame('partially_paid', $this->invoiceStatus());
        self::assertSame(2, $this->rows('billium_outbox_event', "type='allocation.applied.v1'"));

        // Replay under a new key returns the receipt; a second reversal of the same original conflicts.
        self::assertSame($reversal->body, $this->reverse('r1', 'a1', '60.00', 'rev-r1-again')->body);
        try {
            $this->reverse('r2', 'a1');
            self::fail('UNIQUE original reversal');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
        }
        try {
            $this->reverse('r4', 'missing');
            self::fail('unknown original');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ReferenceUnknown, $e->problem);
        }
        self::assertSame(3, $this->rows('billium_allocation'));
        self::assertSame(2, $this->rows('billium_outbox_event', "type='allocation.applied.v1'"));

        // A full reversal without remainder returns the invoice to issued.
        $this->allocateFrom('a2', 'obligation-a', '40.00');
        self::assertSame('paid', $this->invoiceStatus());
        self::assertTrue($this->reverse('r5', 'a2')->accepted);
        self::assertSame('partially_paid', $this->invoiceStatus());
        self::assertSame(6000, $this->obligationState('obligation-a')['allocated']);
    }

    public function test_a_remainder_in_another_currency_is_refused_and_changes_nothing(): void
    {
        $this->obligation('o1', 10000);
        $this->payWith();
        $this->allocateFrom('a1', 'o1', '100.00')->orThrow();
        try {
            $this->ledger()->reverseAllocation(new ReverseAllocation('rev-usd', $this->account(), 'usd', 'a1', 'correction', Money::parse('60.00', 'USD')));
            self::fail('a remainder is placed in the currency of the original allocation');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::MoneyCurrencyMismatch, $e->problem);
        }
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertSame(10000, $this->obligationState('o1')['allocated']);
        self::assertSame(['allocated' => 10000, 'flag' => false], $this->paymentState());
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_key LIKE '%rev-usd%'"));

        // The same reversal in the original currency still goes through.
        self::assertSame('6000', $this->reverse('rub', 'a1', '60.00')->orThrow()->body['remainder_minor'] ?? null);
    }

    public function test_positive_remainders_can_be_reversed_through_the_returned_journal_identity_until_zero(): void
    {
        $this->obligation('o1', 10000);
        $this->payWith();
        $this->allocateFrom('a1', 'o1', '100.00')->orThrow();
        $first = $this->reverse('r1', 'a1', '60.00')->orThrow();
        $remainder = $first->body['remainder_allocation_ref'] ?? null;
        self::assertIsInt($remainder);
        self::assertSame(['6000', 'reversal:r1'], [$first->body['remainder_minor'],
            $this->value('SELECT receipt_ref FROM billium_allocation WHERE id = ?', [$remainder])]);
        self::assertSame(6000, $this->obligationState('o1')['allocated']);
        $second = $this->reverse('r2', $remainder, '20.00')->orThrow();
        self::assertSame(['6000', '2000', 'reversal:r1'], [$second->body['reversed_minor'], $second->body['remainder_minor'], $second->body['reverses_receipt_ref']]);
        $last = $second->body['remainder_allocation_ref'] ?? null;
        self::assertIsInt($last);
        $final = $this->reverse('r3', $last)->orThrow();
        self::assertNull($final->body['remainder_allocation_ref']);
        self::assertSame(['2000', '0'], [$final->body['reversed_minor'], $final->body['remainder_minor']]);
        self::assertSame(0, $this->obligationState('o1')['allocated']);
        self::assertSame(['allocated' => 0, 'flag' => false], $this->paymentState());
        self::assertSame(0, self::integer($this->value('SELECT sum(amount_minor) FROM billium_allocation')));
        self::assertSame(3, $this->rows('billium_allocation', 'reverses_id IS NOT NULL'));
        self::assertSame($first->body, $this->reverse('r1', 'a1', '60.00', 'again-r1')->body);
        self::assertSame($second->body, $this->reverse('r2', $remainder, '20.00', 'again-r2')->body);
        self::assertSame($final->body, $this->reverse('r3', $last, key: 'again-r3')->body);
        try {
            $this->reverse('another-reversal', $remainder);
            self::fail('a remainder is reversed at most once');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::IdempotencyConflict, $e->problem);
        }
        self::assertSame(4, $this->rows('billium_outbox_event', "type = 'allocation.applied.v1'"));
        $event = json_decode(self::text($this->value("SELECT payload FROM billium_outbox_event WHERE type = 'allocation.applied.v1' ORDER BY id DESC LIMIT 1")), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($event);
        self::assertSame('reversal:r2', $event['reverses_receipt_ref']);
    }

    public function test_journal_identity_preserves_scope_checks_and_numeric_string_allocation_references(): void
    {
        $this->obligation('o1', 10000);
        $this->obligation('o2', 1000);
        $this->payWith(captured: 11000);
        $this->allocateFrom('a1', 'o1', '100.00')->orThrow();
        $first = $this->reverse('r1', 'a1', '60.00')->orThrow();
        $remainder = $first->body['remainder_allocation_ref'] ?? null;
        self::assertIsInt($remainder);
        $this->allocateFrom((string) $remainder, 'o2', '10.00')->orThrow();
        $this->reverse('numeric-reference', (string) $remainder)->orThrow();
        self::assertSame(0, $this->obligationState('o2')['allocated']);
        self::assertSame(6000, $this->obligationState('o1')['allocated']);
        try {
            $this->ledger()->reverseAllocation(new ReverseAllocation('wrong-account', $this->account('account-b'), 'foreign', $remainder, 'correction'));
            self::fail('a journal id does not bypass the bound billing account');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ReferenceUnknown, $e->problem);
        }
        foreach ([0, -1] as $id) {
            try {
                $this->reverse('invalid-'.$id, $id);
                self::fail('journal identities must be positive');
            } catch (ProblemException $e) {
                self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
            }
        }
        $this->reverse('valid-remainder', $remainder)->orThrow();
        self::assertSame(0, $this->obligationState('o1')['allocated']);
    }

    public function test_reversal_of_a_remainder_validates_its_bound(): void
    {
        $this->obligation('o1', 1000);
        $this->payWith();
        $this->allocateFrom('a1', 'o1', '10.00');
        try {
            $this->reverse('r1', 'a1', '10.00');
            self::fail('remainder equal to the original is not a correction');
        } catch (ProblemException $e) {
            self::assertSame(ProblemCode::ValidationInvalid, $e->problem);
        }
        self::assertSame(1, $this->rows('billium_allocation'));
    }
}
