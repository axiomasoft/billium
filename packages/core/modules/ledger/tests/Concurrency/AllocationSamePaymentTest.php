<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Concurrency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Ledger\Tests\Commands\AllocationFixtures;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * ACC-CON-08 (INV-09, COV-166, COV-284..286): ten processes allocate 50.00 each from one payment of 100.00 to two
 * obligations of 80.00 (even index → O1, odd → O2) under different keys. After the barrier the database totals hold.
 */
#[Group('ACC-CON-08')]
final class AllocationSamePaymentTest extends LedgerTestCase
{
    use AllocationFixtures;

    public function test_ten_workers_never_exceed_the_payment_or_an_obligation(): void
    {
        $this->obligation('o1', 8000);
        $this->obligation('o2', 8000);
        $this->payWith(captured: 10000);
        $plan = [];
        for ($index = 0; $index < 10; $index++) {
            $plan[] = ['action' => 'allocate', 'obligation' => $index % 2 === 0 ? 'o1' : 'o2', 'payment' => 'payment-a', 'amount' => '50.00'];
        }
        $results = ConcurrencyStand::run(AllocationCommandScenario::class, 10, ['plan' => $plan], 60);

        $accepted = 0;
        foreach ($results as $result) {
            $ok = self::ok($result);
            if ($ok['accepted'] === true) {
                $accepted++;

                continue;
            }
            self::assertContains($ok['problem'], [ProblemCode::AllocationExceedsOpen->value, ProblemCode::AllocationExceedsAvailable->value], json_encode($result, JSON_THROW_ON_ERROR));
        }
        self::assertSame(2, $accepted, 'two allocations of 50.00 exhaust 100.00');
        self::assertSame(10000, self::integer($this->value('SELECT sum(amount_minor) FROM billium_allocation')));
        self::assertSame(10000, self::integer($this->value('SELECT allocated_minor FROM billium_payment')));
        foreach (['o1', 'o2'] as $obligation) {
            $state = $this->obligationState($obligation);
            self::assertLessThanOrEqual(8000, $state['allocated']);
            self::assertSame($state['allocated'], self::integer($this->value('SELECT coalesce(sum(a.amount_minor),0) FROM billium_allocation a JOIN billium_obligation o ON o.id=a.obligation_id WHERE o.obligation_ref=?', [$obligation])), 'materialized counter = journal');
        }
        self::assertSame(2, $this->rows('billium_allocation_receipt', "kind='allocation'"));
        self::assertSame(2, $this->rows('billium_outbox_event', "type='allocation.applied.v1'"));
        // Every refused worker stored a deterministic receipt for its key (D31).
        self::assertSame(8, $this->rows('billium_idempotency_receipt', "command_kind='ledger.allocate' AND outcome='refused'"));
    }
}
