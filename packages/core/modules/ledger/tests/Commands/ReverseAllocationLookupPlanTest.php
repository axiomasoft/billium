<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Commands;

use Billium\Ledger\Tests\Schema\LedgerTestCase;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/** reverseAllocation finds the original allocation by its public reference through an index, never by scanning the seller. */
#[Group('pg')]
final class ReverseAllocationLookupPlanTest extends LedgerTestCase
{
    use AllocationFixtures;
    use InvoiceFixtures;

    public function test_the_original_allocation_is_read_through_the_receipt_ref_index(): void
    {
        $this->obligation('o1', 10000);
        $this->payWith();
        $this->allocateFrom('a1', 'o1', '100.00')->orThrow();
        $lookup = null;
        $this->application()->make(Dispatcher::class)->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$lookup): void {
            if ($lookup === null && str_contains($query->sql, '"a"."receipt_ref" = ?')) {
                $lookup = $query;
            }
        });

        $this->reverse('r1', 'a1')->orThrow();

        self::assertInstanceOf(QueryExecuted::class, $lookup);
        $owner = $this->owner();
        $owner->beginTransaction();
        try {
            // The fixture table is tiny; forbidding the sequential scan shows whether a lookup index exists at all, and
            // fresh statistics keep the planner from preferring an equally cheap seller-prefix walk of another index.
            $owner->statement('ANALYZE billium_allocation');
            $owner->statement('SET LOCAL enable_seqscan = off');
            $explain = $owner->selectOne('EXPLAIN (FORMAT JSON) '.$lookup->sql, $lookup->bindings);
        } finally {
            $owner->rollBack();
        }
        self::assertInstanceOf(stdClass::class, $explain);
        $plan = self::text($explain->{'QUERY PLAN'});
        self::assertStringContainsString('"Index Name": "billium_allocation_receipt_ref_idx"', $plan);
        self::assertStringNotContainsString('"Node Type": "Seq Scan"', $plan);
    }
}
