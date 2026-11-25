<?php

declare(strict_types=1);

namespace Billium\Ledger\Tests\Feature;

use Billium\Core\Tests\Support\ObservationFactory;
use Billium\Ledger\Internal\Observation\ObservationHandler;
use Billium\Ledger\Internal\Observation\ObservationOwnership;
use Billium\Ledger\Tests\Observation\ObservationTestCase;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/** Owner bindings are found by their primary key, never by scanning every observed operation's binding. */
#[Group('pg')]
final class ObservationOwnerBindingLookupTest extends ObservationTestCase
{
    public function test_binding_key_matches_the_key_the_claim_routine_stores(): void
    {
        foreach ([['op-1', 'op-1'], ['refund-ü', 'op-1'], [str_repeat('r', 200), str_repeat('r', 200)]] as [$operation, $root]) {
            self::assertSame('accepted', $this->value("SELECT public.billium_claim_observation_owner(?,?,?,?,?,?,?,?, '{}'::jsonb)",
                [self::INSTALLATION, 'seller-a', 'test', 'remote-billium', 'pa1', $operation, $root, 'owner-a']));
        }

        self::assertSame(3, $this->rows('billium_observation_owner_binding'));
        self::assertSame(3, $this->rows('billium_observation_owner_binding b', 'b.operation_key = '.ObservationOwnership::bindingKey('b')));
    }

    public function test_legacy_revision_probe_reads_the_binding_by_primary_key(): void
    {
        $envelope = ObservationFactory::charge('op-1', 1, 1000);
        // Without a settlement intent the charge parks, but its observed payment is recorded without an owner binding.
        $this->tryObserve($envelope);
        self::assertSame(1, $this->rows('billium_observed_payment'));
        $observation = ObservationHandler::decode($envelope) ?? self::fail('fixture is not a valid observation');
        $connection = $this->owner();
        $probe = null;
        $connection->listen(static function (QueryExecuted $query) use (&$probe): void {
            if ($probe === null && str_contains($query->sql, 'billium_observation_owner_binding')) {
                $probe = $query;
            }
        });

        self::assertTrue(ObservationOwnership::hasLegacyRevision($connection, $observation));

        self::assertInstanceOf(QueryExecuted::class, $probe);
        $connection->beginTransaction();
        try {
            // The fixture tables are tiny; forbidding the sequential scan shows whether a key lookup exists at all.
            $connection->statement('SET LOCAL enable_seqscan = off');
            $explain = $connection->selectOne('EXPLAIN (FORMAT JSON) '.$probe->sql, $probe->bindings);
        } finally {
            $connection->rollBack();
        }
        self::assertInstanceOf(stdClass::class, $explain);
        $plan = self::text($explain->{'QUERY PLAN'});
        self::assertStringContainsString('"Index Name": "billium_observation_owner_binding_pkey"', $plan);
        self::assertStringNotContainsString('"Node Type": "Seq Scan"', $plan);
    }
}
