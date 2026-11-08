<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use Billium\Ledger\Tests\Observation\OverRefundVector;
use PHPUnit\Framework\Attributes\Group;

/**
 * D57 actual vs applied (invariant-model, observation-apply 7): an actual provider refund above the capture is retained
 * unclamped and unrejected, the applied projection keeps its last consistent totals, the exposure is visible, a
 * financial hold makes the payment unspendable and needs explicit repair. Over-capture and regressions take the same
 * path. No automatic refund, charge or collection is created.
 */
#[Group('D60')]
final class ExceptionalExposureTest extends ObservationTestCase
{
    use OverRefundVector;

    public function test_an_actual_over_refund_is_kept_held_and_never_clamped(): void
    {
        ['consistent' => $consistent, 'held' => $held, 'refusal' => $refusal] = $this->overRefund('embedded-commerz');

        self::assertSame('1000', $consistent->body['spendable_minor'], 'before the exception 30.00 − 20.00 allocated was spendable');
        self::assertSame('hold', $held->mode());
        self::assertEquals(['revision' => 2, 'captured_minor' => '10000', 'refunded_minor' => '7000'], $held->body['applied']);
        self::assertSame('14000', self::arr($held->body['observed'])['refunded_minor']);
        $discrepancy = self::arr($held->body['discrepancy']);
        self::assertSame('over_refund', $discrepancy['reason']);
        self::assertSame('4000', $discrepancy['exposure_minor']);
        self::assertSame('10000', $discrepancy['expected_minor']);
        self::assertSame('14000', $discrepancy['actual_minor']);
        self::assertTrue($held->body['financial_hold']);
        self::assertSame('0', $held->body['spendable_minor']);
        self::assertSame(ProblemCode::AllocationReallocationRequired->value, $refusal, 'typed financial-hold allocation refusal');

        $payment = $this->paymentRow();
        self::assertSame([10000, 7000, 2000, true, 'held', 2], [self::integer($payment['captured_minor']), self::integer($payment['refunded_minor']),
            self::integer($payment['allocated_minor']), $payment['financial_hold'], $payment['projection_status'], self::integer($payment['applied_revision'])]);
        $observed = $this->observedRow();
        self::assertSame([10000, 14000, 3], [self::integer($observed['captured_minor']), self::integer($observed['refunded_minor']), self::integer($observed['revision'])]);
        self::assertSame(1, $this->rows('billium_ledger_discrepancy', "reason = 'over_refund' AND hold AND expected_minor = 10000 AND actual_minor = 14000"));
        self::assertSame(1, $this->rows('billium_outbox_event', "type = 'discrepancy.recorded.v1'"));
        self::assertSame(2, $this->rows('billium_allocation'), 'no allocation and no reversal from the exception');
        self::assertSame(0, $this->rows('billium_outbox_command'), 'no automatic refund, charge or collection command');
    }

    public function test_over_capture_and_regression_are_held_with_the_last_consistent_totals(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 5000, sequence: 1));

        $over = $this->observe(ObservationFactory::charge('op-1', 2, 7000, sequence: 2, fields: ['authorizedTotal' => 6000]));
        self::assertSame('over_capture', self::arr($over->body['discrepancy'])['reason']);
        self::assertSame('1000', self::arr($over->body['discrepancy'])['exposure_minor']);
        self::assertSame(5000, self::integer($this->paymentRow()['captured_minor']));

        $regression = $this->observe(ObservationFactory::charge('op-1', 3, 3000, sequence: 3));
        self::assertSame('hold', $regression->mode());
        self::assertSame('regression_captured', self::arr($regression->body['discrepancy'])['reason']);
        self::assertSame(5000, self::integer($this->paymentRow()['captured_minor']), 'no silent negative cumulative overwrite');
        self::assertSame(3000, self::integer($this->observedRow()['captured_minor']));
        self::assertSame(2, $this->rows('billium_ledger_discrepancy', 'hold'));
    }

    public function test_an_actual_effect_against_a_closed_intent_is_held_without_reopening_it(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $intent = self::integer($this->value("SELECT id FROM billium_settlement_intent WHERE business_intent_ref = 'intent-a'"));
        $this->owner()->update("UPDATE billium_settlement_intent SET status = 'closed_no_effect', revision = revision + 1 WHERE id = ?", [$intent]);

        $held = $this->observe(ObservationFactory::charge('op-1', 1, 1000));

        self::assertSame('hold', $held->mode());
        self::assertSame('closed_intent_effect', self::arr($held->body['discrepancy'])['reason']);
        self::assertNull($this->paymentRow()['settlement_intent_id']);
        self::assertSame('closed_no_effect', $this->value('SELECT status FROM billium_settlement_intent WHERE id = ?', [$intent]));
        self::assertSame(0, $this->rows('billium_allocation'));
        self::assertSame(1000, self::integer($this->observedRow()['captured_minor']));
    }
}
