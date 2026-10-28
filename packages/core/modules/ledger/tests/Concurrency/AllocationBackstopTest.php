<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Concurrency;

use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Tests\Schema\AllocationRaceScenario;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use PDOException;
use PHPUnit\Framework\Attributes\TestWith;

final class AllocationBackstopTest extends LedgerTestCase
{
    #[TestWith(['payment'])]
    #[TestWith(['credit'])]
    public function test_ten_runtime_processes_cannot_overspend_one_funds_source(string $source): void
    {
        $a = $this->obligation('a');
        $b = $this->obligation('b');
        $funds = $source === 'payment' ? $this->payment(100) : $this->credit(amount: 100);
        $results = ConcurrencyStand::run(AllocationRaceScenario::class, 10, ['obligations' => [$a, $b], 'funds' => $funds, 'source' => $source]);
        self::assertCount(2, array_filter($results, static fn (array $r): bool => isset($r['ok'])));
        self::assertCount(8, array_filter($results, static fn (array $r): bool => ($r['error']['code'] ?? '') === 'billium.allocation.exceeds_available'));
        self::assertSame(100, self::integer($this->value('SELECT sum(amount_minor) FROM billium_allocation')));
        self::assertSame(100, self::integer($this->value('SELECT sum(allocated_minor) FROM billium_obligation')));
        self::assertSame($source === 'payment' ? 100 : 0, self::integer($this->value($source === 'payment' ? 'SELECT allocated_minor FROM billium_payment' : 'SELECT remaining_minor FROM billium_customer_credit')));
    }

    public function test_raw_sql_guard_blocks_new_allocation_under_refund_flag_or_hold_and_requires_exact_full_reversal(): void
    {
        $o = $this->obligation();
        $p = $this->payment();
        $this->allocate($o, $p, null, 800);
        $this->owner()->update('UPDATE billium_payment SET refunded_minor=500,reallocation_required=true');
        try {
            $this->allocate($o, $p, null, 1, 'blocked');
            self::fail('flag blocks new spend');
        } catch (PDOException $e) {
            self::assertStringContainsString('billium.allocation.reallocation_required', $e->getMessage());
        }
        try {
            $this->allocate($o, $p, null, -799, 'partial', 1);
            self::fail('partial reversal');
        } catch (PDOException $e) {
            self::assertStringContainsString('billium.validation.invalid', $e->getMessage());
        }
        $this->allocate($o, $p, null, -800, 'reversal', 1);
        self::assertFalse($this->value('SELECT reallocation_required FROM billium_payment'));
        self::assertSame(0, self::integer($this->value('SELECT allocated_minor FROM billium_obligation')));
        try {
            $this->allocate($o, $p, null, -800, 'duplicate-reversal', 1);
            self::fail('one reversal');
        } catch (PDOException $e) {
            self::assertSame('23514', ($e->errorInfo[0] ?? null));
        }
        $this->owner()->update("UPDATE billium_payment SET financial_hold=true,projection_status='held'");
        try {
            $this->allocate($o, $p, null, 1, 'held');
            self::fail('hold blocks');
        } catch (PDOException $e) {
            self::assertStringContainsString('billium.allocation.reallocation_required', $e->getMessage());
        }
        self::assertSame(2, $this->rows('billium_allocation'));
        self::assertSame('55000', self::sqlState(TestEnvironment::pdo(owner: true), 'DELETE FROM billium_allocation'));
    }
}
