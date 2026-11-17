<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Contracts\Codec;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Internal\Observation\RefundReconciler;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use PHPUnit\Framework\Attributes\Group;

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
    public function test_a_pre_upgrade_mismatch_is_reused_and_resolved_without_duplicate_history(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, 300));
        $this->observe(ObservationFactory::refund('op-r1', 'op-1', 1, 400, sequence: 2));
        $basis = (new Codec)->digest((object) ['normalization' => RefundReconciler::BASIS, 'paymentRef' => 'op-1', 'chargeRevision' => 1,
            'chargeRefunded' => '300', 'refunds' => [['operationRef' => 'op-r1', 'revision' => 1, 'captured' => '400']]], 'basisDigest');
        $fingerprint = 'refund_basis:op-1:'.$basis;
        $this->owner()->insert(<<<'SQL'
            INSERT INTO billium_ledger_discrepancy(installation_ref,seller_ref,environment,fingerprint,comparison_basis_digest,
                observed_payment_id,observed_revision,charge_revision,refund_revision,expected_minor,actual_minor,hold,reason)
            VALUES('inst-test','seller-a','test',?,?,?,1,1,1,300,400,false,'refund_basis_mismatch')
            SQL, [$fingerprint, $basis, self::integer($this->observedRow()['id'])]);
        self::assertSame(0, $this->reconciler()->reconcile(0)['recorded']);
        self::assertSame(1, $this->rows('billium_ledger_discrepancy'));
        self::assertSame($fingerprint, $this->value('SELECT fingerprint FROM billium_ledger_discrepancy'));
        $this->observe(ObservationFactory::refund('op-r1', 'op-1', 2, 300, sequence: 3));
        self::assertSame(1, $this->reconciler()->reconcile(0)['resolved']);
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
        self::assertSame(2, $this->reconciler()->reconcile(0)['recorded']);
        self::assertSame(0, $this->reconciler()->reconcile(0)['recorded']);
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
        $this->observe(ObservationFactory::refund('refund-2', 'op-2', 1, 400, sequence: 4));
        self::assertSame(0, $this->reconciler()->reconcile(0, 1)['recorded']);
        self::assertSame(1, $this->reconciler()->reconcile(0, 1)['recorded']);
        for ($i = 0; $i < 4; $i++) {
            self::assertSame(0, $this->reconciler()->reconcile(0, 1)['recorded']);
        }
        self::assertSame(1, $this->rows('billium_ledger_discrepancy'));
        $this->observe(ObservationFactory::refund('refund-2', 'op-2', 2, 500, sequence: 5));
        $recorded = 0;
        for ($i = 0; $i < 2; $i++) {
            $recorded += $this->reconciler()->reconcile(0, 1)['recorded'];
        }
        self::assertSame(1, $recorded);
        $this->observe(ObservationFactory::refund('refund-2', 'op-2', 3, 300, sequence: 6));
        $resolved = 0;
        for ($i = 0; $i < 2; $i++) {
            $resolved += $this->reconciler()->reconcile(0, 1)['resolved'];
        }
        self::assertSame(2, $resolved);
        self::assertSame(2, $this->rows('billium_discrepancy_event', "kind='resolved'"));
        self::assertSame(2, $this->rows('billium_payment'));
    }

    public function test_the_ordinary_refund_before_charge_order_records_no_discrepancy(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->tryObserve(ObservationFactory::refund('op-r1', 'op-1', 1, 300, sequence: 1));
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, 300, sequence: 2));

        self::assertSame(['compared' => 0, 'recorded' => 0, 'resolved' => 0], $this->reconciler()->reconcile(3600), 'nothing inside the window');
        $this->drainer()->retryParked();
        self::assertSame(['compared' => 1, 'recorded' => 0, 'resolved' => 0], $this->reconciler()->reconcile(0));
        self::assertSame(0, $this->rows('billium_ledger_discrepancy'));
        self::assertSame(0, $this->rows('billium_outbox_event', "type = 'discrepancy.recorded.v1'"));
    }

    public function test_a_mismatch_is_recorded_per_basis_and_resolved_append_only(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, sequence: 1));
        $this->observe(ObservationFactory::charge('op-1', 2, 1000, 300, sequence: 2));
        $this->observe(ObservationFactory::refund('op-r1', 'op-1', 1, 400, sequence: 3));

        self::assertSame(1, $this->reconciler()->reconcile(0)['recorded']);
        self::assertSame(0, $this->reconciler()->reconcile(0)['recorded'], 'an identical basis dedupes');
        $first = $this->row("SELECT * FROM billium_ledger_discrepancy WHERE reason = 'refund_basis_mismatch'");
        self::assertSame([300, 400, 2, 1, false], [self::integer($first['expected_minor']), self::integer($first['actual_minor']),
            self::integer($first['charge_revision']), self::integer($first['refund_revision']), $first['hold']]);
        self::assertSame('refund_basis:embedded-commerz:op-1:'.self::text($first['comparison_basis_digest']), $first['fingerprint']);
        self::assertSame(1, $this->rows('billium_outbox_event', "type = 'discrepancy.recorded.v1'"));

        // The detail changes, the charge revision does not: the new mismatch basis is visible on its own row.
        $this->observe(ObservationFactory::refund('op-r1', 'op-1', 2, 500, sequence: 4));
        self::assertSame(1, $this->reconciler()->reconcile(0)['recorded']);
        $rows = $this->table('SELECT charge_revision, refund_revision, actual_minor, comparison_basis_digest FROM billium_ledger_discrepancy ORDER BY id');
        self::assertCount(2, $rows);
        self::assertSame([2, 2], [self::integer($rows[0]['charge_revision']), self::integer($rows[1]['charge_revision'])]);
        self::assertSame([1, 2], [self::integer($rows[0]['refund_revision']), self::integer($rows[1]['refund_revision'])]);
        self::assertNotSame($rows[0]['comparison_basis_digest'], $rows[1]['comparison_basis_digest']);

        // Agreement appends resolution for every open mismatch; nothing is edited and the ledger stays as it was.
        $this->observe(ObservationFactory::refund('op-r1', 'op-1', 3, 300, sequence: 5));
        self::assertSame(['compared' => 1, 'recorded' => 0, 'resolved' => 2], $this->reconciler()->reconcile(0));
        self::assertSame(2, $this->rows('billium_discrepancy_event', "kind = 'resolved'"));
        self::assertSame(2, $this->rows('billium_ledger_discrepancy'));
        self::assertSame(['compared' => 1, 'recorded' => 0, 'resolved' => 0], $this->reconciler()->reconcile(0));
        $payment = $this->paymentRow();
        self::assertSame([300, false, 1000], [self::integer($payment['refunded_minor']), $payment['financial_hold'], self::integer($payment['allocated_minor'])]);
    }

    public function test_a_held_exceptional_discrepancy_stays_visible_beside_a_consistent_refund_basis(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, 300, sequence: 1));
        $this->observe(ObservationFactory::refund('op-r1', 'op-1', 1, 300, sequence: 2));
        $this->observe(ObservationFactory::charge('op-1', 2, 1000, 1500, sequence: 3));

        self::assertSame(0, $this->reconciler()->reconcile(0)['recorded'], 'the ordinary basis 300 agrees with the detail');
        self::assertSame(1, $this->rows('billium_ledger_discrepancy', "reason = 'over_refund' AND hold"));
        self::assertSame(0, $this->rows('billium_discrepancy_event', "kind = 'resolved'"), 'basis agreement never resolves a held exception');
        self::assertTrue($this->paymentRow()['financial_hold']);
    }
}
