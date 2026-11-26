<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Concurrency;

use Billium\Contracts\Enums\ProblemCode;
use Billium\Core\Tests\Support\Concurrency\ConcurrencyStand;
use Billium\Core\Tests\TestEnvironment;
use Billium\Ledger\Tests\Commands\AllocationFixtures;
use Billium\Ledger\Tests\Schema\LedgerTestCase;
use PDOException;
use PHPUnit\Framework\Attributes\Group;

/**
 * ACC-CON-19 (INV-14): seller A's payment funds A's obligation; the same payment aimed at seller B's obligation is
 * billium.reference.unknown (B's rows are invisible to A) and leaves no row. A raw INSERT that bypasses the guard
 * still fails on the scoped foreign key (23503).
 */
#[Group('ACC-CON-19')]
#[Group('pg')]
final class CrossSellerAllocationTest extends LedgerTestCase
{
    use AllocationFixtures;

    public function test_cross_seller_allocation_is_unknown_and_the_scoped_fk_backstops_raw_sql(): void
    {
        $this->obligation('obligation-a', 1000);
        $this->callers->as('seller-b');
        $foreign = $this->obligation('obligation-b', 1000);
        $this->callers->as('seller-a');
        $payment = $this->payWith(captured: 1000);
        $results = ConcurrencyStand::run(AllocationCommandScenario::class, 2, ['plan' => [
            ['action' => 'allocate', 'obligation' => 'obligation-a', 'payment' => 'payment-a', 'amount' => '5.00'],
            ['action' => 'allocate', 'obligation' => 'obligation-b', 'payment' => 'payment-a', 'amount' => '5.00'],
        ]]);
        self::assertTrue(self::ok($results[0])['accepted']);
        self::assertSame(ProblemCode::ReferenceUnknown->value, $results[1]['error']['code'] ?? null, json_encode($results[1], JSON_THROW_ON_ERROR));
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertSame(0, $this->rows('billium_allocation', 'obligation_id=?', [$foreign]));
        self::assertSame(0, $this->rows('billium_idempotency_receipt', "command_kind='ledger.allocate' AND command_key='k-race-1'"));
        self::assertSame(0, self::integer($this->value("SELECT allocated_minor FROM billium_obligation WHERE obligation_ref='obligation-b'")));

        // Bypass the guard trigger as the schema owner: the composite (seller, environment, …, id) FK still refuses.
        $owner = TestEnvironment::pdo(owner: true);
        $owner->beginTransaction();
        try {
            $owner->exec('ALTER TABLE billium_allocation DISABLE TRIGGER billium_allocation_guard');
            $statement = $owner->prepare(<<<'SQL'
                INSERT INTO billium_allocation(installation_ref,seller_ref,environment,billing_account_ref,currency,obligation_id,payment_id,amount_minor,cause,policy_version,receipt_ref)
                VALUES('inst-test','seller-a','test','account-a','RUB',?,?,100,'raw','explicit/1','allocation:race-0')
                SQL);
            $statement->execute([$foreign, $payment]);
            self::fail('cross-seller raw insert');
        } catch (PDOException $e) {
            self::assertSame('23503', $e->errorInfo[0] ?? null, $e->getMessage());
        } finally {
            $owner->rollBack();
        }
        self::assertSame(1, $this->rows('billium_allocation'));
        self::assertSame(1, $this->rows('pg_trigger', "tgname='billium_allocation_guard' AND tgenabled='O'"));
    }
}
