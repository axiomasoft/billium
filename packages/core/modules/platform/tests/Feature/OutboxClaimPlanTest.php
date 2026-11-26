<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Tests\Outbox\OutboxTestCase;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/** A relay poll costs the due rows it claims, not the size of the backed-off backlog. */
#[Group('pg')]
final class OutboxClaimPlanTest extends OutboxTestCase
{
    public function test_claim_skips_backoff_backlog_through_the_due_index_and_still_reclaims_expired_claims(): void
    {
        $this->publisher();
        $owner = $this->owner();
        $scope = new FeedScope(self::INSTALLATION, 'seller-a', 'test', '1')->key();
        $owner->insert(<<<'SQL'
            INSERT INTO billium_outbox_event
                (event_id,seller_ref,environment,type,schema_version,aggregate_ref,aggregate_revision,occurred_at,digest,payload,
                 contract_revision,owner_module_id,source_scope)
            SELECT 'backoff-'||i,'seller-a','test','obligation.created.v1','billium.event.v1','obligation-'||i,1,now(),
                   'sha256:'||repeat('a',64),'{}'::jsonb,'events-2026-10-v1','ledger',?
            FROM generate_series(1,5000) i
            SQL, [$scope]);
        $owner->insert(<<<'SQL'
            INSERT INTO billium_outbox_delivery(event_id,destination_ref,status,available_at)
            SELECT event_id,'default','pending',now() + interval '1 hour' FROM billium_outbox_event
            SQL);
        foreach ([
            ['expired', 'in_flight', '-3 seconds', '-1 second'], ['due', 'pending', '-2 seconds', null],
            ['live-claim', 'in_flight', '-4 seconds', '+1 hour'], ['parked', 'parked', '-5 seconds', null],
        ] as [$eventId, $status, $available, $claimUntil]) {
            $owner->insert(<<<'SQL'
                INSERT INTO billium_outbox_event
                    (event_id,seller_ref,environment,type,schema_version,aggregate_ref,aggregate_revision,occurred_at,digest,payload,
                     contract_revision,owner_module_id,source_scope)
                VALUES (?,'seller-a','test','obligation.created.v1','billium.event.v1','obligation-cold',1,now(),
                    'sha256:'||repeat('b',64),'{}'::jsonb,'events-2026-10-v1','ledger',?)
                SQL, [$eventId, $scope]);
            $owner->insert(<<<'SQL'
                INSERT INTO billium_outbox_delivery(event_id,destination_ref,status,available_at,claim_token,claim_until)
                VALUES (?,'default',?,now() + ?::interval,?,now() + ?::interval)
                SQL, [$eventId, $status, $available, $claimUntil === null ? null : 'claim-'.$eventId, $claimUntil]);
        }
        $owner->statement('ANALYZE billium_outbox_event, billium_outbox_delivery');
        $db = $this->application()->make(DatabaseManager::class)->connection();
        $db->enableQueryLog();

        $claims = $this->relay()->claim(10);

        $log = $db->getQueryLog();
        $db->disableQueryLog();
        self::assertSame(['expired', 'due'], array_map(static fn (array $claim): string => self::text($claim['message']['eventId']), $claims));
        self::assertSame(1, $claims[0]['failures']);
        self::assertSame(0, $claims[1]['failures']);
        $statement = array_find($log, static fn (array $entry): bool => str_contains(self::text($entry['query']), 'SKIP LOCKED'));
        self::assertNotNull($statement);
        $owner->beginTransaction();
        try {
            $explain = $owner->selectOne('EXPLAIN (ANALYZE, FORMAT JSON) '.self::text($statement['query']));
        } finally {
            $owner->rollBack();
        }
        self::assertInstanceOf(stdClass::class, $explain);
        $plan = self::text($explain->{'QUERY PLAN'});
        self::assertStringContainsString('"Index Name": "billium_outbox_delivery_due"', $plan);
        self::assertStringContainsString('"Index Cond": "(available_at <= statement_timestamp())"', $plan);
        self::assertStringNotContainsString('"Node Type": "Seq Scan"', $plan);
        self::assertSame(0, preg_match('/"Rows Removed by Filter": [0-9]{2,}/', $plan), 'the backed-off backlog must not be read: '.$plan);
    }
}
