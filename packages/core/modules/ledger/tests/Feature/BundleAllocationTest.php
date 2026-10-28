<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * SB-07 (D46, allocation-policy): a bundle of two components settled through observations and one settlement intent
 * with pro_rata_open/1. O1 66.67 and O2 33.33; a capture of 50.00 gives 3333.5/1666.5 → 3334/1666 (tie to the lower
 * index); a second payment of 50.00 pays the open 3333/1667 and settles both. Every row carries its lineage.
 */
#[Group('SB-07')]
final class BundleAllocationTest extends ObservationTestCase
{
    public function test_bundle_components_are_settled_pro_rata_through_observations(): void
    {
        $this->debt('o1', 6667);
        $this->debt('o2', 3333);
        $this->intent('intent-a', ['o1', 'o2']);

        $first = $this->observe(ObservationFactory::charge('op-1', 1, 5000, sequence: 1));

        self::assertSame([['amount_minor' => '3334', 'obligation_ref' => 'o1'], ['amount_minor' => '1666', 'obligation_ref' => 'o2']], $first->body['allocations']);
        self::assertSame(3334, $this->allocated('o1'));
        self::assertSame(1666, $this->allocated('o2'));

        $second = $this->observe(ObservationFactory::charge('op-2', 1, 5000, sequence: 2));

        self::assertSame([['amount_minor' => '3333', 'obligation_ref' => 'o1'], ['amount_minor' => '1667', 'obligation_ref' => 'o2']], $second->body['allocations']);
        self::assertSame(6667, $this->allocated('o1'));
        self::assertSame(3333, $this->allocated('o2'));
        self::assertSame(['settled', 'settled'], array_map(static fn (array $row): mixed => $row['status'], $this->table('SELECT status FROM billium_obligation ORDER BY obligation_ref')));
        self::assertSame('0', $second->body['unallocated_minor']);
        foreach ($this->table('SELECT a.policy_version, a.receipt_ref, a.cause, a.settlement_intent_id, o.component_ref FROM billium_allocation a JOIN billium_obligation o ON o.id = a.obligation_id') as $row) {
            self::assertSame('pro_rata_open/1', $row['policy_version']);
            self::assertContains($row['receipt_ref'], ['observation:op-1#1', 'observation:op-2#1']);
            self::assertSame('observation', $row['cause']);
            self::assertNotNull($row['settlement_intent_id']);
            self::assertNotSame('', $row['component_ref']);
        }
        self::assertSame(4, $this->rows('billium_outbox_event', "type = 'allocation.applied.v1'"));
    }

    public function test_an_overpayment_stays_unallocated_on_the_payment(): void
    {
        $this->debt('o1', 3000);
        $this->intent('intent-a', ['o1']);

        $receipt = $this->observe(ObservationFactory::charge('op-1', 1, 5000));

        self::assertSame(3000, $this->allocated('o1'));
        self::assertSame('2000', $receipt->body['unallocated_minor']);
        self::assertSame('2000', $receipt->body['spendable_minor']);
        self::assertSame(3000, self::integer($this->paymentRow()['allocated_minor']));
    }
}
