<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Contracts\LedgerQueries;
use Billium\Ledger\Internal\Observation\RefundReconciler;
use Billium\Ledger\Internal\Observation\RefundReconcileReport;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * D41/D48 comparison basis (observation-apply, RefundReconciler): the charge refund basis is compared with succeeded
 * refund details only after the consistency window, so the ordinary refund → charge order records nothing; a mismatch
 * is stored with a basis digest of the charge revision and the ordered contributing refund revisions/amounts, an
 * identical basis dedupes, a changed detail under the same charge revision is a new visible row, and later agreement
 * appends resolution without editing history. The ledger never changes here.
 */
#[Group('D60')]
#[Group('pg')]
final class RefundReconcileBasisTest extends ObservationTestCase
{
    #[TestWith([0])]
    #[TestWith([PHP_INT_MAX])]
    public function test_overflowing_refund_details_record_a_durable_mismatch_without_starving_the_next_charge(int $refunded): void
    {
        $this->debt('o1', 1000);
        $this->debt('o2', 1000);
        $this->intent('intent-a', ['o1']);
        $this->intent('intent-b', ['o2']);
        $this->observe(ObservationFactory::charge('op-1', 1, PHP_INT_MAX, $refunded, sequence: 1));
        foreach (['refund-1', 'refund-2'] as $index => $operation) {
            $receipt = $this->observe(ObservationFactory::refund($operation, 'op-1', 1, 5_000_000_000_000_000_000, sequence: $index + 2));
            self::assertSame('refund_detail', $receipt->mode());
        }
        $this->observe(ObservationFactory::charge('op-2', 1, 1000, 300, sequence: 4, fields: ['businessIntentRef' => 'intent-b']));

        self::assertEquals(new RefundReconcileReport(1, 1, 0), $this->reconciler()->reconcile(0, 1));
        $first = $this->row("SELECT * FROM billium_ledger_discrepancy WHERE reason = 'refund_basis_mismatch'");
        self::assertSame(self::integer($this->observedRow()['id']), self::integer($first['observed_payment_id']));
        self::assertSame($refunded, self::integer($first['expected_minor']));
        self::assertSame($refunded === PHP_INT_MAX ? PHP_INT_MAX - 1 : PHP_INT_MAX, self::integer($first['actual_minor']));
        self::assertFalse($first['hold']);

        self::assertEquals(new RefundReconcileReport(1, 1, 0), $this->reconciler()->reconcile(0, 1));
        $secondId = self::integer($this->observedRow('op-2')['id']);
        self::assertSame(1, $this->rows('billium_ledger_discrepancy', "reason = 'refund_basis_mismatch' AND observed_payment_id = ".$secondId));
        self::assertEquals(new RefundReconcileReport(2, 0, 0), $this->reconciler()->reconcile(0));
        self::assertSame(2, $this->rows('billium_ledger_discrepancy', "reason = 'refund_basis_mismatch'"));
        self::assertSame(2, $this->rows('billium_outbox_event', "type = 'discrepancy.recorded.v1'"));
        self::assertSame(0, $this->rows('billium_discrepancy_event', "kind = 'resolved'"));
    }

