<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Data\AllocationReceipt;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use Illuminate\Support\Str;

/**
 * applyPaymentObservation semantics (INV-06, D41, D46, D48, REQ-ACC-10, REQ-FAIL-12/13): cumulative totals are assigned
 * per revision, a compatible older revision moves no money, a changed snapshot of the same revision is a conflict, a
 * source discrepancy holds the payment, and a refund child never adds to the charge refund basis.
 */
final class ObservationApplyTest extends ObservationTestCase
{
    public function test_partial_payments_allocate_the_available_delta_of_each_assigned_revision(): void
    {
        $this->debt('o1', 1000);
        $this->invoice(self::integer($this->value("SELECT id FROM billium_obligation WHERE obligation_ref = 'o1'")));
        $this->intent('intent-a', ['o1']);

        $first = $this->observe(ObservationFactory::charge('op-1', 1, 300, sequence: 1));
        self::assertSame('assign', $first->mode());
        self::assertSame(300, $this->allocated('o1'));
        self::assertSame(['partially_paid', 700], $this->invoiceState());

        $second = $this->observe(ObservationFactory::charge('op-1', 2, 1000, sequence: 2));
        self::assertSame([['amount_minor' => '700', 'obligation_ref' => 'o1']], $second->body['allocations']);
        self::assertSame(1000, $this->allocated('o1'));
        self::assertSame(['paid', 0], $this->invoiceState());
        self::assertSame(1000, self::integer($this->paymentRow()['captured_minor']), 'assigned, not 300 + 1000');
        self::assertSame(2, self::integer($this->paymentRow()['applied_revision']));
        self::assertSame(2, $this->rows('billium_payment_revision'));
    }

