<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Contracts\Data\Money;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Data\DeclareSettlementIntent;
use Billium\Ledger\Data\RecordCustomerCredit;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use PDOException;

final class AllocationAccountIsolationTest extends LedgerTestCase
{
    public function test_cross_account_seller_and_currency_allocations_refuse_atomically(): void
    {
        $o = $this->obligation();
        $c = $this->credit(account: 'account-b');
        try {
            $this->allocate($o, null, $c, 1);
            self::fail('cross account');
        } catch (PDOException $e) {
            self::assertStringContainsString('billium.reference.unknown', $e->getMessage());
        }
        $this->ledger()->recordCustomerCredit(new RecordCustomerCredit('usd-credit', $this->account(), 'usd-credit', 'usd-source', Money::parse('10.00', 'USD')));
        $usd = self::integer($this->value("SELECT id FROM billium_customer_credit WHERE credit_ref='usd-credit'"));
        try {
            $this->allocate($o, null, $usd, 1, 'cross-currency');
            self::fail('cross currency');
        } catch (PDOException $e) {
            self::assertStringContainsString('billium.reference.unknown', $e->getMessage());
        }
        $this->callers->as('seller-b');
        $foreign = $this->credit('foreign-credit');
        $this->callers->as('seller-a');
        try {
            $this->allocate($o, null, $foreign, 1, 'cross-seller');
            self::fail('cross seller');
        } catch (PDOException $e) {
            self::assertStringContainsString('billium.reference.unknown', $e->getMessage());
        }
        $this->ledger()->declareSettlementIntent(new DeclareSettlementIntent('intent', $this->account(), 'intent', 'RUB', ['obligation-a']));
        $owner = TestEnvironment::pdo(owner: true);
        self::assertSame('23503', self::sqlState($owner, <<<'SQL'
            INSERT INTO billium_settlement_target(installation_ref,seller_ref,environment,billing_account_ref,currency,settlement_intent_id,obligation_id,position)
            VALUES('inst-test','seller-b','test','account-a','RUB',1,1,0)
            SQL));
        self::assertSame(0, $this->rows('billium_allocation'));
        self::assertSame(0, self::integer($this->value('SELECT allocated_minor FROM billium_obligation')));
        self::assertSame(1000, self::integer($this->value("SELECT remaining_minor FROM billium_customer_credit WHERE credit_ref='credit-a'")));
    }
}
