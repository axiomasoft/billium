<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Commands;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Internal\InvoiceStatus;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The trigger `billium_invoice_freeze` and the PHP table agree on every pair of states (negative proof for both). */
#[Group('pg')]
final class InvoiceFsmTriggerTest extends LedgerTestCase
{
    use InvoiceFixtures;

    public function test_trigger_allows_exactly_the_transitions_of_the_php_table(): void
    {
        $owner = TestEnvironment::pdo(owner: true);
        $n = 0;
        foreach (InvoiceStatus::cases() as $from) {
            foreach (InvoiceStatus::cases() as $to) {
                if ($from === $to) {
                    continue;
                }
                $ref = 'fsm-'.(++$n);
                $this->owner()->insert(<<<'SQL'
                    INSERT INTO billium_invoice(installation_ref,seller_ref,environment,billing_account_ref,currency,invoice_ref,customer_ref,status,subtotal_minor,total_minor,issued_at)
                    VALUES('inst-test','seller-a','test','account-a','RUB',?,'customer-a',?,1000,1000,CASE WHEN ? IN ('draft','discarded') THEN NULL ELSE now() END)
                    SQL, [$ref, $from->value, $from->value]);
                [$state, $message] = self::refusal($owner, "UPDATE billium_invoice SET status='{$to->value}', issued_at=COALESCE(issued_at, CASE WHEN '{$to->value}' IN ('draft','discarded') THEN NULL ELSE now() END) WHERE invoice_ref='{$ref}'");
                if ($from->canTransitionTo($to)) {
                    self::assertNull($state, "{$from->value} -> {$to->value}: ".$message);
                } else {
                    // 55000: the helper date of a discarded source is itself frozen, the status stays refused either way
                    self::assertContains($state, ['P0001', '23514', '55000'], "{$from->value} -> {$to->value}");
                    if ($state === 'P0001') {
                        self::assertStringContainsString(ProblemCode::InvoiceTransitionInvalid->value, $message, "{$from->value} -> {$to->value}");
                    }
                    self::assertSame($from->value, $this->invoiceStatus($ref), 'a refused transition changes nothing');
                }
            }
        }
        self::assertSame(count(InvoiceStatus::cases()) * (count(InvoiceStatus::cases()) - 1), $n);
    }

    public function test_void_is_refused_by_the_database_once_anything_is_allocated(): void
    {
        $this->owner()->insert(<<<'SQL'
            INSERT INTO billium_invoice(installation_ref,seller_ref,environment,billing_account_ref,currency,invoice_ref,customer_ref,status,subtotal_minor,total_minor,allocated_minor,issued_at)
            VALUES('inst-test','seller-a','test','account-a','RUB','settled','customer-a','issued',1000,1000,100,now())
            SQL);
        [$state, $message] = self::refusal(TestEnvironment::pdo(owner: true), "UPDATE billium_invoice SET status='void' WHERE invoice_ref='settled'");
        self::assertSame('P0001', $state);
        self::assertStringContainsString(ProblemCode::InvoiceTransitionInvalid->value, $message);
    }
}
