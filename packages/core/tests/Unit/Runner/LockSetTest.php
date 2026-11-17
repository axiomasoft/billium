<?php

declare(strict_types=1);

namespace Billium\Core\Tests\Unit\Runner;

use Billium\Core\Data\Runner\LockRequest;
use Billium\Core\Data\Runtime\Caller;
use Billium\Core\Enums\Runner\LockRank;
use Billium\Core\Enums\Runner\LockScope;
use Billium\Core\Exceptions\Runner\LockOrderViolation;
use Billium\Core\Internal\Runner\LockSet;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('D27')]
final class LockSetTest extends TestCase
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $statements = [];

    public function test_rank_values_follow_the_single_global_order(): void
    {
        $values = array_map(static fn (LockRank $rank): int => $rank->value, LockRank::cases());
        self::assertSame([10, 15, 20, 25, 30, 35, 40, 44, 45, 50, 60, 65, 70, 80, 90, 100, 110, 115, 120, 130], $values);
        $sorted = $values;
        sort($sorted);
        self::assertSame($sorted, $values, 'cases are declared in lock order');
    }

    public function test_ascending_ranks_lock_in_order_with_scope_filters(): void
    {
        $locks = $this->locks();
        // Passed together out of order: the set sorts by rank and keys before touching a row.
        $locked = $locks->acquire(
            new LockRequest(LockRank::Obligation, 'billium_obligation', [9, 3, 3]),
            new LockRequest(LockRank::FinancialSession, 'billium_financial_session', [1], shared: true, scope: LockScope::Installation),
            new LockRequest(LockRank::ModuleAdmission, 'billium_module_state', ['platform', 'ledger'], shared: true, scope: LockScope::Installation, keyColumn: 'module_id'),
        );
        self::assertSame([1, 'ledger', 'platform', 3, 9], $locked);
        self::assertCount(3, $this->statements);
        [$session, $modules, $obligations] = $this->statements;
        self::assertStringContainsString('"billium_financial_session"', $session[0]);
        self::assertStringEndsWith('FOR SHARE', $session[0]);
        self::assertSame(['{1}', 'inst-1'], $session[1]);
        self::assertStringContainsString('ORDER BY "module_id" COLLATE "C" FOR SHARE', $modules[0]);
        self::assertSame(['{"ledger","platform"}', 'inst-1'], $modules[1]);
        self::assertStringContainsString('seller_ref = ? AND environment = ?', $obligations[0]);
        self::assertStringEndsWith('ORDER BY "id" FOR UPDATE', $obligations[0]);
        self::assertSame(['{3,9}', 'seller-1', 'live'], $obligations[1]);
    }

    public function test_a_lower_rank_after_a_higher_one_is_a_violation(): void
    {
        $locks = $this->locks();
        $locks->acquire(new LockRequest(LockRank::Invoice, 'billium_invoice', [1]));
        $this->expectException(LockOrderViolation::class);
        $locks->acquire(new LockRequest(LockRank::Obligation, 'billium_obligation', [1]));
    }

    public function test_new_ids_inside_a_rank_only_ascend(): void
    {
        $locks = $this->locks();
        $locks->acquire(new LockRequest(LockRank::Obligation, 'billium_obligation', [5]));
        self::assertSame([7], $locks->acquire(new LockRequest(LockRank::Obligation, 'billium_obligation', [7])));
        $this->expectException(LockOrderViolation::class);
        $this->expectExceptionMessage('must ascend');
        $locks->acquire(new LockRequest(LockRank::Obligation, 'billium_obligation', [6]));
    }

    public function test_a_repeated_id_is_reentrant_even_below_the_current_rank(): void
    {
        $locks = $this->locks();
        $locks->acquire(new LockRequest(LockRank::Obligation, 'billium_obligation', [5]));
        $locks->acquire(new LockRequest(LockRank::Invoice, 'billium_invoice', [2]));
        $before = count($this->statements);
        self::assertSame([5], $locks->acquire(new LockRequest(LockRank::Obligation, 'billium_obligation', [5])));
        self::assertSame([2], $locks->acquire(new LockRequest(LockRank::Invoice, 'billium_invoice', [2], shared: true)));
        self::assertCount($before, $this->statements, 'a held row is not locked twice');
    }

    public function test_upgrading_a_held_shared_lock_is_forbidden(): void
    {
        $locks = $this->locks();
        $locks->acquire(new LockRequest(LockRank::FinancialSession, 'billium_financial_session', [1], shared: true, scope: LockScope::Installation));
        $this->expectException(LockOrderViolation::class);
        $this->expectExceptionMessage('Upgrade');
        $locks->acquire(new LockRequest(LockRank::FinancialSession, 'billium_financial_session', [1], scope: LockScope::Installation));
    }

    public function test_funds_lock_payments_before_customer_credits(): void
    {
        $locks = $this->locks();
        $locks->acquire(new LockRequest(LockRank::Funds, 'billium_payment', [8], segment: LockRank::FUNDS_PAYMENT));
        $locks->acquire(new LockRequest(LockRank::Funds, 'billium_customer_credit', [1], segment: LockRank::FUNDS_CUSTOMER_CREDIT));
        $this->expectException(LockOrderViolation::class);
        $locks->acquire(new LockRequest(LockRank::Funds, 'billium_payment', [9], segment: LockRank::FUNDS_PAYMENT));
    }

    public function test_funds_requested_together_are_sorted_payment_first(): void
    {
        $locks = $this->locks();
        $locks->acquire(
            new LockRequest(LockRank::Funds, 'billium_customer_credit', [1], segment: LockRank::FUNDS_CUSTOMER_CREDIT),
            new LockRequest(LockRank::Funds, 'billium_payment', [2, 1], segment: LockRank::FUNDS_PAYMENT),
        );
        self::assertStringContainsString('"billium_payment"', $this->statements[0][0]);
        self::assertSame('{1,2}', $this->statements[0][1][0]);
        self::assertStringContainsString('"billium_customer_credit"', $this->statements[1][0]);
    }

    public function test_one_table_and_mode_per_rank_segment(): void
    {
        $locks = $this->locks();
        $locks->acquire(new LockRequest(LockRank::Obligation, 'billium_obligation', [1]));
        $this->expectException(LockOrderViolation::class);
        $locks->acquire(new LockRequest(LockRank::Obligation, 'billium_other', [2]));
    }

    public function test_rows_outside_the_caller_scope_are_not_held(): void
    {
        $locks = $this->locks(found: [3]);
        self::assertSame([3], $locks->acquire(new LockRequest(LockRank::Obligation, 'billium_obligation', [2, 3])));
        $this->expectException(LockOrderViolation::class);
        // The foreign id 2 was never locked, so it is not reentrant: below the position it is an ordering violation.
        $locks->acquire(new LockRequest(LockRank::Obligation, 'billium_obligation', [2]));
    }

    public function test_requests_reject_claims_foreign_tables_and_mixed_keys(): void
    {
        foreach ([
            static fn (): LockRequest => new LockRequest(LockRank::Claim, 'billium_idempotency_receipt', [1]),
            static fn (): LockRequest => new LockRequest(LockRank::Obligation, 'users', [1]),
            static fn (): LockRequest => new LockRequest(LockRank::Obligation, 'billium_obligation', []),
            static fn (): LockRequest => new LockRequest(LockRank::Obligation, 'billium_obligation', [1, 'a']),
            static fn (): LockRequest => new LockRequest(LockRank::Obligation, 'billium_obligation', [1], keyColumn: 'id; drop'),
        ] as $build) {
            try {
                $build();
                self::fail('Expected an invalid lock request');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_usage_series_is_reentrant_but_cannot_be_added_after_schedule(): void
    {
        $locks = $this->locks();
        $locks->acquire(new LockRequest(
            rank: LockRank::UsageSeries, table: 'billium_usage_aggregate_series_head', keys: ['series-a'], keyColumn: 'series_key',
        ));
        $locks->acquire(new LockRequest(rank: LockRank::BillingSchedule, table: 'billium_billing_schedule', keys: [1]));
        self::assertSame(['series-a'], $locks->acquire(new LockRequest(
            rank: LockRank::UsageSeries, table: 'billium_usage_aggregate_series_head', keys: ['series-a'], keyColumn: 'series_key',
        )));
        $this->expectException(LockOrderViolation::class);
        $locks->acquire(new LockRequest(
            rank: LockRank::UsageSeries, table: 'billium_usage_aggregate_series_head', keys: ['series-b'], keyColumn: 'series_key',
        ));
    }

    public function test_rating_frontier_must_be_planned_before_occurrence_and_is_reentrant(): void
    {
        $locks = $this->locks();
        $frontier = new LockRequest(rank: LockRank::RatingFrontier, table: 'billium_rating_source_frontier', keys: ['occurrence:a'], keyColumn: 'frontier_key');
        $locks->acquire($frontier);
        $locks->acquire(new LockRequest(rank: LockRank::BillingOccurrence, table: 'billium_schedule_billing_occurrence', keys: ['a'], keyColumn: 'occurrence_ref'));
        self::assertSame(['occurrence:a'], $locks->acquire($frontier));
        $this->expectException(LockOrderViolation::class);
        $locks->acquire(new LockRequest(rank: LockRank::RatingFrontier, table: 'billium_rating_source_frontier', keys: ['occurrence:b'], keyColumn: 'frontier_key'));
    }

    /** @param list<int|string>|null $found */
    private function locks(?array $found = null): LockSet
    {
        $this->statements = [];

        return new LockSet(function (string $sql, array $bindings) use ($found): array {
            $this->statements[] = [$sql, $bindings];
            $keys = trim((string) $bindings[0], '{}');
            $keys = array_map(static fn (string $key): int|string => is_numeric($key) ? (int) $key : trim($key, '"'), explode(',', $keys));

            return $found === null ? $keys : array_values(array_intersect($keys, $found));
        }, new Caller('seller-1', 'live', 'inst-1', 'actor-1'));
    }
}
