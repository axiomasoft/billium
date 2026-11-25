<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/** Refund reconciliation reads one charge's refunds and finds their receipts by digest, never the seller's whole history. */
#[Group('pg')]
final class RefundReconcileLookupPlanTest extends ObservationTestCase
{
    public function test_refund_basis_reads_only_this_charge_refunds_and_finds_receipts_by_digest(): void
    {
        $this->debt('o1', 1000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 1000, 300, sequence: 1));
        $this->observe(ObservationFactory::refund('refund-1', 'op-1', 1, 300, sequence: 2));
        $lookup = null;
        $this->application()->make(Dispatcher::class)->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$lookup): void {
            if ($lookup === null && str_contains($query->sql, '"billium_allocation_receipt"') && str_contains($query->sql, 'DISTINCT ON')) {
                $lookup = $query;
            }
        });

        self::assertSame(1, $this->reconciler()->reconcile(0)['compared']);

        self::assertInstanceOf(QueryExecuted::class, $lookup);
        $owner = $this->owner();
        $owner->beginTransaction();
        try {
            // The fixture tables are tiny; forbidding the sequential scan shows whether a lookup index exists at all.
            $owner->statement('SET LOCAL enable_seqscan = off');
            $explain = $owner->selectOne('EXPLAIN (FORMAT JSON) '.$lookup->sql, $lookup->bindings);
        } finally {
            $owner->rollBack();
        }
        self::assertInstanceOf(stdClass::class, $explain);
        $plan = self::text($explain->{'QUERY PLAN'});
        self::assertStringContainsString('"Index Name": "billium_allocation_receipt_digest_idx"', $plan);
        self::assertStringContainsString('"Index Name": "billium_observed_payment_refund_original_idx"', $plan);
        self::assertStringNotContainsString('"Node Type": "Seq Scan"', $plan);
    }
}
