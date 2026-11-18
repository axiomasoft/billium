<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Tests\Outbox\OutboxTestCase;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/** A prune batch proves open deliveries from the open ones alone, not from the whole delivered history. */
#[Group('pg')]
final class OutboxPrunePlanTest extends OutboxTestCase
{
    public function test_prune_proves_open_deliveries_from_the_open_ones_and_waits_for_the_oldest(): void
    {
        $this->publisher();
        $owner = $this->owner();
        $scope = new FeedScope(self::INSTALLATION, 'seller-a', 'test', '1')->key();
        $owner->insert(<<<'SQL'
            INSERT INTO billium_outbox_event
                (event_id,seller_ref,environment,type,schema_version,aggregate_ref,aggregate_revision,occurred_at,digest,payload,
                 contract_revision,owner_module_id,source_scope)
            SELECT 'prune-'||lpad(i::text,5,'0'),'seller-a','test','obligation.created.v1','billium.event.v1','obligation-'||i,1,now(),
                   'sha256:'||repeat('a',64),'{}'::jsonb,'events-2026-10-v1','ledger',?
            FROM generate_series(1,20000) i
            SQL, [$scope]);
        $owner->insert(<<<'SQL'
            INSERT INTO billium_outbox_delivery(event_id,destination_ref,status,available_at,delivered_at)
            SELECT event_id,'default',CASE WHEN event_id <= 'prune-00010' THEN 'pending' ELSE 'delivered' END,now(),
                   CASE WHEN event_id <= 'prune-00010' THEN NULL ELSE now() END
            FROM billium_outbox_event
            SQL);
        $owner->statement('ANALYZE billium_outbox_event, billium_outbox_delivery');
        $owner->enableQueryLog();

        $blocked = $this->pruner()->prune();

        $log = $owner->getQueryLog();
        $owner->disableQueryLog();
        self::assertSame(0, $blocked, 'the oldest events still wait for delivery');
        $batch = array_find($log, static fn (array $entry): bool => str_contains(self::text($entry['query']), 'WITH safe AS MATERIALIZED'));
        self::assertNotNull($batch);
        $owner->beginTransaction();
        try {
            $explain = $owner->selectOne('EXPLAIN (ANALYZE, FORMAT JSON) '.self::text($batch['query']), $batch['bindings']);
        } finally {
            $owner->rollBack();
        }
        self::assertInstanceOf(stdClass::class, $explain);
        $plan = self::text($explain->{'QUERY PLAN'});
        self::assertStringContainsString('"Index Name": "billium_outbox_delivery_open_event"', $plan);
        self::assertSame(0, preg_match('/"Node Type": "Seq Scan",[^{}]*"Relation Name": "billium_outbox_delivery"/', $plan), 'delivered history must not be scanned: '.$plan);

        $owner->update("UPDATE billium_outbox_delivery SET status = 'delivered', delivered_at = now() WHERE status = 'pending'");
        self::assertSame(20000, $this->pruner()->prune());
        self::assertSame(0, $owner->table('billium_outbox_event')->count());
        self::assertSame(0, $owner->table('billium_outbox_delivery')->count());
    }
}
