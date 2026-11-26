<?php

declare(strict_types=1);

namespace Billium\Platform\Tests\Feature;

use Billium\Platform\Data\Feed\FeedScope;
use Billium\Platform\Internal\Outbox\EventFeedReader;
use Billium\Platform\Tests\Outbox\OutboxTestCase;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/** A feed page costs the events of its own scope, not the outbox history of every other seller. */
#[Group('pg')]
// Feed reads and prune stop at the cluster-wide snapshot xmin, which transactions in other databases hold back.
#[Group('pg-serial')]
final class FeedScopeReadPlanTest extends OutboxTestCase
{
    public function test_a_scope_read_walks_its_own_seller_in_cursor_order(): void
    {
        $selection = $this->selection();
        $this->events(3);
        $owner = $this->owner();
        foreach (['seller-b' => 4000, 'seller-c' => 4000] as $seller => $count) {
            $owner->insert(<<<'SQL'
                INSERT INTO billium_outbox_event
                    (event_id,seller_ref,environment,type,schema_version,aggregate_ref,aggregate_revision,occurred_at,digest,payload,
                     contract_revision,owner_module_id,source_scope)
                SELECT ?||'-'||i,?,'test','obligation.created.v1','billium.event.v1','obligation-'||i,1,now(),
                       'sha256:'||repeat('a',64),'{}'::jsonb,'events-2026-10-v1','ledger',?
                FROM generate_series(1,?) i
                SQL, [$seller, $seller, new FeedScope(self::INSTALLATION, $seller, 'test', '1')->key(), $count]);
        }
        $this->events(2);
        $owner->statement('ANALYZE billium_outbox_event');
        $db = $this->application()->make(DatabaseManager::class)->connection();
        $db->enableQueryLog();

        $first = $this->feed()->read($selection, null, 4);
        $second = $this->feed()->read($selection, $first->nextCursor->token, 4);

        $log = $db->getQueryLog();
        $db->disableQueryLog();
        self::assertCount(4, $first->events);
        self::assertTrue($first->hasMore);
        self::assertCount(1, $second->events);
        self::assertFalse($second->hasMore);
        $reader = $this->feed();
        self::assertInstanceOf(EventFeedReader::class, $reader);
        self::assertSame(0, $reader->diagnostics($selection, $second->nextCursor->token)['safePendingRows']);
        self::assertSame(5, $reader->diagnostics($selection)['safePendingRows'], 'other sellers are never pending for this scope');
        $page = array_find($log, static fn (array $entry): bool => str_contains(self::text($entry['query']), 'ORDER BY tx_id,id LIMIT'));
        self::assertNotNull($page);
        $explain = $owner->selectOne('EXPLAIN (ANALYZE, FORMAT JSON) '.self::text($page['query']), $page['bindings']);
        self::assertInstanceOf(stdClass::class, $explain);
        $plan = self::text($explain->{'QUERY PLAN'});
        self::assertMatchesRegularExpression('/"Index Name": "billium_outbox_event_(?:scope|seller)_cursor"/', $plan);
        self::assertStringNotContainsString('"Node Type": "Sort"', $plan, 'the page must come out of the index in cursor order: '.$plan);
        self::assertSame(0, preg_match('/"Rows Removed by (?:Filter|Index Recheck)": [0-9]{2,}/', $plan), 'other sellers must not be read: '.$plan);
    }
}
