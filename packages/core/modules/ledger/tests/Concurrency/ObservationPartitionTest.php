<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Concurrency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Tests\Observation\ObservationScenario;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;

/**
 * ACC-CON-24 (INV-06, REQ-ACC-10 worked example): an obligation of 100.00 on an issued invoice, charge rev1 captured
 * 30.00 already applied; six processes then deliver rev2 captured 100.00, its duplicate, another delivery of the rev1
 * snapshot, its duplicate, another delivery of rev2 and a duplicate of rev1 in one partition. Whatever the order:
 * exactly two allocations 30.00 + 70.00, invoice paid, totals assigned (never summed), nothing quarantined.
 */
#[Group('ACC-CON-24')]
#[Group('pg')]
final class ObservationPartitionTest extends ObservationTestCase
{
    public function test_six_workers_apply_one_partition_without_double_effect(): void
    {
        $this->debt('o1', 10000);
        $this->invoiceFor('o1', 10000);
        $this->intent('intent-a', ['o1']);
        $rev1 = ObservationFactory::charge('op-1', 1, 3000, sequence: 1);
        $this->observe($rev1);
        $rev2 = ObservationFactory::charge('op-1', 2, 10000, sequence: 2);
        $rev1Again = ObservationFactory::with($rev1, ['eventId' => (string) Str::ulid(), 'sourceSequence' => 3, 'evidenceRef' => 'ev-again']);
        $rev2Again = ObservationFactory::with($rev2, ['eventId' => (string) Str::ulid(), 'sourceSequence' => 4, 'evidenceRef' => 'ev-again']);
        $plan = [];
        foreach ([$rev2, $rev2, $rev1Again, $rev1Again, $rev2Again, $rev1] as $index => $envelope) {
            $plan[] = ['action' => 'observe', 'json' => ObservationFactory::json($envelope), 'delay_ms' => ($index * 7) % 20];
        }

        $results = ConcurrencyStand::run(ObservationScenario::class, 6, ['plan' => $plan], 90);

        foreach ($results as $result) {
            $ok = self::ok($result);
            self::assertContains($ok['problem'], [null, ProblemCode::ObservationParked->value], json_encode($result, JSON_THROW_ON_ERROR));
            if ($ok['receipt'] !== null) {
                self::assertContains($ok['receipt'], ['observation:embedded-commerz:op-1#1', 'observation:embedded-commerz:op-1#2']);
            }
        }
        self::assertSame([3000, 7000], array_map(static fn (array $row): int => self::integer($row['amount_minor']), $this->table('SELECT amount_minor FROM billium_allocation ORDER BY id')));
        self::assertSame(10000, $this->allocated('o1'));
        self::assertSame('paid', $this->value("SELECT status FROM billium_invoice WHERE invoice_ref = 'invoice-o1'"));
        $payment = $this->paymentRow();
        self::assertSame([10000, 10000, 2, false], [self::integer($payment['captured_minor']), self::integer($payment['allocated_minor']),
            self::integer($payment['applied_revision']), $payment['financial_hold']]);
        self::assertSame(0, $this->rows('billium_inbox_message', "status <> 'applied'"), 'every delivery applied in source order, none quarantined');
        self::assertSame(4, self::integer($this->partition()['accounted_sequence']));
        self::assertSame(2, $this->rows('billium_payment_revision'));
        self::assertSame(2, $this->rows('billium_allocation_receipt', "kind = 'payment_observation'"));
        self::assertSame(2, $this->rows('billium_outbox_event', "type = 'allocation.applied.v1'"));
    }

    private function invoiceFor(string $obligation, int $total): void
    {
        $id = self::integer($this->value(<<<'SQL'
            INSERT INTO billium_invoice(installation_ref,seller_ref,environment,billing_account_ref,currency,invoice_ref,customer_ref,status,subtotal_minor,total_minor,issued_at)
            VALUES('inst-test','seller-a','test','account-a','RUB',?,'customer-a','issued',?,?,now()) RETURNING id
            SQL, ['invoice-'.$obligation, $total, $total]));
        $this->owner()->update('UPDATE billium_obligation SET billed_invoice_id=?,revision=revision+1 WHERE obligation_ref=?', [$id, $obligation]);
    }
}