    public function test_a_pre_upgrade_mismatch_is_reused_and_resolved_without_duplicate_history(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, 300));
        $this->observe(ObservationFactory::refund('op-r1', 'op-1', 1, 100, sequence: 2));
        $basis = (new Codec)->digest((object) ['normalization' => RefundReconciler::BASIS, 'paymentRef' => 'op-1', 'chargeRevision' => 1,
            'chargeRefunded' => '300', 'refunds' => [['operationRef' => 'op-r1', 'revision' => 1, 'captured' => '100']]], 'basisDigest');
        $fingerprint = 'refund_basis:op-1:'.$basis;
        $this->owner()->insert(<<<'SQL'
            INSERT INTO billium_ledger_discrepancy(installation_ref,seller_ref,environment,fingerprint,comparison_basis_digest,
                observed_payment_id,observed_revision,charge_revision,refund_revision,expected_minor,actual_minor,hold,reason)
            VALUES('inst-test','seller-a','test',?,?,?,1,1,1,300,100,false,'refund_basis_mismatch')
            SQL, [$fingerprint, $basis, self::integer($this->observedRow()['id'])]);
        self::assertSame(0, $this->reconciler()->reconcile(0)->recorded);
        self::assertSame(1, $this->rows('billium_ledger_discrepancy'));
        self::assertSame($fingerprint, $this->value('SELECT fingerprint FROM billium_ledger_discrepancy'));
        $this->observe(ObservationFactory::refund('op-r1', 'op-1', 2, 300, sequence: 3));
        self::assertSame(1, $this->reconciler()->reconcile(0)->resolved);
        self::assertSame(1, $this->rows('billium_ledger_discrepancy'));
    }

    public function test_identical_refund_bases_in_two_authorities_record_independent_discrepancies(): void
    {
        foreach (['embedded-commerz', 'remote-billium'] as $index => $authority) {
            $this->debt('o'.$index, 1000);
            $this->intent('intent-'.$index, ['o'.$index]);
            $fields = ['sourceAuthority' => $authority, 'businessIntentRef' => 'intent-'.$index];
            $this->observe(ObservationFactory::charge('same-charge', 1, 1000, 300, fields: $fields));
            $this->observe(ObservationFactory::refund('same-refund', 'same-charge', 1, 400, sequence: 2, fields: $fields));
        }
        self::assertSame(2, $this->reconciler()->reconcile(0)->recorded);
        self::assertSame(0, $this->reconciler()->reconcile(0)->recorded);
        self::assertSame(2, $this->rows('billium_ledger_discrepancy'));
        self::assertSame(2, $this->rows('billium_outbox_event', "type='discrepancy.recorded.v1'"));
        self::assertSame(1, self::integer($this->value('SELECT count(DISTINCT comparison_basis_digest) FROM billium_ledger_discrepancy')));
    }

    public function test_a_bounded_worker_reaches_later_charges_and_revisits_changed_details(): void
    {
        $this->debt('o1', 1000);
        $this->debt('o2', 1000);
        $this->intent('intent-a', ['o1']);
        $this->intent('intent-b', ['o2']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, 200, sequence: 1));
        $this->observe(ObservationFactory::refund('refund-1', 'op-1', 1, 200, sequence: 2));
        $this->observe(ObservationFactory::charge('op-2', 1, 1000, 300, sequence: 3, fields: ['businessIntentRef' => 'intent-b']));
        $this->observe(ObservationFactory::refund('refund-2', 'op-2', 1, 100, sequence: 4));
        self::assertSame(0, $this->reconciler()->reconcile(0, 1)->recorded);
        self::assertSame(1, $this->reconciler()->reconcile(0, 1)->recorded);
        for ($i = 0; $i < 4; $i++) {
            self::assertSame(0, $this->reconciler()->reconcile(0, 1)->recorded);
        }
        self::assertSame(1, $this->rows('billium_ledger_discrepancy'));
        $this->observe(ObservationFactory::refund('refund-2', 'op-2', 2, 200, sequence: 5));
        $recorded = 0;
        for ($i = 0; $i < 2; $i++) {
            $recorded += $this->reconciler()->reconcile(0, 1)->recorded;
        }
        self::assertSame(1, $recorded);
        $this->observe(ObservationFactory::refund('refund-2', 'op-2', 3, 300, sequence: 6));
        $resolved = 0;
        for ($i = 0; $i < 2; $i++) {
            $resolved += $this->reconciler()->reconcile(0, 1)->resolved;
        }
        self::assertSame(2, $resolved);
        self::assertSame(2, $this->rows('billium_discrepancy_event', "kind='resolved'"));
        self::assertSame(2, $this->rows('billium_payment'));
    }

    public function test_a_negative_window_or_an_unbounded_limit_is_refused_before_any_comparison(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, 300));
        foreach ([[-1, 100], [0, 0], [0, 1001]] as [$window, $limit]) {
            try {
                $this->reconciler()->reconcile($window, $limit);
                self::fail("window {$window}, limit {$limit}");
            } catch (InvalidArgumentException) {
            }
        }
        self::assertSame(0, $this->rows('billium_refund_reconcile_progress'));
        self::assertEquals(new RefundReconcileReport(1, 1, 0), $this->reconciler()->reconcile(0, 1000));
    }

    public function test_the_ordinary_refund_before_charge_order_records_no_discrepancy(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->tryObserve(ObservationFactory::refund('op-r1', 'op-1', 1, 300, sequence: 1));
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, 300, sequence: 2));

        self::assertEquals(new RefundReconcileReport(0, 0, 0), $this->reconciler()->reconcile(3600), 'nothing inside the window');
        $this->drainer()->retryParked();
        self::assertEquals(new RefundReconcileReport(1, 0, 0), $this->reconciler()->reconcile(0));
        self::assertSame(0, $this->rows('billium_ledger_discrepancy'));
        self::assertSame(0, $this->rows('billium_outbox_event', "type = 'discrepancy.recorded.v1'"));
    }

    public function test_a_mismatch_is_recorded_per_basis_and_resolved_append_only(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, sequence: 1));
        $this->observe(ObservationFactory::charge('op-1', 2, 1000, 300, sequence: 2));
        $this->observe(ObservationFactory::refund('op-r1', 'op-1', 1, 100, sequence: 3));

        self::assertSame(1, $this->reconciler()->reconcile(0)->recorded);
        self::assertSame(0, $this->reconciler()->reconcile(0)->recorded, 'an identical basis dedupes');
        $first = $this->row("SELECT * FROM billium_ledger_discrepancy WHERE reason = 'refund_basis_mismatch'");
        self::assertSame([300, 100, 2, 1, false], [self::integer($first['expected_minor']), self::integer($first['actual_minor']),
            self::integer($first['charge_revision']), self::integer($first['refund_revision']), $first['hold']]);
        self::assertSame('refund_basis:embedded-commerz:op-1:'.self::text($first['comparison_basis_digest']), $first['fingerprint']);
        self::assertSame(1, $this->rows('billium_outbox_event', "type = 'discrepancy.recorded.v1'"));

        // The detail changes, the charge revision does not: the new mismatch basis is visible on its own row.
        $this->observe(ObservationFactory::refund('op-r1', 'op-1', 2, 200, sequence: 4));
        self::assertSame(1, $this->reconciler()->reconcile(0)->recorded);
        $rows = $this->table('SELECT charge_revision, refund_revision, actual_minor, comparison_basis_digest FROM billium_ledger_discrepancy ORDER BY id');
        self::assertCount(2, $rows);
        self::assertSame([2, 2], [self::integer($rows[0]['charge_revision']), self::integer($rows[1]['charge_revision'])]);
        self::assertSame([1, 2], [self::integer($rows[0]['refund_revision']), self::integer($rows[1]['refund_revision'])]);
        self::assertNotSame($rows[0]['comparison_basis_digest'], $rows[1]['comparison_basis_digest']);

        // Agreement appends resolution for every open mismatch; nothing is edited and the ledger stays as it was.
        $this->observe(ObservationFactory::refund('op-r2', 'op-1', 1, 100, sequence: 5));
        self::assertEquals(new RefundReconcileReport(1, 0, 2), $this->reconciler()->reconcile(0));
        self::assertSame(2, $this->rows('billium_discrepancy_event', "kind = 'resolved'"));
        self::assertSame(2, $this->rows('billium_ledger_discrepancy'));
        self::assertEquals(new RefundReconcileReport(1, 0, 0), $this->reconciler()->reconcile(0));
        $payment = $this->paymentRow();
        self::assertSame([300, false, 1000], [self::integer($payment['refunded_minor']), $payment['financial_hold'], self::integer($payment['allocated_minor'])]);
    }

    public function test_a_quarantined_currency_mismatch_never_contributes_to_the_refund_basis(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, 300));
        self::assertSame(1, $this->reconciler()->reconcile(0)->recorded);
        $refund = ObservationFactory::refund('usd-refund', 'op-1', 1, 300, sequence: 2, fields: ['currency' => 'USD']);
        self::assertSame(ProblemCode::ObservationConflict->value, $this->tryObserve($refund));
        self::assertSame('quarantined', $this->inboxStatus(self::eventId($refund)));
        self::assertNull($this->observedRow('usd-refund')['billing_account_ref']);
        self::assertEquals(new RefundReconcileReport(1, 0, 0), $this->reconciler()->reconcile(0));
        self::assertSame(0, $this->rows('billium_discrepancy_event', "kind='resolved'"));
    }

    public function test_an_unlinked_wrong_currency_refund_does_not_schedule_a_comparison(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000));
        $this->tryObserve(ObservationFactory::refund('usd-refund', 'op-1', 1, 300, sequence: 2, fields: ['currency' => 'USD']));
        self::assertEquals(new RefundReconcileReport(0, 0, 0), $this->reconciler()->reconcile(0));
    }

    #[TestWith([300, 'succeeded', 'regression_captured'])]
    #[TestWith([500, 'pending', 'regression_state'])]
    public function test_refund_regressions_hold_the_charge_and_keep_the_last_validated_basis(int $amount, string $state, string $reason): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, 300));
        $this->observe(ObservationFactory::refund('op-r1', 'op-1', 1, 400, sequence: 2));
        $this->observe(ObservationFactory::refund('op-r1', 'op-1', 2, 500, sequence: 3));
        self::assertSame(1, $this->reconciler()->reconcile(0)->recorded);
        $refund = ObservationFactory::refund('op-r1', 'op-1', 3, $amount, sequence: 4, fields: ['state' => $state]);
        $receipt = $this->observe($refund);
        self::assertSame('hold', $receipt->mode());
        self::assertTrue($receipt->body['financial_hold']);
        $discrepancy = $this->row('SELECT * FROM billium_ledger_discrepancy WHERE hold');
        self::assertSame([$reason, 500, $amount, 1, 3, true], [$discrepancy['reason'], self::integer($discrepancy['expected_minor']),
            self::integer($discrepancy['actual_minor']), self::integer($discrepancy['charge_revision']), self::integer($discrepancy['refund_revision']), $discrepancy['hold']]);
        self::assertSame(self::integer($this->observedRow('op-r1')['id']), self::integer($discrepancy['observed_payment_id']));
        self::assertTrue($this->paymentRow()['financial_hold']);
        self::assertSame('held', $this->paymentRow()['projection_status']);
        self::assertSame(300, self::integer($this->paymentRow()['refunded_minor']));
        self::assertEquals(new RefundReconcileReport(1, 0, 0), $this->reconciler()->reconcile(0));
        self::assertSame(0, $this->rows('billium_discrepancy_event', "kind='resolved'"));
        self::assertSame(1, $this->rows('billium_discrepancy_event', "kind='held'"));
        $status = $this->application()->make(LedgerQueries::class)->paymentStatus('op-1');
        self::assertSame(['active' => true, 'reasonCodes' => [$reason], 'discrepancyRefs' => [$discrepancy['fingerprint']]], $status['financialHold']);
        self::assertSame('held_discrepancy', $status['projectionStatus']);
        $replay = $this->observe(ObservationFactory::with($refund, ['eventId' => (string) Str::ulid(), 'sourceSequence' => 5]));
        self::assertSame($receipt->receiptRef, $replay->receiptRef);
        self::assertSame(2, $this->rows('billium_outbox_event', "type='discrepancy.recorded.v1'"), 'one basis event and one held event, including replay');
        $event = $this->row("SELECT payload FROM billium_outbox_event WHERE type='discrepancy.recorded.v1' AND payload->>'reason' = ?", [$reason]);
        $body = self::arr(json_decode(self::text($event['payload']), true, 64, JSON_THROW_ON_ERROR));
        self::assertSame('500', $body['expected_minor']);
        self::assertSame($receipt->receiptRef, $body['receipt_ref']);
    }

    public function test_a_held_exceptional_discrepancy_stays_visible_beside_a_consistent_refund_basis(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, 300, sequence: 1));
        $this->observe(ObservationFactory::refund('op-r1', 'op-1', 1, 300, sequence: 2));
        $this->observe(ObservationFactory::charge('op-1', 2, 1000, 1500, sequence: 3));

        self::assertSame(0, $this->reconciler()->reconcile(0)->recorded, 'the ordinary basis 300 agrees with the detail');
        self::assertSame(1, $this->rows('billium_ledger_discrepancy', "reason = 'over_refund' AND hold"));
        self::assertSame(0, $this->rows('billium_discrepancy_event', "kind = 'resolved'"), 'basis agreement never resolves a held exception');
        self::assertTrue($this->paymentRow()['financial_hold']);
    }
}
