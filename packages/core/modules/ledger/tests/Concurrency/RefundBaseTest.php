<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Concurrency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Tests\Observation\ObservationScenario;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * ACC-CON-26 (D41, D48, INV-06): charge C1 captured 100.00 fully allocated; four processes deliver C2 (refundedTotal
 * 30.00), the refund child R1 (30.00) and a repeat of each. The charge refundedTotal is the only basis: refunded 30.00,
 * never 60.00; reallocation_required is set (D35); nothing is quarantined; the reconcile job after the window finds no
 * discrepancy. With R1 = 40.00 it records exactly one discrepancy and the ledger is unchanged.
 */
#[Group('ACC-CON-26')]
final class RefundBaseTest extends ObservationTestCase
{
    public function test_charge_and_refund_child_repeats_assign_the_basis_once(): void
    {
        $this->race(3000);

        self::assertSame(['compared' => 1, 'recorded' => 0, 'resolved' => 0], $this->reconciler()->reconcile(0));
        self::assertSame(0, $this->rows('billium_ledger_discrepancy'));
    }

    public function test_a_refund_detail_of_forty_records_one_discrepancy(): void
    {
        $this->race(4000);

        self::assertSame(1, $this->reconciler()->reconcile(0)['recorded']);
        self::assertSame(0, $this->reconciler()->reconcile(0)['recorded']);
        self::assertSame(1, $this->rows('billium_ledger_discrepancy', "reason = 'refund_basis_mismatch' AND expected_minor = 3000 AND actual_minor = 4000 AND NOT hold"));
        self::assertSame(1, $this->rows('billium_outbox_event', "type = 'discrepancy.recorded.v1'"));
        self::assertSame(3000, self::integer($this->paymentRow()['refunded_minor']), 'the ledger is not changed by the reconcile');
    }

    private function race(int $refundAmount): void
    {
        $this->debt('o1', 10000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 10000, sequence: 1));
        $c2 = ObservationFactory::json(ObservationFactory::charge('op-1', 2, 10000, 3000, sequence: 2));
        $r1 = ObservationFactory::json(ObservationFactory::refund('op-r1', 'op-1', 1, $refundAmount, sequence: 3));
        $plan = [];
        foreach ([$c2, $r1, $c2, $r1] as $index => $json) {
            $plan[] = ['action' => 'observe', 'json' => $json, 'delay_ms' => $index * 5];
        }

        $results = ConcurrencyStand::run(ObservationScenario::class, 4, ['plan' => $plan], 90);

        foreach ($results as $result) {
            self::assertContains(self::ok($result)['problem'], [null, ProblemCode::ObservationParked->value], json_encode($result, JSON_THROW_ON_ERROR));
        }
        $payment = $this->paymentRow();
        self::assertSame(3000, self::integer($payment['refunded_minor']), 'assigned from C2, the child never adds');
        self::assertTrue($payment['reallocation_required']);
        self::assertSame(10000, self::integer($payment['allocated_minor']));
        self::assertSame(0, $this->rows('billium_inbox_message', "status = 'quarantined'"));
        self::assertSame(3, $this->rows('billium_inbox_message', "status = 'applied'"));
        self::assertSame(1, $this->rows('billium_allocation_receipt', "kind = 'refund_detail'"));
        self::assertSame($refundAmount, self::integer($this->observedRow('op-r1')['captured_minor']));
    }
}