    public function test_a_compatible_older_revision_moves_no_money(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 300, sequence: 1));
        $this->observe(ObservationFactory::charge('op-1', 3, 1000, sequence: 2));

        $stale = $this->observe(ObservationFactory::charge('op-1', 2, 600, sequence: 3));

        self::assertSame('stale', $stale->mode());
        self::assertSame('observation:op-1#2', $stale->receiptRef);
        self::assertSame([], $stale->body['allocations']);
        self::assertSame(1000, $this->allocated('o1'));
        self::assertSame(3, self::integer($this->paymentRow()['applied_revision']));
        self::assertSame(1000, self::integer($this->paymentRow()['captured_minor']));
        self::assertSame(3, self::integer($this->observedRow()['revision']), 'the current observed snapshot never regresses');
        self::assertSame(3, $this->rows('billium_observed_payment_revision'), 'the older revision is kept as history');
        self::assertSame(2, $this->rows('billium_allocation'));
    }

    public function test_the_same_revision_with_another_economic_snapshot_is_quarantined_and_the_partition_advances(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 300, sequence: 1));
        $changed = ObservationFactory::charge('op-1', 1, 400, sequence: 2);

        self::assertSame(ProblemCode::ObservationConflict->value, $this->attempt($changed));
        self::assertSame('quarantined', $this->inboxStatus(self::eventId($changed)));
        self::assertSame(300, $this->allocated('o1'));
        self::assertSame(300, self::integer($this->observedRow()['captured_minor']), 'the original revision stays untouched');

        $next = $this->observe(ObservationFactory::charge('op-1', 2, 1000, sequence: 3));
        self::assertSame('assign', $next->mode());
        self::assertSame(1000, $this->allocated('o1'));
    }

    public function test_a_source_reported_discrepancy_holds_the_last_consistent_projection(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 300, sequence: 1));
        $reported = ObservationFactory::charge('op-1', 2, 1000, sequence: 2, fields: [
            'discrepancy' => ['discrepancyRef' => 'disc-1', 'kind' => 'amount_mismatch', 'revision' => 1],
        ]);

        $held = $this->observe($reported);

        self::assertSame('hold', $held->mode());
        self::assertTrue($held->body['financial_hold']);
        self::assertSame('0', $held->body['spendable_minor']);
        self::assertEquals(['captured_minor' => '300', 'refunded_minor' => '0', 'revision' => 1], $held->body['applied']);
        self::assertSame('1000', self::arr($held->body['observed'])['captured_minor']);
        self::assertSame(300, $this->allocated('o1'), 'no allocation from a held snapshot');
        $payment = $this->paymentRow();
        self::assertTrue($payment['financial_hold']);
        self::assertSame('held', $payment['projection_status']);
        self::assertSame(300, self::integer($payment['captured_minor']));
        self::assertSame(1000, self::integer($this->observedRow()['captured_minor']));
        self::assertSame(1, $this->rows('billium_ledger_discrepancy', "reason = 'source_reported' AND hold"));
        self::assertSame(1, $this->rows('billium_discrepancy_event', "kind = 'held'"));
        self::assertSame(1, $this->rows('billium_outbox_event', "type = 'discrepancy.recorded.v1'"));

        // A later consistent revision is observed, yet the hold keeps the applied projection until explicit repair.
        $later = $this->observe(ObservationFactory::charge('op-1', 3, 1000, sequence: 3));
        self::assertSame('hold', $later->mode());
        self::assertSame(300, self::integer($this->paymentRow()['captured_minor']));
        self::assertSame(1, $this->rows('billium_ledger_discrepancy'));
    }

    public function test_a_refund_before_its_charge_parks_the_linkage_and_drains_after_the_charge(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $refund = ObservationFactory::refund('op-r1', 'op-1', 1, 300, sequence: 1);

        self::assertSame(ProblemCode::ObservationParked->value, $this->attempt($refund));
        self::assertSame('parked_dependency', $this->inboxStatus(self::eventId($refund)));
        self::assertSame(300, self::integer($this->observedRow('op-r1')['captured_minor']), 'the refund fact is observed');
        self::assertSame(1, $this->rows('billium_observation_park', "dependency_kind = 'original_charge' AND released_at IS NULL"));

        // The dependency-parked refund does not block the partition: the charge at sequence 2 applies at once.
        $charge = $this->observe(ObservationFactory::charge('op-1', 1, 1000, 300, sequence: 2));
        self::assertSame('assign', $charge->mode());

        self::assertSame(['applied'], $this->drainer()->retryParked());
        self::assertSame('applied', $this->inboxStatus(self::eventId($refund)));
        self::assertSame(1, $this->rows('billium_allocation_receipt', "kind = 'refund_detail'"));
        self::assertSame(0, $this->rows('billium_observation_park', 'released_at IS NULL'));
        self::assertSame(300, self::integer($this->paymentRow()['refunded_minor']), 'D41: charge refundedTotal is the only basis');
        self::assertSame([], $this->drainer()->retryParked());
    }

    public function test_a_refund_child_never_adds_to_the_charge_refund_basis(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, sequence: 1));

        $detail = $this->observe(ObservationFactory::refund('op-r1', 'op-1', 1, 300, sequence: 2));
        self::assertSame('refund_detail', $detail->mode());
        self::assertSame(0, self::integer($this->paymentRow()['refunded_minor']), 'the child changes only its own detail');
        self::assertFalse($this->paymentRow()['reallocation_required']);

        $charge = $this->observe(ObservationFactory::charge('op-1', 2, 1000, 300, sequence: 3));
        $again = $this->observe(ObservationFactory::refund('op-r1', 'op-1', 1, 300, sequence: 4, fields: ['eventId' => (string) Str::ulid()]));

        self::assertInstanceOf(AllocationReceipt::class, $again);
        self::assertSame($detail->receiptRef, $again->receiptRef);
        self::assertSame(300, self::integer($this->paymentRow()['refunded_minor']), 'charge 100.00, refund 30.00 → 30.00, not 60.00');
        self::assertTrue($this->paymentRow()['reallocation_required'], 'allocated 1000 > captured 1000 − refunded 300 (D35)');
        self::assertTrue($charge->body['reallocation_required']);
        self::assertSame([], $charge->body['allocations']);
        self::assertSame(1000, $this->allocated('o1'), 'a confirmed refund never reverses allocations by itself');
    }

    /** @return array{0: string, 1: int} */
    private function invoiceState(): array
    {
        $row = $this->row("SELECT status, open_minor FROM billium_invoice WHERE invoice_ref = 'invoice-a'");

        return [self::text($row['status']), self::integer($row['open_minor'])];
    }
}
