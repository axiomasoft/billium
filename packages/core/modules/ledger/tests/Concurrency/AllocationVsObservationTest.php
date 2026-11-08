<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Concurrency;

use Billium\Contracts\Data\Money;
use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Data\AdjustObligation;
use Billium\Ledger\Tests\Observation\ObservationScenario;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Explicit allocation racing an observation of the same payment (INV-09, D27 lock order, D35): both lock intent →
 * obligation → invoice → payment ascending, so neither deadlocks nor overspends. Whatever wins, the payment counter
 * equals its journal, no obligation is over-allocated, and allocated > captured − refunded only with
 * reallocation_required set; a refused allocation is a stored exceeds_available/exceeds_open/reallocation refusal.
 */
#[Group('INV-09')]
final class AllocationVsObservationTest extends ObservationTestCase
{
    public function test_explicit_allocation_and_a_refund_observation_keep_conservation(): void
    {
        $this->debt('o1', 1000);
        $this->debt('o2', 1000);
        $this->intent('intent-a', ['o1', 'o2']);
        $this->observe(ObservationFactory::charge('op-1', 1, 10000, sequence: 1));
        $this->ledger()->adjustObligation(new AdjustObligation('key-adj', $this->account(), 'adj-1', 'o1', Money::parse('30.00', 'RUB'), 'late-usage'))->orThrow();
        $c2 = ObservationFactory::json(ObservationFactory::charge('op-1', 2, 10000, 6000, sequence: 2));
        $plan = [
            ['action' => 'allocate', 'obligation' => 'o1', 'payment' => 'op-1', 'amount' => '30.00'],
            ['action' => 'observe', 'json' => $c2],
            ['action' => 'allocate', 'obligation' => 'o1', 'payment' => 'op-1', 'amount' => '10.00', 'delay_ms' => 5],
            ['action' => 'observe', 'json' => $c2, 'delay_ms' => 5],
        ];

        $results = ConcurrencyStand::run(ObservationScenario::class, 4, ['plan' => $plan], 90);

        foreach ($results as $result) {
            $ok = self::ok($result);
            $allowed = $ok['action'] === 'allocate'
                ? [null, ProblemCode::AllocationExceedsAvailable->value, ProblemCode::AllocationExceedsOpen->value, ProblemCode::AllocationReallocationRequired->value]
                : [null];
            self::assertContains($ok['problem'], $allowed, json_encode($result, JSON_THROW_ON_ERROR));
        }
        $payment = $this->paymentRow();
        $allocated = self::integer($payment['allocated_minor']);
        $net = self::integer($payment['captured_minor']) - self::integer($payment['refunded_minor']);
        self::assertSame(4000, $net);
        self::assertSame($allocated, $this->journalSum('op-1'), 'materialized counter = journal');
        self::assertSame($allocated > $net, $payment['reallocation_required'], 'over-allocation only with the derived flag');
        self::assertLessThanOrEqual(4000, $this->allocated('o1'));
        self::assertLessThanOrEqual(1000, $this->allocated('o2'));
        self::assertSame(2, self::integer($payment['applied_revision']));
        self::assertSame(0, $this->rows('billium_inbox_message', "status <> 'applied'"));
    }
}
