<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Tests\Queries\QueryTestCase;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/** A payment status read finds the refunds and discrepancies of one charge by key, never by scanning the seller's history. */
#[Group('pg')]
final class PaymentStatusLookupPlanTest extends QueryTestCase
{
    public function test_refunds_of_the_charge_are_read_through_the_original_ref_index(): void
    {
        $plan = $this->planOf('"original_ref"');

        self::assertStringContainsString('"Index Name": "billium_observed_payment_refund_original_idx"', $plan);
    }

    private function planOf(string $marker): string
    {
        $this->debt('o1', 10000);
        $this->intent('intent-a', ['o1']);
        $this->observe(ObservationFactory::charge('op-1', 1, 10000));
        $this->observe(ObservationFactory::refund('refund-1', 'op-1', 1, 3000, sequence: 2));
        $lookup = null;
        $this->application()->make(Dispatcher::class)->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$lookup, $marker): void {
            if ($lookup === null && str_contains($query->sql, $marker)) {
                $lookup = $query;
            }
        });

        $this->queries()->paymentStatus('op-1');

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

        return self::text($explain->{'QUERY PLAN'});
    }
}
