<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Concurrency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Ledger\Tests\Commands\AllocationFixtures;
use Billium\Ledger\Tests\Commands\InvoiceFixtures;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * ACC-CON-09 (INV-09, COV-167, COV-286): two allocations of 60.00 and two open_only credit notes of 60.00 race on one
 * issued invoice of 100.00. Exactly one of the four fits; open never goes below zero and nothing overcorrects.
 */
#[Group('ACC-CON-09')]
final class AllocationCreditNoteTest extends LedgerTestCase
{
    use AllocationFixtures;
    use InvoiceFixtures;

    public function test_allocations_and_credit_notes_never_overcorrect_one_invoice(): void
    {
        $this->obligation('obligation-a', 10000);
        $this->draft();
        $this->issue();
        $this->payWith(captured: 20000);
        $plan = [
            ['action' => 'allocate', 'obligation' => 'obligation-a', 'payment' => 'payment-a', 'amount' => '60.00'],
            ['action' => 'credit_note', 'obligation' => 'obligation-a', 'invoice' => 'invoice-a', 'amount' => '60.00'],
            ['action' => 'allocate', 'obligation' => 'obligation-a', 'payment' => 'payment-a', 'amount' => '60.00'],
            ['action' => 'credit_note', 'obligation' => 'obligation-a', 'invoice' => 'invoice-a', 'amount' => '60.00'],
        ];
        $results = ConcurrencyStand::run(AllocationCommandScenario::class, 4, ['plan' => $plan], 60);
        $accepted = 0;
        foreach ($results as $result) {
            $ok = self::ok($result);
            if ($ok['accepted'] === true) {
                $accepted++;

                continue;
            }
            self::assertSame(ProblemCode::AllocationExceedsOpen->value, $ok['problem'], json_encode($result, JSON_THROW_ON_ERROR));
        }
        self::assertSame(1, $accepted);
        $state = $this->obligationState('obligation-a');
        self::assertGreaterThanOrEqual(0, $state['open']);
        self::assertSame(6000, $state['allocated'] + $state['credited']);
        $invoice = $this->invoiceRow();
        self::assertLessThanOrEqual(10000, self::integer($invoice['allocated_minor']) + self::integer($invoice['credited_minor']));
        self::assertSame(4000, self::integer($invoice['open_minor']));
        self::assertSame('partially_paid', $invoice['status']);
        self::assertSame($state['allocated'], self::integer($this->value('SELECT coalesce(sum(amount_minor),0) FROM billium_allocation')));
        self::assertSame($state['credited'], self::integer($this->value('SELECT coalesce(sum(amount_minor),0) FROM billium_credit_note')));
    }
}
