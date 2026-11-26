<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Concurrency;

use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Tests\Schema\AllocationRaceScenario;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;

#[Group('pg')]
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

    public function test_runtime_mutates_allocations_credit_notes_and_counters_only_through_guarded_routines(): void
    {
        $o = $this->obligation();
        $p = $this->payment();
        $this->allocate($o, $p, null, 100);
        $runtime = TestEnvironment::pdo();
        foreach ([
            "INSERT INTO billium_allocation(installation_ref,seller_ref,environment,billing_account_ref,currency,obligation_id,payment_id,amount_minor,cause,policy_version,receipt_ref) VALUES('inst-test','seller-a','test','account-a','RUB',{$o},{$p},1,'raw','x','allocation-a')",
            "INSERT INTO billium_credit_note(installation_ref,seller_ref,environment,billing_account_ref,currency,credit_note_ref,obligation_id,amount_minor,reason,mode,receipt_ref) VALUES('inst-test','seller-a','test','account-a','RUB','raw',{$o},1,'raw','open_only','allocation-a')",
            'UPDATE billium_obligation SET allocated_minor=0,revision=revision+1',
            'UPDATE billium_payment SET allocated_minor=0',
            'UPDATE billium_customer_credit SET remaining_minor=0',
            'DELETE FROM billium_allocation',
        ] as $sql) {
            self::assertSame('42501', self::sqlState($runtime, $sql), $sql);
        }
        // Private helpers are not runtime capabilities; the public surfaces are fixed-path SECURITY DEFINER routines.
        self::assertSame('42501', self::sqlState($runtime, "SELECT public.billium_ledger_release('inst-test','seller-a','test','account-a','RUB',{$o},NULL,1,'raw','allocation-a',true)"));
        self::assertSame('42501', self::sqlState($runtime, "SELECT public.billium_ledger_lock_scope('seller-a','test','{}','{}','{}','{}','{}')"));
        $rows = $this->table("SELECT p.proname,p.prosecdef,p.proconfig::text AS config,has_function_privilege(?,p.oid,'EXECUTE') AS runtime FROM pg_proc p WHERE p.pronamespace='public'::regnamespace AND p.proname IN ('billium_reverse_allocation','billium_issue_credit_note','billium_reallocate_payment') ORDER BY p.proname",
            [self::text(self::pdoValue($runtime, 'SELECT current_user'))]);
        self::assertSame(['billium_issue_credit_note', 'billium_reallocate_payment', 'billium_reverse_allocation'], array_column($rows, 'proname'));
        foreach ($rows as $row) {
            self::assertTrue($row['prosecdef']);
            self::assertTrue($row['runtime']);
            self::assertStringContainsString('search_path=pg_catalog, pg_temp', self::text($row['config']));
        }
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertSame(100, self::integer($this->value('SELECT allocated_minor FROM billium_payment')));
    }

    public function test_concurrent_reversals_of_one_original_leave_exactly_one_linked_negative_row(): void
    {
        $o = $this->obligation();
        $p = $this->payment();
        $this->allocate($o, $p, null, 600, 'allocation:original');
        $original = self::integer($this->value('SELECT id FROM billium_allocation'));
        $results = ConcurrencyStand::run(ReversalRaceScenario::class, 4, ['allocation' => $original]);
        self::assertCount(1, array_filter($results, static fn (array $r): bool => isset($r['ok'])));
        self::assertCount(3, array_filter($results, static fn (array $r): bool => ($r['error']['code'] ?? '') === 'billium.idempotency.conflict'));
        self::assertSame(1, $this->rows('billium_allocation', 'reverses_id=?', [$original]));
        self::assertSame(0, self::integer($this->value('SELECT allocated_minor FROM billium_obligation')));
        self::assertSame(0, self::integer($this->value('SELECT allocated_minor FROM billium_payment')));
        self::assertSame(0, self::integer($this->value('SELECT sum(amount_minor) FROM billium_allocation')));
    }
}
